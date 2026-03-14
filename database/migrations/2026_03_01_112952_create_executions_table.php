<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->enum('status', ['pending', 'running', 'completed', 'failed', 'cancelled', 'waiting']);
            $table->enum('mode', ['manual', 'webhook', 'schedule', 'retry']);
            $table->foreignId('triggered_by')->nullable()->constrained('users');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->decimal('estimated_cost_usd', 10, 4)->nullable();
            $table->unsignedInteger('credits_consumed')->default(0);
            $table->json('trigger_data')->nullable();
            $table->json('result_data')->nullable();
            $table->json('error')->nullable();
            $table->unsignedInteger('attempt')->default(1);
            $table->unsignedInteger('max_attempts')->default(1);
            $table->unsignedBigInteger('parent_execution_id')->nullable();
            $table->foreign('parent_execution_id')->references('id')->on('executions')->cascadeOnDelete();
            $table->unsignedBigInteger('replay_of_execution_id')->nullable();
            $table->foreign('replay_of_execution_id')->references('id')->on('executions')->nullOnDelete();
            $table->boolean('is_deterministic_replay')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->unsignedInteger('node_count')->default(0);
            $table->unsignedInteger('completed_node_count')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['workflow_id', 'status']);
            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('executions');
    }
};
