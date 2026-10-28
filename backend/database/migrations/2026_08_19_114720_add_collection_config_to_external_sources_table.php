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
            $table->string('robots_url')->nullable();
            $table->json('configuration')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('external_sources', function (Blueprint $table) {
            $table->dropColumn(['robots_url', 'configuration']);
        });
    }
};
