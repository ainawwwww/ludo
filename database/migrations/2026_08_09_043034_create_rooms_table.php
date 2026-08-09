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
        Schema::create('rooms', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('room_code', 20)->unique('room_code');
            $table->enum('type', ['public', 'private'])->nullable()->default('public');
            $table->tinyInteger('max_players')->nullable()->default(4);
            $table->bigInteger('entry_fee')->nullable()->default(0);
            $table->string('status')->nullable()->default('waiting');
            $table->unsignedBigInteger('created_by')->index();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
