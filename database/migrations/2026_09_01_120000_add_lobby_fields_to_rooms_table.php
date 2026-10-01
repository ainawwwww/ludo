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
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('title')->nullable()->after('room_code');
            $table->string('category')->nullable()->default('social')->after('title');
            $table->json('tags')->nullable()->after('category');
            $table->string('country_code', 10)->nullable()->index()->after('tags');
            $table->string('cover_image')->nullable()->after('country_code');
            $table->unsignedInteger('member_count')->default(0)->after('cover_image');
            $table->boolean('is_live')->default(true)->after('member_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn([
                'title',
                'category',
                'tags',
                'country_code',
                'cover_image',
                'member_count',
                'is_live',
            ]);
        });
    }
};
