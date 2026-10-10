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
        Schema::create('day_off_periods', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('title');
            $table->date('start_on');
            $table->date('end_on');
            $table->string('source_key')->nullable()->unique();
            $table->index(['start_on', 'end_on']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('day_off_periods');
    }
};
