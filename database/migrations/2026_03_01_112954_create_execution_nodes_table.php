<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained('executions')->cascadeOnDelete();
            $table->string('node_id', 100);
            $table->string('node_type', 100);
            $table->string('node_name')->nullable();
            $table->enum('status', ['pending', 'running', 'completed', 'failed', 'skipped']);
            $table->timestamp('started_at', 3)->nullable();
            $table->timestamp('finished_at', 3)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('input_data')->nullable();
            $table->json('output_data')->nullable();
            $table->json('error')->nullable();
            $table->unsignedInteger('sequence');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['execution_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_nodes');
    }
};
