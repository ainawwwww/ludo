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
        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('device_id')->nullable()->unique('device_id');
            $table->string('username', 50)->unique('username');
            $table->string('email', 100)->nullable()->unique('email');
            $table->string('phone', 20)->nullable()->unique('phone');
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->string('avatar_url')->nullable();
            $table->string('country', 50)->nullable();
            $table->integer('level')->nullable()->default(1);
            $table->integer('xp')->nullable()->default(0);
            $table->boolean('is_guest')->nullable()->default(false);
            $table->boolean('is_active')->nullable()->default(true);
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
