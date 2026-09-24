<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('action', 64)->index();
            $table->string('subject_type', 64);
            // String so records with string keys (settings) can be subjects too.
            $table->string('subject_id', 64);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 45)->nullable();
            // DATETIME, not TIMESTAMP: see the MariaDB note in CLAUDE.md.
            $table->dateTime('created_at')->index();

            $table->index(['subject_type', 'subject_id']);
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->dateTime('reminded_at')->nullable()->after('pickup_by');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dateTime('reviewed_at')->nullable()->after('note');
            $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn('reviewed_at');
        });
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('reminded_at');
        });
        Schema::dropIfExists('audit_logs');
    }
};
