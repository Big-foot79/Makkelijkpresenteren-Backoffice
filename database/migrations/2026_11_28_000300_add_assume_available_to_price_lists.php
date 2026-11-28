<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Some distributors (Midwich) give no stock at all. Decision 28 Nov 2026: what they carry is shown
 * as available rather than on backorder, so no customer is turned away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_lists', function (Blueprint $table) {
            $table->boolean('assume_available')->default(false)->after('valid_until');
        });
    }

    public function down(): void
    {
        Schema::table('price_lists', function (Blueprint $table) {
            $table->dropColumn('assume_available');
        });
    }
};
