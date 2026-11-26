<?php

namespace App\Suppliers\Files;

use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * The supplier's (S)FTP server: find the newest matching file, download it to local storage
 * (kept a few days for troubleshooting) and unpack ZIP files.
 */
class RemoteFiles
{
    private ?Filesystem $disk = null;

    private string $workDir;

    public function __construct(private readonly Supplier $supplier)
    {
        $this->workDir = 'imports/'.$supplier->code.'/'.now()->format('Ymd-His').'-'.substr(uniqid(), -5);
    }

    public function disk(): Filesystem
    {
        return $this->disk ??= Storage::build(match ($this->supplier->protocol) {
            'ftp' => [
                'driver' => 'ftp',
                'host' => $this->supplier->host,
                'port' => $this->supplier->port ?: 21,
                'username' => $this->supplier->username,
                'password' => $this->supplier->password,
                'root' => $this->supplier->root ?: '/',
                'passive' => true,
                'ssl' => false,
                'timeout' => 60,
            ],
            'sftp' => [
                'driver' => 'sftp',
                'host' => $this->supplier->host,
                'port' => $this->supplier->port ?: 22,
                'username' => $this->supplier->username,
                // With a key the password is only used as second step or fallback.
                'password' => $this->supplier->password ?: null,
                'privateKey' => $this->supplier->private_key ?: null,
                'passphrase' => $this->supplier->passphrase ?: null,
                'root' => $this->supplier->root ?: '/',
                'timeout' => 60,
            ],
            // Used by tests and for manual imports from a local folder.
            'local' => [
                'driver' => 'local',
                'root' => $this->supplier->root,
            ],
            default => throw new RuntimeException("Onbekend protocol: {$this->supplier->protocol}"),
        });
    }

    /**
     * The newest file in the root whose name matches the regex.
     *
     * @return array{path: string, modified: CarbonImmutable, size: int}
     */
    public function latest(string $pattern): array
    {
        $candidates = collect($this->disk()->files())
            ->filter(fn (string $path) => preg_match($pattern, basename($path)))
            ->map(fn (string $path) => [
                'path' => $path,
                'modified' => CarbonImmutable::createFromTimestamp($this->disk()->lastModified($path), 'Europe/Amsterdam'),
                'size' => $this->disk()->size($path),
            ])
            ->sortByDesc('modified');

        if ($candidates->isEmpty()) {
            throw new RuntimeException("Geen bestand gevonden dat past bij {$pattern} op {$this->supplier->name}.");
        }

        return $candidates->first();
    }

    /** @return array{path: string, modified: CarbonImmutable, size: int} */
    public function file(string $name): array
    {
        if (! $this->disk()->exists($name)) {
            throw new RuntimeException("Bestand {$name} staat niet op de server van {$this->supplier->name}.");
        }

        return [
            'path' => $name,
            'modified' => CarbonImmutable::createFromTimestamp($this->disk()->lastModified($name), 'Europe/Amsterdam'),
            'size' => $this->disk()->size($name),
        ];
    }

    /** Downloads a remote file and returns its absolute local path. */
    public function download(string $path): string
    {
        $stream = $this->disk()->readStream($path);

        if ($stream === null) {
            throw new RuntimeException("Downloaden mislukt: {$path}");
        }

        $local = $this->workDir.'/'.basename($path);
        Storage::disk('local')->writeStream($local, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return Storage::disk('local')->path($local);
    }

    /**
     * Extracts the ZIP entries whose name matches the regex.
     *
     * @return array<string, string> entry name => absolute local path
     */
    public function unzip(string $zipPath, string $pattern): array
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('ZIP-bestand is beschadigd of onleesbaar: '.basename($zipPath));
        }

        $target = dirname($zipPath).'/'.pathinfo($zipPath, PATHINFO_FILENAME);
        $found = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if ($name !== false && ! str_ends_with($name, '/') && preg_match($pattern, basename($name))) {
                $zip->extractTo($target, $name);
                $found[basename($name)] = $target.'/'.$name;
            }
        }

        $zip->close();

        return $found;
    }

    /** @return list<string> names of the files in a ZIP, for error messages */
    public function zipEntries(string $zipPath): array
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            return [];
        }

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        return array_values(array_filter($names));
    }

    /** Lists the root of the server; used by the "Verbinding testen" button. */
    public function listing(): array
    {
        return $this->disk()->files();
    }
}
