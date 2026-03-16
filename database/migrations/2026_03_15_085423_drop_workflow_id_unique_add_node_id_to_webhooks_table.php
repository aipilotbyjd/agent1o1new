<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhooks', function (Blueprint $table) {
            $table->dropUnique(['workflow_id']);

            $table->string('node_id', 100)->nullable()->after('provider_config');

            $table->unique(['workflow_id', 'node_id']);

            $table->index('provider');
        });
    }

    public function down(): void
    {
        Schema::table('webhooks', function (Blueprint $table) {
            $table->dropUnique(['workflow_id', 'node_id']);
            $table->dropIndex(['provider']);
            $table->dropColumn('node_id');

            $table->unique('workflow_id');
        });
    }
};
