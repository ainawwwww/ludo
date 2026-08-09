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
        Schema::table('user_inventory', function (Blueprint $table) {
            $table->foreign(['user_id'], 'user_inventory_ibfk_1')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['item_id'], 'user_inventory_ibfk_2')->references(['id'])->on('store_items')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_inventory', function (Blueprint $table) {
            $table->dropForeign('user_inventory_ibfk_1');
            $table->dropForeign('user_inventory_ibfk_2');
        });
    }
};
