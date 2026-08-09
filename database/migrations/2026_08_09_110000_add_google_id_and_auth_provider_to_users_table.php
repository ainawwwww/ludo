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
            if (!Schema::hasColumn('users', 'google_id')) {
                $table->string('google_id', 255)->nullable()->unique('users_google_id_unique')->after('email');
            }
            if (!Schema::hasColumn('users', 'auth_provider')) {
                $table->enum('auth_provider', ['guest', 'email', 'google'])->default('email')->after('google_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_google_id_unique');
            $table->dropColumn(['google_id', 'auth_provider']);
        });
    }
};
