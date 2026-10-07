<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_heads', function (Blueprint $table): void {
            $table->id();
            $table->string('instance', 64);
            $table->string('number', 15);
            $table->unsignedBigInteger('current_conversation_id')->nullable();
            $table->unsignedBigInteger('next_order')->default(0);
            $table->timestampTz('last_accepted_at', 6)->nullable();
            $table->timestampsTz(6);
            $table->unique(['instance', 'number']);
        });
        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_head_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('generation');
            $table->string('session_id')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestampTz('started_at', 6);
            $table->timestampTz('last_accepted_at', 6);
            $table->timestampsTz(6);
            $table->unique(['conversation_head_id', 'generation']);
        });
        Schema::table('conversation_heads', function (Blueprint $table): void {
            $table->foreign('current_conversation_id')->references('id')->on('conversations')->restrictOnDelete();
        });
        Schema::create('inbound_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_head_id')->constrained()->restrictOnDelete();
            $table->foreignId('conversation_id')->constrained()->restrictOnDelete();
            $table->string('instance', 64);
            $table->string('external_id');
            $table->text('text');
            $table->unsignedBigInteger('local_order');
            $table->timestampTz('accepted_at', 6);
            $table->string('status', 24)->default('pending')->index();
            $table->timestampsTz(6);
            $table->unique(['instance', 'external_id']);
            $table->unique(['conversation_head_id', 'local_order']);
        });
        Schema::create('executions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('inbound_message_id')->unique()->constrained()->restrictOnDelete();
            $table->uuid('runner_id')->unique();
            $table->string('status', 24)->default('pending')->index();
            $table->string('session_id')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('final_response')->nullable();
            $table->timestampTz('started_at', 6)->nullable();
            $table->timestampTz('finished_at', 6)->nullable();
            $table->timestampsTz(6);
        });
        Schema::create('outbound_parts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('conversation_head_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('execution_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source_key')->unique();
            $table->unsignedBigInteger('local_order');
            $table->unsignedInteger('part_index');
            $table->text('text');
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('evolution_id')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestampTz('retry_at', 6)->nullable();
            $table->timestampTz('sent_at', 6)->nullable();
            $table->timestampsTz(6);
            $table->unique(['execution_id', 'part_index']);
            $table->unique(['conversation_head_id', 'local_order', 'part_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_parts');
        Schema::dropIfExists('executions');
        Schema::dropIfExists('inbound_messages');
        Schema::table('conversation_heads', function (Blueprint $table): void {
            $table->dropForeign(['current_conversation_id']);
        });
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('conversation_heads');
    }
};
