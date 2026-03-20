<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('job_statuses');
    }

    public function down(): void
    {
        Schema::create('job_statuses', function (Blueprint $table) {
            $table->id();
            $table->uuid('job_id')->unique();
            $table->foreignId('execution_id')->nullable()->constrained()->nullOnDelete();
            $table->tinyInteger('partition')->default(0);
            $table->string('callback_token', 64)->unique();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed']);
            $table->tinyInteger('progress')->default(0);
            $table->json('result')->nullable();
            $table->json('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('execution_id');
            $table->index('status');
        });
    }
};
