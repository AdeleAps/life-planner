<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('kind');
            $table->string('item_type')->default('task')->after('source');
            $table->foreignId('project_id')->nullable()->after('item_type')->constrained('tasks')->restrictOnDelete();
            $table->string('type')->nullable()->default(null)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('tasks')->whereNotNull('project_id')->update(['project_id' => null]);
        DB::table('tasks')->where('item_type', 'project')->delete();

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
            $table->dropColumn('item_type');
            $table->string('type')->default('evening')->nullable(false)->change();
            $table->string('kind')->default('one_off');
        });

        DB::table('tasks')->where('recurrence_type', '!=', 'one_time')->update(['kind' => 'recurring']);
    }
};
