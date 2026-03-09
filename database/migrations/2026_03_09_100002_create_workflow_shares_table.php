<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shared_by')->constrained('users')->cascadeOnDelete();
            $table->uuid('share_token')->unique();
            $table->boolean('is_public')->default(false);
            $table->boolean('allow_clone')->default(true);
            $table->string('password')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->integer('view_count')->default(0);
            $table->integer('clone_count')->default(0);
            $table->timestamps();

            $table->index(['workflow_id', 'workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_shares');
    }
};
