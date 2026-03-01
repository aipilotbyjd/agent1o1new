<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_contract_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_version_id')->nullable()->constrained()->nullOnDelete();
            $table->char('graph_hash', 64);
            $table->enum('status', ['valid', 'warning', 'invalid']);
            $table->json('contracts');
            $table->json('issues')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['workflow_id', 'graph_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_contract_snapshots');
    }
};
