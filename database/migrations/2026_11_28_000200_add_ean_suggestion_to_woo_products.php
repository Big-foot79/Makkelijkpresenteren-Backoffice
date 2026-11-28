<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Shop products without EAN: an EAN found at a supplier via the SKU (= manufacturer part number). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('woo_products', function (Blueprint $table) {
            $table->string('suggested_ean', 14)->nullable()->after('ean_source');
            $table->string('suggested_from')->nullable()->after('suggested_ean');
        });
    }

    public function down(): void
    {
        Schema::table('woo_products', function (Blueprint $table) {
            $table->dropColumn(['suggested_ean', 'suggested_from']);
        });
    }
};
