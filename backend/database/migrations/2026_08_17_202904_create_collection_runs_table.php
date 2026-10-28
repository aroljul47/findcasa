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
        Schema::create('collection_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('external_source_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->unsignedInteger('found_count')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['external_source_id', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collection_runs');
    }
};
