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
        Schema::create('advance_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('task_revision');
            $table->date('proposed_on')->nullable();
            $table->string('status');
            $table->json('research_result')->nullable();
            $table->date('next_check_on')->nullable();
            $table->unsignedTinyInteger('notice_shortfall_days')->default(0);
            $table->unsignedSmallInteger('allowance_cents')->default(0);
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['task_id', 'task_revision']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('advance_offers');
    }
};
