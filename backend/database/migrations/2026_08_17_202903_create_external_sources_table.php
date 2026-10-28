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
        Schema::create('external_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url');
            $table->string('adapter');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('collection_interval_minutes')->default(360);
            $table->timestamp('last_collected_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique('url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('external_sources');
    }
};
