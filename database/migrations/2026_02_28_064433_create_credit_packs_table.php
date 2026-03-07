<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchased_by')->constrained('users');
            $table->unsignedInteger('credits_amount');
            $table->unsignedInteger('credits_remaining');
            $table->unsignedInteger('price_cents');
            $table->string('currency')->default('usd');
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('purchased_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_packs');
    }
};
