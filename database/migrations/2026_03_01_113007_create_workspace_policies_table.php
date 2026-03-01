<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->json('allowed_node_types')->nullable();
            $table->json('blocked_node_types')->nullable();
            $table->json('allowed_ai_models')->nullable();
            $table->json('blocked_ai_models')->nullable();
            $table->decimal('max_execution_cost_usd', 10, 4)->nullable();
            $table->unsignedInteger('max_ai_tokens')->nullable();
            $table->json('redaction_rules')->nullable();
            $table->boolean('ai_auto_fix_enabled')->default(false);
            $table->decimal('ai_auto_fix_confidence_threshold', 3, 2)->default(0.95);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_policies');
    }
};
