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
        Schema::create('league_seasons', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('season_number');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->enum('status', ['upcoming', 'active', 'completed'])->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('league_seasons');
    }
};
