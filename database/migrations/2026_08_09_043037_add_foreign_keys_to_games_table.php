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
        Schema::table('games', function (Blueprint $table) {
            $table->foreign(['room_id'], 'games_ibfk_1')->references(['id'])->on('rooms')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['winner_id'], 'games_ibfk_2')->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropForeign('games_ibfk_1');
            $table->dropForeign('games_ibfk_2');
        });
    }
};
