<?php

use App\Models\Supplier;
use App\Suppliers\EanResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Where an article's EAN came from: the supplier's file (null) or looked up by manufacturer part number ('mpn'). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_items', function (Blueprint $table) {
            $table->string('ean_source', 10)->nullable()->after('ean');
        });

        // Fill in what can be found for the articles already imported.
        foreach (Supplier::all() as $supplier) {
            app(EanResolver::class)->forSupplier($supplier);
        }
    }

    public function down(): void
    {
        Schema::table('supplier_items', function (Blueprint $table) {
            $table->dropColumn('ean_source');
        });
    }
};
