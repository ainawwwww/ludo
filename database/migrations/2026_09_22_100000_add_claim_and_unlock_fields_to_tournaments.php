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
        Schema::table('tournaments', function (Blueprint $table) {
            if (!Schema::hasColumn('tournaments', 'unlock_level')) {
                $table->unsignedTinyInteger('unlock_level')->default(1)->after('max_level');
            }
        });

        Schema::table('tournament_participants', function (Blueprint $table) {
            if (!Schema::hasColumn('tournament_participants', 'is_claimed')) {
                $table->boolean('is_claimed')->default(false)->after('status');
            }
            if (!Schema::hasColumn('tournament_participants', 'claimed_at')) {
                $table->timestamp('claimed_at')->nullable()->after('is_claimed');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            if (Schema::hasColumn('tournaments', 'unlock_level')) {
                $table->dropColumn('unlock_level');
            }
        });

        Schema::table('tournament_participants', function (Blueprint $table) {
            if (Schema::hasColumn('tournament_participants', 'claimed_at')) {
                $table->dropColumn('claimed_at');
            }
            if (Schema::hasColumn('tournament_participants', 'is_claimed')) {
                $table->dropColumn('is_claimed');
            }
        });
    }
};
