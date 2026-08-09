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
        Schema::table('users', function (Blueprint $table) {
            $table->enum('gender', ['male', 'female', 'unspecified'])->default('unspecified')->after('avatar_url');
            $table->date('dob')->nullable()->after('gender');
            $table->string('bio', 255)->nullable()->after('country');
            $table->tinyInteger('name_change_count')->default(0)->after('bio');
            $table->timestamp('name_change_reset_at')->nullable()->after('name_change_count');
            $table->integer('league_points')->default(0)->after('name_change_reset_at');
            $table->integer('rank')->nullable()->after('league_points');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'gender',
                'dob',
                'bio',
                'name_change_count',
                'name_change_reset_at',
                'league_points',
                'rank',
            ]);
        });
    }
};
