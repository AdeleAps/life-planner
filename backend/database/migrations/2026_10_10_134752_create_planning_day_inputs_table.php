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
        Schema::create('planning_day_inputs', function (Blueprint $table) {
            $table->id();
            $table->date('plan_date')->unique();
            $table->string('energy_level')->default('standard');
            $table->boolean('day_off')->default(false);
            $table->boolean('dinner_enabled')->default(false);
            $table->string('dinner_dish')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('planning_day_inputs');
    }
};
