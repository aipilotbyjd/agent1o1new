<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('frontier_state');
            $table->json('output_refs');
            $table->json('frame_stack')->nullable();
            $table->unsignedInteger('next_sequence')->default(0);
            $table->string('suspend_reason', 50)->nullable();
            $table->timestamp('resume_at')->nullable();
            $table->unsignedInteger('checkpoint_version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_checkpoints');
    }
};
