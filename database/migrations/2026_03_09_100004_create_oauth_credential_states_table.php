<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_credential_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credential_id')->nullable()->constrained()->nullOnDelete();
            $table->string('credential_type', 100);
            $table->uuid('state_token')->unique();
            $table->string('provider', 100);
            $table->text('authorization_url');
            $table->string('redirect_uri');
            $table->json('scopes')->nullable();
            $table->string('code_verifier')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['state_token', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_credential_states');
    }
};
