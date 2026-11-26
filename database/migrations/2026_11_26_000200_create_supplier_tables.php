<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: supplier data. The full supplier catalogues (100k+ rows each) live here and never go
 * to WooCommerce or Odoo directly; products (phase 3) link to supplier items via EAN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();               // tdsynnex, also, travion, copaco
            $table->string('name');
            $table->boolean('is_active')->default(false);
            $table->string('protocol')->default('ftp');     // ftp, sftp, local
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable();           // encrypted
            $table->string('root')->nullable();
            $table->string('account_code')->nullable();
            $table->json('settings')->nullable();           // file names, schedules, special dates
            $table->foreignId('current_stock_run_id')->nullable()->constrained('job_runs')->nullOnDelete();
            $table->timestamp('stock_as_of')->nullable();   // when the current stock list was made by the supplier
            $table->timestamp('catalog_imported_at')->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 64);                      // supplier's own article number
            $table->string('ean', 14)->nullable()->index();
            $table->string('mpn', 64)->nullable()->index(); // manufacturer part number
            $table->string('brand', 100)->nullable()->index();
            $table->string('description', 500)->nullable();
            $table->text('long_description')->nullable();
            $table->string('category', 150)->nullable();
            $table->decimal('weight_kg', 10, 3)->nullable();
            $table->decimal('msrp', 12, 2)->nullable();      // list / advice price (TD Synnex only)
            $table->decimal('net_price', 12, 2)->nullable(); // our purchase price from the price list
            $table->decimal('surcharge', 12, 2)->nullable(); // e.g. disposal fee
            $table->string('currency', 3)->default('EUR');
            $table->unsignedSmallInteger('warranty_months')->nullable();
            $table->boolean('is_kit')->default(false);
            $table->string('status', 30)->nullable();       // eol, eol_with_follower, allocation, not_deliverable
            $table->string('follower_sku', 64)->nullable();
            $table->boolean('is_listed')->default(true);    // false = no longer in the supplier's catalogue
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('price_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['supplier_id', 'sku']);
        });

        // Stock per import run. A run only becomes "current" after it finished successfully
        // (suppliers.current_stock_run_id); the previous run is kept as a fallback.
        Schema::create('supplier_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_run_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 64);
            $table->string('location', 20)->nullable();     // TD Synnex: 1818 local, 1854 central
            $table->integer('quantity')->default(0);
            $table->date('next_date')->nullable();
            $table->integer('next_quantity')->nullable();
            $table->string('availability', 20)->nullable(); // supplier's own category code
            $table->string('note', 30)->nullable();         // allocation, overdue
            $table->timestamp('as_of')->nullable();

            $table->index(['job_run_id', 'sku']);
            $table->index(['supplier_id', 'sku']);
        });

        $now = now();
        DB::table('suppliers')->insert([
            [
                'code' => 'tdsynnex', 'name' => 'TD Synnex', 'protocol' => 'ftp', 'root' => '/',
                'settings' => json_encode([
                    'cron_catalog' => '15 9 * * *',
                    'cron_stock' => '20 9 * * *',
                    'special_date_allocation' => '17.11.2016',
                    'special_date_overdue' => '15.12.2016',
                ]),
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => 'also', 'name' => 'ALSO', 'protocol' => 'sftp', 'root' => '/',
                'settings' => json_encode([
                    'cron_catalog' => '15 6 * * *',
                    'cron_stock' => '30 7 * * *',
                    'catalog_file' => 'pricelist-2.txt.zip',
                    'stock_file' => 'stock.txt',
                ]),
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => 'travion', 'name' => 'Travion', 'protocol' => 'ftp', 'root' => '/pricefile',
                'settings' => json_encode([
                    'cron_full' => '15 5 * * *',
                ]),
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => 'copaco', 'name' => 'Copaco', 'protocol' => 'ftp', 'root' => '/114116/out',
                'settings' => json_encode([
                    'cron_full' => '30 5 * * *',
                    'file' => 'Copaco_prijslijst_114116.csv',
                    'skip_lines' => '15',
                ]),
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);

        DB::table('suppliers')->where('code', 'travion')->update(['account_code' => 'TD042012']);
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_stock');
        Schema::dropIfExists('supplier_items');
        Schema::dropIfExists('suppliers');
    }
};
