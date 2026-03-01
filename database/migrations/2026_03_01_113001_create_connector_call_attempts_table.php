<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_call_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('execution_node_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->string('connector_key', 120);
            $table->string('connector_operation', 120);
            $table->string('provider')->nullable();
            $table->smallInteger('attempt_no')->default(1);
            $table->boolean('is_retry')->default(false);
            $table->enum('status', ['success', 'client_error', 'server_error', 'timeout', 'network_error', 'cancelled']);
            $table->smallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->char('request_fingerprint', 64);
            $table->string('idempotency_key')->nullable();
            $table->string('error_code', 120)->nullable();
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('happened_at', 3);
            $table->timestamps();

            $table->index(['workspace_id', 'connector_key', 'happened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_call_attempts');
    }
};
