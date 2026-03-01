<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_replay_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('source_execution_id')->nullable();
            $table->foreign('source_execution_id')->references('id')->on('executions');
            $table->enum('mode', ['capture', 'replay']);
            $table->uuid('deterministic_seed');
            $table->json('workflow_snapshot');
            $table->json('trigger_snapshot')->nullable();
            $table->json('fixtures')->nullable();
            $table->json('environment_snapshot')->nullable();
            $table->timestamp('captured_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_replay_packs');
    }
};
