<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_metrics_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('connector_key', 120);
            $table->string('connector_operation', 120);
            $table->date('day');
            $table->unsignedInteger('total_calls')->default(0);
            $table->unsignedInteger('success_calls')->default(0);
            $table->unsignedInteger('failure_calls')->default(0);
            $table->unsignedInteger('retry_calls')->default(0);
            $table->unsignedInteger('timeout_calls')->default(0);
            $table->unsignedInteger('p50_latency_ms')->nullable();
            $table->unsignedInteger('p95_latency_ms')->nullable();
            $table->unsignedInteger('p99_latency_ms')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'connector_key', 'connector_operation', 'day'], 'connector_metrics_daily_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_metrics_daily');
    }
};
