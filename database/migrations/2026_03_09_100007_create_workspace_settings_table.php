<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('timezone')->default('UTC');
            $table->integer('execution_retention_days')->default(30);
            $table->integer('default_max_retries')->default(1);
            $table->integer('default_timeout_seconds')->default(300);
            $table->boolean('auto_activate_workflows')->default(false);
            $table->boolean('allow_public_sharing')->default(true);
            $table->json('notification_preferences')->nullable();
            $table->json('git_sync_config')->nullable();
            $table->string('git_repo_url')->nullable();
            $table->string('git_branch')->default('main');
            $table->boolean('git_auto_sync')->default(false);
            $table->timestamp('last_git_sync_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_settings');
    }
};
