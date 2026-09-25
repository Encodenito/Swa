<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Set when SendRefund claims a refund, just before calling PayMongo. A second run of the
            // same job (queue:retry, a redelivered job) finds it set and does nothing.
            $table->dateTime('refund_attempted_at')->nullable()->after('reviewed_by');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('deactivated_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('refund_attempted_at');
        });
    }
};
