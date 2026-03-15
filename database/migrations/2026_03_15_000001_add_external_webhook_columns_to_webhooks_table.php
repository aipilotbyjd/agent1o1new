<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhooks', function (Blueprint $table) {
            $table->string('provider', 50)->nullable()->after('workspace_id');
            $table->string('external_webhook_id', 255)->nullable()->after('provider');
            $table->text('external_webhook_secret')->nullable()->after('external_webhook_id');
            $table->json('provider_config')->nullable()->after('external_webhook_secret');
        });
    }

    public function down(): void
    {
        Schema::table('webhooks', function (Blueprint $table) {
            $table->dropColumn([
                'provider',
                'external_webhook_id',
                'external_webhook_secret',
                'provider_config',
            ]);
        });
    }
};
