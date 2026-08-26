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
        Schema::create('league_division_members', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('league_division_id')->constrained('league_divisions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->integer('points_in_division')->default(0);
            $table->integer('final_rank')->nullable();
            $table->enum('result', ['promoted', 'demoted', 'stayed'])->nullable();
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamps();

            $table->unique(['league_division_id', 'user_id'], 'ldm_div_user_unique');
            $table->index(['league_division_id', 'points_in_division'], 'ldm_div_pts_idx');
            $table->index(['user_id', 'created_at'], 'ldm_user_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('league_division_members');
    }
};
