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
        Schema::create('games', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('room_id')->index();
            $table->string('status')->nullable()->default('playing');
            $table->unsignedBigInteger('winner_id')->nullable()->index();
            $table->timestamp('started_at')->nullable()->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
