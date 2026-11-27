<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brand names as one name per brand.
 *  1. Company suffixes are dropped: "LG Electronics" -> "LG", "Samsung Electronics Benelux B.V." -> "Samsung".
 *  2. Then the fixed links under Leveranciers -> Merknamen apply ("Hewlett Packard" -> "HP").
 * Capitals do not matter anywhere in the backoffice (IIYAMA = Iiyama).
 */
class Brands
{
    private const SUFFIX = '/[\s,.\-]+(electronics?|inc|incorporated|corp|corporation|co|company|ltd|limited|gmbh|ag|bv|b\.v|nv|n\.v|sa|s\.a|spa|s\.p\.a|plc|llc|europe|benelux|nederland|netherlands|international|technologies|technology|group)\.?$/iu';

    private static ?array $aliases = null;

    public static function canonical(?string $raw): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $raw));

        if ($name === '') {
            return null;
        }

        $aliases = self::aliases();

        if (isset($aliases[mb_strtolower($name)])) {
            return $aliases[mb_strtolower($name)];
        }

        // Drop company suffixes one by one, but never down to nothing ("Electronics" alone stays).
        do {
            $previous = $name;
            $stripped = rtrim(trim(preg_replace(self::SUFFIX, '', $name)), ' ,.-');
            $name = mb_strlen($stripped) >= 2 ? $stripped : $name;
        } while ($name !== $previous);

        return $aliases[mb_strtolower($name)] ?? $name;
    }

    /**
     * Apply the current rules to everything already stored. Returns the number of rows changed.
     */
    public static function apply(): int
    {
        self::flush();
        $changed = 0;

        $raws = DB::table('supplier_items')->whereNotNull('brand_raw')->distinct()->pluck('brand_raw');

        foreach ($raws as $raw) {
            $brand = self::canonical($raw);
            $changed += DB::table('supplier_items')->where('brand_raw', $raw)
                ->where(fn ($q) => $q->whereNull('brand')->orWhere('brand', '!=', $brand))
                ->update(['brand' => $brand]);
        }

        if (Schema::hasTable('products')) {
            foreach (DB::table('products')->whereNotNull('brand')->distinct()->pluck('brand') as $raw) {
                $brand = self::canonical($raw);

                if ($brand !== $raw) {
                    $changed += DB::table('products')->where('brand', $raw)->update(['brand' => $brand]);
                }
            }
        }

        foreach (['brand_terms', 'favorite_brands'] as $table) {
            $column = $table === 'brand_terms' ? 'brand' : 'name';

            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (DB::table($table)->get(['id', $column]) as $row) {
                $brand = self::canonical($row->{$column});

                // Unique per brand: when two rows end up as one brand, keep the first and leave the other as it is.
                if ($brand !== $row->{$column} && ! DB::table($table)->whereRaw("lower({$column}) = ?", [mb_strtolower($brand)])->exists()) {
                    $changed += DB::table($table)->where('id', $row->id)->update([$column => $brand]);
                }
            }
        }

        return $changed;
    }

    public static function flush(): void
    {
        self::$aliases = null;
    }

    /** @return array<string, string> lower-case alias => brand */
    private static function aliases(): array
    {
        return self::$aliases ??= Schema::hasTable('brand_aliases')
            ? DB::table('brand_aliases')->pluck('brand', 'alias')->all()
            : [];
    }
}
