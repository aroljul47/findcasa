<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('external_sources', function (Blueprint $table) {
            $table->unsignedTinyInteger('consecutive_failures')->default(0);
            $table->timestamp('disabled_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('external_sources', function (Blueprint $table) {
            $table->dropColumn(['consecutive_failures', 'disabled_at']);
        });
    }
};
