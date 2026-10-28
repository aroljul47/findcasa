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
        Schema::table('collected_offers', function (Blueprint $table) {
            $table->string('fingerprint', 64)->nullable()->index();
            $table->foreignId('duplicate_of_id')->nullable()->constrained('collected_offers')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('collected_offers', function (Blueprint $table) {
            $table->dropForeign(['duplicate_of_id']);
            $table->dropIndex(['fingerprint']);
            $table->dropColumn(['fingerprint', 'duplicate_of_id']);
        });
    }
};
