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
        Schema::create('league_divisions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('league_season_id')->constrained('league_seasons')->cascadeOnDelete();
            $table->foreignId('league_tier_id')->constrained('league_tiers')->cascadeOnDelete();
            $table->integer('division_number')->default(1);
            $table->integer('max_players')->default(30);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('league_divisions');
    }
};
