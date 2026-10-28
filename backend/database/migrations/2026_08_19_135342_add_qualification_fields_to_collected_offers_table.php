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
            $table->unsignedInteger('monthly_rent')->nullable()->after('category');
            $table->string('city')->nullable()->after('monthly_rent');
            $table->string('property_type')->nullable()->after('city');
            $table->text('description_excerpt')->nullable()->after('property_type');
            $table->string('image_url', 2048)->nullable()->after('description_excerpt');
            $table->decimal('latitude', 10, 7)->nullable()->after('image_url');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->foreignId('nearest_campus_id')->nullable()->after('longitude')->constrained('campuses')->nullOnDelete();
            $table->decimal('distance_km', 8, 2)->nullable()->after('nearest_campus_id');
            $table->unsignedTinyInteger('relevance_score')->default(0)->after('distance_km');
            $table->string('qualification_status')->default('incomplete')->after('relevance_score');
            $table->json('qualification_reasons')->nullable()->after('qualification_status');
            $table->index(['qualification_status', 'relevance_score']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('collected_offers', function (Blueprint $table) {
            $table->dropForeign(['nearest_campus_id']);
            $table->dropIndex(['qualification_status', 'relevance_score']);
            $table->dropColumn(['monthly_rent', 'city', 'property_type', 'description_excerpt', 'image_url', 'latitude', 'longitude', 'nearest_campus_id', 'distance_km', 'relevance_score', 'qualification_status', 'qualification_reasons']);
        });
    }
};
