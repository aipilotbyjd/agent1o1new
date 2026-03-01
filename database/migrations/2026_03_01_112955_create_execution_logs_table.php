<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained('executions')->cascadeOnDelete();
            $table->foreignId('execution_node_id')->nullable()->constrained('execution_nodes')->cascadeOnDelete();
            $table->enum('level', ['debug', 'info', 'warning', 'error']);
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('logged_at', 3);

            $table->index(['execution_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_logs');
    }
};
