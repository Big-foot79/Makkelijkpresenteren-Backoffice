<?php

namespace Tests\Concerns;

use App\Models\Supplier;
use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * Points a seeded supplier at a temporary local folder that plays its (S)FTP server.
 * Files are given as name => contents, or name => [entry => contents] for a ZIP.
 * All data in the tests is fictional.
 */
trait SupplierFixtures
{
    protected function supplierWithFiles(string $code, array $files, array $settings = []): Supplier
    {
        $root = storage_path('framework/testing/ftp/'.$code.'-'.uniqid());
        File::ensureDirectoryExists($root);

        foreach ($files as $name => $contents) {
            $this->putRemoteFile($root, $name, $contents);
        }

        $supplier = Supplier::where('code', $code)->firstOrFail();
        $supplier->update([
            'protocol' => 'local',
            'root' => $root,
            'is_active' => true,
            'settings' => array_merge($supplier->settings ?? [], $settings),
        ]);

        return $supplier->fresh();
    }

    protected function putRemoteFile(string $root, string $name, string|array $contents, ?int $modified = null): void
    {
        $path = $root.'/'.$name;

        if (is_array($contents)) {
            $zip = new ZipArchive;
            $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            foreach ($contents as $entry => $data) {
                $zip->addFromString($entry, $data);
            }
            $zip->close();
        } else {
            file_put_contents($path, $contents);
        }

        if ($modified) {
            touch($path, $modified);
        }
    }

    /** Builds a semicolon line with values at the given 0-based positions. */
    protected function line(array $columns, int $width): string
    {
        $fields = array_fill(0, $width, '');
        foreach ($columns as $i => $value) {
            $fields[$i] = $value;
        }

        return implode(';', $fields);
    }

    protected function tearDownSupplierFixtures(): void
    {
        File::deleteDirectory(storage_path('framework/testing/ftp'));
        File::deleteDirectory(storage_path('app/private/imports'));
    }
}
