<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credential_types', function (Blueprint $table) {
            $table->json('oauth_config')->nullable()->after('test_config');
        });
    }

    public function down(): void
    {
        Schema::table('credential_types', function (Blueprint $table) {
            $table->dropColumn('oauth_config');
        });
    }
};
