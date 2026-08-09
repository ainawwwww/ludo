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
        Schema::create('game_moves', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('game_id')->index('game_id');
            $table->unsignedBigInteger('user_id')->index('user_id');
            $table->tinyInteger('token_id');
            $table->tinyInteger('dice_value');
            $table->integer('from_pos')->nullable();
            $table->integer('to_pos')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->boolean('is_kill')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_moves');
    }
};
