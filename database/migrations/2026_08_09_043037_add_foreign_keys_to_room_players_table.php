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
        Schema::table('room_players', function (Blueprint $table) {
            $table->foreign(['room_id'], 'room_players_ibfk_1')->references(['id'])->on('rooms')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['user_id'], 'room_players_ibfk_2')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('room_players', function (Blueprint $table) {
            $table->dropForeign('room_players_ibfk_1');
            $table->dropForeign('room_players_ibfk_2');
        });
    }
};
