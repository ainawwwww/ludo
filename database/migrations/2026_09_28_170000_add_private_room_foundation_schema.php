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
        // 1. Add turn_seconds to rooms table
        Schema::table('rooms', function (Blueprint $table) {
            if (!Schema::hasColumn('rooms', 'turn_seconds')) {
                $table->unsignedTinyInteger('turn_seconds')->nullable()->default(null)->after('max_players');
            }
        });

        // 2. Add unique (room_id, user_id) to room_players table
        Schema::table('room_players', function (Blueprint $table) {
            $table->unique(['room_id', 'user_id'], 'room_players_room_id_user_id_unique');
        });

        // 3. Add index on transactions.reference_id
        Schema::table('transactions', function (Blueprint $table) {
            $table->index('reference_id', 'transactions_reference_id_index');
        });

        // 4. Add index on games.status
        Schema::table('games', function (Blueprint $table) {
            $table->index('status', 'games_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropIndex('games_status_index');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_reference_id_index');
        });

        Schema::table('room_players', function (Blueprint $table) {
            $table->dropUnique('room_players_room_id_user_id_unique');
        });

        Schema::table('rooms', function (Blueprint $table) {
            if (Schema::hasColumn('rooms', 'turn_seconds')) {
                $table->dropColumn('turn_seconds');
            }
        });
    }
};
