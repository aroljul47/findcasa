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
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type', 50);
            $table->string('address')->nullable();
            $table->string('district')->nullable();
            $table->decimal('surface', 10, 2)->nullable();
            $table->unsignedSmallInteger('bedroom_count')->default(1);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('publication_status')->default('pending');
            $table->string('origin')->default('direct');
            $table->unsignedBigInteger('external_source_id')->nullable();
            $table->string('external_url')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->boolean('exact_location_visible')->default(false);
            $table->timestamps();
            $table->index(['publication_status', 'district']);
            $table->index(['latitude', 'longitude']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
