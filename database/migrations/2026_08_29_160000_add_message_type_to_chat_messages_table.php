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
        if (Schema::hasTable('chat_messages') && !Schema::hasColumn('chat_messages', 'message_type')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->string('message_type', 30)->default('text')->after('message');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('chat_messages') && Schema::hasColumn('chat_messages', 'message_type')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->dropColumn('message_type');
            });
        }
    }
};
