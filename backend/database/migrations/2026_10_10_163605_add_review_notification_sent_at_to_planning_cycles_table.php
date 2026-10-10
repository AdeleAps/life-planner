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
        Schema::table('planning_cycles', function (Blueprint $table) {
            $table->timestamp('review_notification_sent_at')->nullable()->after('review_requested_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('planning_cycles', function (Blueprint $table) {
            $table->dropColumn('review_notification_sent_at');
        });
    }
};
