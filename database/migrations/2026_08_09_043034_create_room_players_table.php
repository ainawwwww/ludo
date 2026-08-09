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
        Schema::create('room_players', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('user_id')->index('user_id');
            $table->tinyInteger('seat_position');
            $table->enum('color', ['red', 'green', 'yellow', 'blue']);
            $table->boolean('is_ready')->nullable()->default(false);
            $table->timestamp('joined_at')->nullable()->useCurrent();

            $table->unique(['room_id', 'color'], 'unique_room_color');
            $table->unique(['room_id', 'seat_position'], 'unique_room_seat');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room_players');
    }
};
