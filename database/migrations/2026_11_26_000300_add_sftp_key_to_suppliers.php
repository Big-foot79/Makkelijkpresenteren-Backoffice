<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SFTP login with a private key and passphrase (ALSO), both stored encrypted. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->text('private_key')->nullable()->after('password');
            $table->text('passphrase')->nullable()->after('private_key');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['private_key', 'passphrase']);
        });
    }
};
