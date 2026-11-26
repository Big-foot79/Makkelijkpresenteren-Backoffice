<?php

namespace App\Suppliers\Files;

use Generator;
use RuntimeException;

/**
 * Reads a delimited supplier file line by line, without loading it into memory.
 *
 * - `encoding`: the file's character set; TD Synnex delivers ANSI (Windows-1252), the rest UTF-8.
 * - `quoted`: true = real CSV with "..." fields (Copaco). false = split on the delimiter and only
 *   strip quotes around a whole field, so an inch mark in 'Display 55"' never breaks a line.
 * - `header`: true = first (non-skipped) line has column names and rows come back keyed by name.
 * - `skipLines`: lines to skip before the data (or header) starts.
 */
class DelimitedFile
{
    public function __construct(
        private readonly string $path,
        private readonly string $delimiter = ';',
        private readonly string $encoding = 'UTF-8',
        private readonly bool $quoted = false,
        private readonly bool $header = false,
        private readonly int $skipLines = 0,
    ) {}

    /** @return Generator<int, array<int|string, string>> line number => row */
    public function rows(): Generator
    {
        $handle = @fopen($this->path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Bestand niet te openen: {$this->path}");
        }

        try {
            $lineNumber = 0;
            $columns = null;

            while (($line = fgets($handle)) !== false) {
                $lineNumber++;

                if ($lineNumber === 1) {
                    $line = preg_replace('/^\xEF\xBB\xBF/', '', $line); // UTF-8 BOM
                }

                if ($lineNumber <= $this->skipLines) {
                    continue;
                }

                $line = rtrim($line, "\r\n");

                if (trim($line) === '') {
                    continue;
                }

                if ($this->encoding !== 'UTF-8') {
                    $line = mb_convert_encoding($line, 'UTF-8', $this->encoding);
                }

                $fields = $this->split($line);

                if ($this->header && $columns === null) {
                    $columns = array_map('trim', $fields);

                    continue;
                }

                if ($columns !== null) {
                    $fields = array_combine($columns, array_pad(array_slice($fields, 0, count($columns)), count($columns), ''));
                }

                yield $lineNumber => $fields;
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return list<string> */
    private function split(string $line): array
    {
        if ($this->quoted) {
            return str_getcsv($line, $this->delimiter, '"', '');
        }

        return array_map(function (string $field) {
            $field = trim($field);

            if (strlen($field) >= 2 && $field[0] === '"' && $field[-1] === '"') {
                $field = str_replace('""', '"', substr($field, 1, -1));
            }

            return $field;
        }, explode($this->delimiter, $line));
    }
}
