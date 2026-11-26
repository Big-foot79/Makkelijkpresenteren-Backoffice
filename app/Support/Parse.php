<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Throwable;

/** Small, forgiving parsers for values in supplier files. */
class Parse
{
    public static function text(mixed $value, ?int $max = null): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        return $max ? mb_substr($value, 0, $max) : $value;
    }

    /**
     * EAN/GTIN as digits only, normalised to EAN-13 where possible
     * (UPC-12 gets a leading zero, GTIN-14 with a leading zero loses it).
     */
    public static function ean(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) ($value ?? ''));

        if ($digits === '' || ! in_array(strlen($digits), [8, 12, 13, 14], true) || ltrim($digits, '0') === '') {
            return null;
        }

        if (strlen($digits) === 12) {
            $digits = '0'.$digits;
        }

        if (strlen($digits) === 14 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return $digits;
    }

    /** "1.234,56", "1234,56", "1,234.56" and "1234.56" all become 1234.56. */
    public static function decimal(mixed $value): ?float
    {
        $s = trim((string) ($value ?? ''));

        if ($s === '') {
            return null;
        }

        $s = preg_replace('/[^\d,.\-]/', '', $s);
        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');

        if ($lastComma !== false && $lastDot !== false) {
            // The separator that comes last is the decimal separator.
            $s = $lastComma > $lastDot
                ? str_replace(['.', ','], ['', '.'], $s)
                : str_replace(',', '', $s);
        } elseif ($lastComma !== false) {
            $s = str_replace(',', '.', $s);
        }

        return is_numeric($s) ? (float) $s : null;
    }

    public static function int(mixed $value, ?int $default = null): ?int
    {
        $s = trim((string) ($value ?? ''));

        if ($s === '' || ! preg_match('/^-?\d+/', $s, $m)) {
            return $default;
        }

        return (int) $m[0];
    }

    /** Parses a date in the given format (e.g. 'd.m.Y', 'Ymd'); null when empty or invalid. */
    public static function date(mixed $value, string $format): ?CarbonImmutable
    {
        $s = trim((string) ($value ?? ''));

        if ($s === '' || preg_match('/^0+$/', $s)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!'.$format, $s, 'Europe/Amsterdam');
        } catch (Throwable) {
            return null;
        }

        return $date && $date->format($format) === $s ? $date : null;
    }
}
