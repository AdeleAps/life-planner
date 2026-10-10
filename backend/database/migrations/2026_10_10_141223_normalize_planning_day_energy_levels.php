<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('planning_day_inputs')->where('energy_level', 'high')->update(['energy_level' => 'standard']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Previous high-energy choices cannot be reconstructed.
    }
};
