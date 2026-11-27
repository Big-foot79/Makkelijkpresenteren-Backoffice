<?php

use App\Support\Brands;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One name per brand. Suppliers write brands their own way (LG, LG Electronics, LG ELECTRONICS);
 * the backoffice keeps the supplier's spelling in brand_raw and uses one brand name everywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('alias', 100)->unique();   // lower case
            $table->string('brand', 100);
            $table->timestamps();
        });

        Schema::table('supplier_items', function (Blueprint $table) {
            $table->string('brand_raw', 100)->nullable()->after('brand');
        });

        DB::table('supplier_items')->update(['brand_raw' => DB::raw('brand')]);
        Brands::apply();
    }

    public function down(): void
    {
        Schema::table('supplier_items', function (Blueprint $table) {
            $table->dropColumn('brand_raw');
        });

        Schema::dropIfExists('brand_aliases');
    }
};
