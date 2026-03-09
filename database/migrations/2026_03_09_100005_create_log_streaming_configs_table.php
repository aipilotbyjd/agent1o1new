<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_streaming_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('destination_type', 50);
            $table->json('destination_config');
            $table->json('event_types')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('include_node_data')->default(false);
            $table->timestamp('last_sent_at')->nullable();
            $table->integer('error_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_streaming_configs');
    }
};
