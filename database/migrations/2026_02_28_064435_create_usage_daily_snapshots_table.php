<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_daily_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->unsignedInteger('credits_used')->default(0);
            $table->unsignedInteger('executions_total')->default(0);
            $table->unsignedInteger('executions_succeeded')->default(0);
            $table->unsignedInteger('executions_failed')->default(0);
            $table->unsignedInteger('nodes_executed')->default(0);
            $table->unsignedInteger('ai_nodes_executed')->default(0);
            $table->timestamps();

            $table->unique(['workspace_id', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_daily_snapshots');
    }
};
