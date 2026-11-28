<?php

namespace App\Support;

use DateTimeInterface;
use Generator;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/**
 * Reads Excel (.xlsx), OpenDocument (.ods) and CSV files row by row, as strings.
 * Old .xls files are not supported: save them as .xlsx first.
 */
class Spreadsheet
{
    public const EXTENSIONS = ['xlsx', 'ods', 'csv', 'txt'];

    /** @return list<string> sheet names */
    public static function sheets(string $path): array
    {
        $reader = self::open($path);
        $names = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            // A CSV has one unnamed sheet.
            $names[] = trim((string) $sheet->getName()) !== '' ? $sheet->getName() : 'Blad '.(count($names) + 1);
        }

        $reader->close();

        return $names;
    }

    /**
     * Rows of one sheet as lists of strings, with their 1-based row number as key.
     *
     * @return Generator<int, list<string>>
     */
    public static function rows(string $path, int $sheetIndex = 0): Generator
    {
        $reader = self::open($path);

        try {
            foreach ($reader->getSheetIterator() as $index => $sheet) {
                // OpenSpout numbers sheets from 1.
                if ($index - 1 !== $sheetIndex) {
                    continue;
                }

                $number = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $number++;
                    yield $number => array_map(self::cell(...), $row->toArray());
                }

                return;
            }
        } finally {
            $reader->close();
        }
    }

    /** @return list<string> the cells of one row (1-based) */
    public static function row(string $path, int $sheetIndex, int $rowNumber): array
    {
        foreach (self::rows($path, $sheetIndex) as $number => $cells) {
            if ($number === $rowNumber) {
                return $cells;
            }
        }

        return [];
    }

    private static function cell(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            // Long numbers such as EANs come back as floats; never write them as 6.93E+12.
            is_float($value) && floor($value) === $value && abs($value) < 1e15 => number_format($value, 0, '', ''),
            is_float($value) => rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.'),
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_bool($value) => $value ? '1' : '0',
            default => trim((string) $value),
        };
    }

    private static function open(string $path): ReaderInterface
    {
        if (! is_file($path)) {
            throw new RuntimeException("Bestand niet gevonden: {$path}");
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $reader = match ($extension) {
            'xlsx' => new XlsxReader,
            'ods' => new OdsReader,
            'csv', 'txt' => self::csvReader($path),
            'xls' => throw new RuntimeException('Oude .xls-bestanden worden niet ondersteund. Sla het bestand in Excel op als .xlsx.'),
            default => throw new RuntimeException("Onbekend bestandstype .{$extension}; gebruik .xlsx, .ods of .csv."),
        };

        $reader->open($path);

        return $reader;
    }

    /** Price lists from Dutch suppliers mostly use ";" and sometimes Windows-1252. */
    private static function csvReader(string $path): CsvReader
    {
        $sample = (string) file_get_contents($path, length: 65536);
        // Cut at the last line break, so a multibyte character is never split.
        $sample = substr($sample, 0, strrpos($sample, "\n") ?: strlen($sample));
        $firstLine = strtok($sample, "\n") ?: '';

        $options = new CsvOptions;
        $counts = [';' => substr_count($firstLine, ';'), ',' => substr_count($firstLine, ','), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);
        $options->FIELD_DELIMITER = (string) array_key_first($counts);

        if (! mb_check_encoding($sample, 'UTF-8')) {
            $options->ENCODING = 'Windows-1252';
        }

        return new CsvReader($options);
    }
}
