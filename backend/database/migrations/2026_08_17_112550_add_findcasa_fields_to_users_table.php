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
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('email');
            $table->string('role')->default('student')->after('phone');
            $table->foreignId('institution_id')->nullable()->after('role')->constrained()->nullOnDelete();
            $table->foreignId('campus_id')->nullable()->after('institution_id')->constrained()->nullOnDelete();
            $table->string('owner_verification_status')->default('not_applicable')->after('campus_id');
            $table->string('identity_document_path')->nullable()->after('owner_verification_status');
            $table->boolean('is_suspended')->default(false)->after('identity_document_path');
            $table->index(['role', 'is_suspended']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['institution_id']);
            $table->dropForeign(['campus_id']);
            $table->dropColumn(['phone', 'role', 'institution_id', 'campus_id', 'owner_verification_status', 'identity_document_path', 'is_suspended']);
        });
    }
};
