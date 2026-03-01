<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_fix_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('execution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->string('failed_node_key', 100);
            $table->text('error_message');
            $table->text('diagnosis');
            $table->json('suggestions');
            $table->smallInteger('applied_index')->nullable();
            $table->string('model_used', 50);
            $table->unsignedInteger('tokens_used')->default(0);
            $table->string('status', 20)->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_fix_suggestions');
    }
};
