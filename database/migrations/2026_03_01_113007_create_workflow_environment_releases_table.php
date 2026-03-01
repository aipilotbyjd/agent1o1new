<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_environment_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_environment_id')->nullable()->constrained('workspace_environments')->nullOnDelete();
            $table->foreignId('to_environment_id')->nullable()->constrained('workspace_environments')->nullOnDelete();
            $table->foreignId('workflow_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('triggered_by')->nullable()->constrained('users');
            $table->enum('action', ['promote', 'rollback', 'sync']);
            $table->string('commit_sha', 64)->nullable();
            $table->json('diff_summary')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_environment_releases');
    }
};
