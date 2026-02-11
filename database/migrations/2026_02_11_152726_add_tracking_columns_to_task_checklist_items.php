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
        Schema::table('task_checklist_items', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable()->after('status');
            $table->timestamp('on_hold_at')->nullable()->after('started_at');
            $table->timestamp('resumed_at')->nullable()->after('on_hold_at');
            $table->timestamp('reopened_at')->nullable()->after('resumed_at');
            $table->foreignId('last_worked_on_by')->nullable()->after('reopened_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_checklist_items', function (Blueprint $table) {
            $table->dropForeign(['last_worked_on_by']);
            $table->dropColumn(['started_at', 'on_hold_at', 'resumed_at', 'reopened_at', 'last_worked_on_by']);
        });
    }
};
