<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 20)->default('reservation');
            $table->string('reference_code', 12)->unique();
            $table->string('status', 20)->index();
            $table->decimal('total', 10, 2);
            $table->decimal('downpayment_amount', 10, 2);
            $table->decimal('balance', 10, 2);
            $table->timestamp('expires_at')->nullable()->index();
            $table->dateTime('pickup_by')->nullable()->index();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamp('forfeited_at')->nullable();
            $table->string('paymongo_checkout_id', 100)->nullable()->index();
            $table->string('checkout_url', 500)->nullable();
            $table->string('walk_in_name', 150)->nullable();
            $table->string('walk_in_phone', 30)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('reservation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->restrictOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->foreignId('batch_id')->constrained('stock_batches')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->timestamps();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreign('reservation_id')->references('id')->on('reservations')->restrictOnDelete();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->string('type', 20);
            $table->decimal('amount', 10, 2);
            $table->string('method', 20);
            // PayMongo pay_... id. The unique index backs webhook idempotency.
            $table->string('provider_ref', 100)->nullable()->unique();
            $table->string('status', 20)->index();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['reservation_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['reservation_id']);
        });
        Schema::dropIfExists('reservation_items');
        Schema::dropIfExists('reservations');
    }
};
