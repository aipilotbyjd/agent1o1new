<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_agent_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained()->cascadeOnDelete();
            $table->string('execution_node_key');
            $table->unsignedSmallInteger('step_number');
            $table->string('action');
            $table->string('tool_name')->nullable();
            $table->json('tool_input')->nullable();
            $table->json('tool_output')->nullable();
            $table->text('llm_reasoning')->nullable();
            $table->unsignedInteger('tokens_used')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();

            $table->index(['execution_id', 'execution_node_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_agent_steps');
    }
};
