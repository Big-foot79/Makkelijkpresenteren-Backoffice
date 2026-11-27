<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: the webshop. The backoffice owns products, prices and availability; WooCommerce follows.
 * woo_products is a mirror of what is in WooCommerce, used for the one-time link report, conflict
 * detection and the nightly check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_connections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url');
            $table->text('consumer_key')->nullable();     // encrypted
            $table->text('consumer_secret')->nullable();  // encrypted
            $table->text('webhook_secret')->nullable();   // encrypted
            $table->boolean('is_active')->default(false); // false = nothing is written to the shop
            $table->string('woo_version')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('ean', 14)->nullable()->unique();
            $table->string('sku', 100)->nullable()->index();
            $table->string('name');
            $table->string('brand', 100)->nullable()->index();
            $table->string('status', 20)->default('draft');          // publish, draft, private, pending
            $table->decimal('webshop_price', 12, 2)->nullable();     // regular price, excl. VAT
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->string('availability', 20)->nullable();          // ample, limited, backorder, unknown
            $table->date('availability_date')->nullable();
            $table->timestamp('availability_updated_at')->nullable();
            $table->unsignedBigInteger('woo_id')->nullable()->unique();
            $table->json('woo_synced_state')->nullable();             // the shop fields as we last sent them
            $table->timestamp('woo_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('woo_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('woo_id')->unique();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20)->nullable();                  // simple, variable, grouped, external
            $table->string('status', 20)->nullable();
            $table->string('sku', 100)->nullable()->index();
            $table->string('name')->nullable();
            $table->string('brand', 100)->nullable();
            $table->string('ean', 14)->nullable()->index();
            $table->string('ean_source', 20)->nullable();            // gtin, attribute, meta
            $table->decimal('regular_price', 12, 2)->nullable();
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->string('stock_status', 20)->nullable();
            $table->boolean('manage_stock')->default(false);
            $table->string('permalink')->nullable();
            $table->json('managed_meta')->nullable();                // our _mp_* meta values as Woo has them
            $table->string('match_status', 20)->nullable();          // ok, no_ean, duplicate_ean, variable
            $table->unsignedInteger('supplier_matches')->default(0); // supplier items with this EAN
            $table->timestamp('woo_modified_at')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });

        Schema::table('shop_connections', function (Blueprint $table) {
            $table->json('last_report')->nullable()->after('last_checked_at'); // counts of the last product fetch
        });

        Schema::create('woo_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('woo_id')->unique();
            $table->string('number')->nullable();
            $table->string('status', 30)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->decimal('total', 12, 2)->nullable();
            $table->string('company')->nullable();
            $table->json('lines')->nullable();        // sku, woo product id, name, quantity, total
            $table->json('attribution')->nullable();  // gclid, utm_*, source, landing page
            $table->string('gclid')->nullable()->index();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamps();
        });

        DB::table('shop_connections')->insert([
            'name' => 'Makkelijkpresenteren (staging)',
            'url' => 'https://makkelijkpresenteren.kinsta.cloud',
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('woo_orders');
        Schema::dropIfExists('woo_products');
        Schema::dropIfExists('products');
        Schema::dropIfExists('shop_connections');
    }
};
