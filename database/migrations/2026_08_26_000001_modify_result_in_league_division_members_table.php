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
        Schema::table('league_division_members', function (Blueprint $table) {
            $table->string('result', 30)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('league_division_members', function (Blueprint $table) {
            $table->enum('result', ['promoted', 'demoted', 'stayed'])->nullable()->change();
        });
    }
};
