<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("UPDATE users SET phone = CONCAT('01', phone) WHERE phone REGEXP '^[0-9]{8}$'");
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 10)->nullable()->change();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users ADD CONSTRAINT chk_users_phone_digits CHECK (phone IS NULL OR phone REGEXP '^[0-9]{10}$')");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT chk_users_phone_digits');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->change();
        });
    }
};
