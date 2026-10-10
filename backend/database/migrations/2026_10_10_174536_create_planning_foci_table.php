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
        Schema::create('planning_foci', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->date('starts_on');
            $table->text('focus')->nullable();
            $table->timestamp('prompted_at')->nullable();
            $table->timestamps();
            $table->unique(['kind', 'starts_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('planning_foci');
    }
};
