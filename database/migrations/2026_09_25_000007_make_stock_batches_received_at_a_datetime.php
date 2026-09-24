<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MariaDB 10.4 (explicit_defaults_for_timestamp=OFF) gives the first NOT NULL TIMESTAMP column in a
 * table an implicit ON UPDATE CURRENT_TIMESTAMP. On stock_batches that silently reset received_at
 * every time a quantity changed, which broke oldest-first allocation. DATETIME never auto-updates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_batches', function (Blueprint $table) {
            $table->dateTime('received_at')->change();
        });
    }

    public function down(): void
    {
        Schema::table('stock_batches', function (Blueprint $table) {
            $table->timestamp('received_at')->change();
        });
    }
};
