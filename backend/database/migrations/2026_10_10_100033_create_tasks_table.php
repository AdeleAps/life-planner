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
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_request_id')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('source')->default('form');
            $table->string('kind')->default('one_off');
            $table->string('type')->default('evening');
            $table->string('priority')->default('standard');
            $table->string('energy_cost')->nullable();
            $table->string('lifecycle_state')->default('active');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
