<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: CRM, message log, chatbot, orphan payments. Business dates are DATETIME (see CLAUDE.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('body');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('created_at')->index();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->string('slug', 60)->unique();
            $table->string('colour', 20)->default('neutral');
            $table->timestamps();
        });

        Schema::create('tag_user', function (Blueprint $table) {
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('created_at')->nullable();

            $table->primary(['tag_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('due_on')->index();
            $table->string('note', 500);
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('completed_at')->nullable()->index();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('message_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('channel', 20);
            $table->string('notification', 100);
            $table->foreignId('reservation_id')->nullable()->constrained()->restrictOnDelete();
            // Masked, e.g. c***@gmail.com or 0917*****67. Message bodies are never stored.
            $table->string('recipient', 100)->nullable();
            $table->string('subject', 255)->nullable();
            $table->dateTime('sent_at')->index();

            $table->index(['user_id', 'sent_at']);
        });

        Schema::create('chatbot_faqs', function (Blueprint $table) {
            $table->id();
            $table->string('question', 255);
            $table->text('answer');
            $table->text('keywords');
            $table->boolean('active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('chat_handoffs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('contact', 150);
            $table->text('message');
            $table->json('transcript')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('open')->index();
            $table->foreignId('claimed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->string('reply_note', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('orphan_payments', function (Blueprint $table) {
            $table->id();
            $table->string('provider_ref', 100)->unique();
            $table->string('reference_number', 100)->nullable();
            $table->string('checkout_id', 100)->nullable();
            $table->decimal('amount', 10, 2);
            $table->dateTime('received_at');
            // Refund of an orphan: same single-attempt pattern as payments (spec K.8).
            $table->string('refund_status', 20)->nullable()->index();
            $table->string('refund_ref', 100)->nullable()->unique();
            $table->dateTime('refund_attempted_at')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orphan_payments');
        Schema::dropIfExists('chat_handoffs');
        Schema::dropIfExists('chatbot_faqs');
        Schema::dropIfExists('message_logs');
        Schema::dropIfExists('follow_ups');
        Schema::dropIfExists('tag_user');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('customer_notes');
    }
};
