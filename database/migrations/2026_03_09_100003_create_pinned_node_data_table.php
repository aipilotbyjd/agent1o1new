<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pinned_node_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pinned_by')->constrained('users')->cascadeOnDelete();
            $table->string('node_id', 100);
            $table->string('node_name')->nullable();
            $table->json('data');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['workflow_id', 'node_id']);
            $table->index(['workflow_id', 'workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pinned_node_data');
    }
};
