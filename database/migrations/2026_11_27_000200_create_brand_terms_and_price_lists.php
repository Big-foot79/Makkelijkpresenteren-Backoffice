<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What we really pay. Distributor list prices are only a starting point: most brands give a usual
 * discount on their advice price (iiyama, Sony via TD Synnex, ...) or send a bid price list by mail.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Per brand: the usual discount on the advice price, and through which distributor we buy.
        Schema::create('brand_terms', function (Blueprint $table) {
            $table->id();
            $table->string('brand', 100)->unique();
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('bid_source', 30)->nullable();   // mail, tdsynnex, portal
            $table->text('note')->nullable();
            $table->timestamps();
        });

        // A price list from a brand or distributor, uploaded as Excel/CSV with its own columns.
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('brand', 100)->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('file_path');
            $table->string('file_name')->nullable();
            $table->unsignedSmallInteger('sheet')->default(0);       // 0-based sheet index
            $table->unsignedInteger('header_row')->default(1);       // 1-based
            $table->json('columns')->nullable();                     // field => 0-based column index
            $table->string('status', 20)->default('uploaded');       // uploaded, imported, failed
            $table->unsignedInteger('rows_imported')->default(0);
            $table->unsignedInteger('rows_without_ean')->default(0);
            $table->timestamp('imported_at')->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_id')->constrained()->cascadeOnDelete();
            $table->string('ean', 14)->nullable()->index();
            $table->string('mpn', 100)->nullable()->index();
            $table->string('description')->nullable();
            $table->decimal('msrp', 12, 2)->nullable();            // advice / catalogue price
            $table->decimal('purchase_price', 12, 2)->nullable();  // bid or net price on the list
            $table->string('ean_source', 20)->nullable();          // list, mpn (looked up via supplier MPN)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
        Schema::dropIfExists('brand_terms');
    }
};
