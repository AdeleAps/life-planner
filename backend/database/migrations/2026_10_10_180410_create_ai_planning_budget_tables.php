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
        Schema::create('ai_monthly_budgets', function (Blueprint $table) {
            $table->string('month', 7)->primary();
            $table->unsignedInteger('allowance_cents')->default(0);
            $table->timestamps();
        });

        Schema::create('ai_planning_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_request_id')->unique();
            $table->date('plan_date');
            $table->string('month', 7);
            $table->string('status');
            $table->unsignedInteger('allowance_cents');
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->json('draft')->nullable();
            $table->timestamps();
            $table->index(['plan_date', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_planning_requests');
        Schema::dropIfExists('ai_monthly_budgets');
    }
};
