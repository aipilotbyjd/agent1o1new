<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->foreignId('credential_id')->constrained('credentials')->cascadeOnDelete();
            $table->string('node_id', 100);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['workflow_id', 'node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_credentials');
    }
};
