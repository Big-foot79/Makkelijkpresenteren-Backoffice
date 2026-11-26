<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Link from an article to its page in TD Synnex InTouch. Other suppliers can get one via settings. */
return new class extends Migration
{
    public function up(): void
    {
        $supplier = DB::table('suppliers')->where('code', 'tdsynnex')->first();

        if ($supplier) {
            $settings = json_decode($supplier->settings ?? '{}', true) ?: [];
            $settings['product_url'] ??= 'https://intouch.tdsynnex.com/InTouch/MVC/Product/ProductDetails?productId={sku}';
            DB::table('suppliers')->where('id', $supplier->id)->update(['settings' => json_encode($settings)]);
        }
    }

    public function down(): void
    {
        //
    }
};
