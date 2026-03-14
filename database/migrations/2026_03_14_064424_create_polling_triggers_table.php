<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('polling_triggers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->unique()->constrained('workflows')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('endpoint_url', 2048);
            $table->enum('http_method', ['GET', 'POST'])->default('GET');
            $table->json('headers')->nullable();
            $table->json('query_params')->nullable();
            $table->json('body')->nullable();
            $table->string('dedup_key', 255)->comment('JSON path or field name used to deduplicate records');
            $table->unsignedInteger('interval_seconds')->default(300)->comment('Polling interval in seconds');
            $table->boolean('is_active')->default(true);
            $table->json('auth_config')->nullable()->comment('Credentials config: {type, token/username/password}');
            $table->json('last_seen_ids')->nullable()->comment('Array of most recent dedup IDs to prevent re-triggering');
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('next_poll_at')->nullable();
            $table->unsignedBigInteger('poll_count')->default(0);
            $table->unsignedBigInteger('trigger_count')->default(0);
            $table->string('last_error')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'next_poll_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('polling_triggers');
    }
};
