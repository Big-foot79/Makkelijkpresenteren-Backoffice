<?php

namespace Tests\Unit;

use App\Support\Parse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ParseTest extends TestCase
{
    public static function decimals(): array
    {
        return [
            ['1.234,56', 1234.56],
            ['1234,56', 1234.56],
            ['1,234.56', 1234.56],
            ['1234.56', 1234.56],
            [' 899 ', 899.0],
            ['€ 12,50', 12.5],
            ['', null],
            ['n.v.t.', null],
        ];
    }

    #[DataProvider('decimals')]
    public function test_decimal(string $input, ?float $expected): void
    {
        $this->assertSame($expected, Parse::decimal($input));
    }

    public static function eans(): array
    {
        return [
            ['8712345678906', '8712345678906'],
            ['871234567890', '0871234567890'],    // UPC-12 -> EAN-13
            ['08712345678906', '8712345678906'],  // GTIN-14 with leading zero
            [' 8712-3456-78906 ', '8712345678906'],
            ['12345', null],
            ['0000000000000', null],
            ['', null],
        ];
    }

    #[DataProvider('eans')]
    public function test_ean(string $input, ?string $expected): void
    {
        $this->assertSame($expected, Parse::ean($input));
    }
}
