<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_daily_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('task_key');
            $table->string('title');
            $table->string('reward_type')->default('coins'); // coins, diamonds
            $table->unsignedInteger('reward_amount')->default(500);
            $table->unsignedInteger('current_progress')->default(0);
            $table->unsignedInteger('total_progress')->default(1);
            $table->boolean('is_claimed')->default(false);
            $table->date('task_date');
            $table->timestamps();

            $table->unique(['user_id', 'task_key', 'task_date'], 'user_task_date_unique');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_chest_claimed_at')->nullable()->after('rank');
            $table->timestamp('vip_expires_at')->nullable()->after('last_chest_claimed_at');
        });

        Schema::create('event_claim_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('claim_type');
            $table->string('request_id')->index();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_claim_logs');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['last_chest_claimed_at', 'vip_expires_at']);
        });

        Schema::dropIfExists('user_daily_tasks');
    }
};
