<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity_remaining');
            $table->decimal('sale_price', 10, 2)->nullable();
            $table->timestamp('received_at');
            $table->timestamps();

            // Oldest-first allocation: WHERE item_id = ? ORDER BY received_at, id
            $table->index(['item_id', 'received_at', 'id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->foreignId('batch_id')->constrained('stock_batches')->restrictOnDelete();
            $table->string('type', 20);
            $table->integer('quantity');
            $table->unsignedBigInteger('reservation_id')->nullable()->index();
            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['item_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_batches');
    }
};
