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
        Schema::table('store_items', function (Blueprint $table) {
            if (!Schema::hasColumn('store_items', 'item_key')) {
                $table->string('item_key', 100)->nullable()->unique()->after('name');
            }
        });

        // Broaden type column from rigid 3-value enum to flexible VARCHAR(50)
        try {
            \Illuminate\Support\Facades\DB::statement("ALTER TABLE store_items MODIFY COLUMN type VARCHAR(50);");
        } catch (\Throwable $e) {
            // Ignore if already VARCHAR or SQLite
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('store_items', function (Blueprint $table) {
            if (Schema::hasColumn('store_items', 'item_key')) {
                $table->dropColumn('item_key');
            }
        });
    }
};
