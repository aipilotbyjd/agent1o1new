<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            $table->string('mode', 20)->change();
        });
    }

    public function down(): void
    {
        // Reverse is not practical — values may exist that don't fit the old enum.
    }
};
