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
        Schema::table('game_moves', function (Blueprint $table) {
            $table->foreign(['game_id'], 'game_moves_ibfk_1')->references(['id'])->on('games')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['user_id'], 'game_moves_ibfk_2')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('game_moves', function (Blueprint $table) {
            $table->dropForeign('game_moves_ibfk_1');
            $table->dropForeign('game_moves_ibfk_2');
        });
    }
};
