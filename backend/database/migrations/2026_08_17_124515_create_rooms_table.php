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
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('rent', 12, 2);
            $table->decimal('deposit', 12, 2)->nullable();
            $table->decimal('advance', 12, 2)->nullable();
            $table->decimal('additional_fees', 12, 2)->default(0);
            $table->string('status')->default('available');
            $table->timestamps();
            $table->index(['property_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
