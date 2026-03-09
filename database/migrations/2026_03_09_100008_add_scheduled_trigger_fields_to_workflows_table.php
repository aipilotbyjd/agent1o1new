<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->string('trigger_type', 50)->nullable()->after('error_workflow_id');
            $table->string('cron_expression')->nullable()->after('trigger_type');
            $table->timestamp('next_run_at')->nullable()->after('cron_expression');
            $table->timestamp('last_cron_run_at')->nullable()->after('next_run_at');
        });
    }

    public function down(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->dropColumn(['trigger_type', 'cron_expression', 'next_run_at', 'last_cron_run_at']);
        });
    }
};
