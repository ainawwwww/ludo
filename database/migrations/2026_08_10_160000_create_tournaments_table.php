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
        Schema::create('tournaments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('mode', ['classic', 'quick'])->default('classic');
            $table->bigInteger('entry_fee');
            $table->enum('currency_type', ['coins', 'diamonds'])->default('coins');
            $table->bigInteger('prize_pool')->default(0);
            $table->unsignedTinyInteger('max_level')->default(6);
            $table->enum('status', ['active', 'closed'])->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tournaments');
    }
};
