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
        Schema::table('teams', function (Blueprint $table) {
            $table->timestamp('last_activity_at')->nullable()->after('updated_at');
            $table->string('lifecycle_status', 20)->default('active')->after('last_activity_at');
            $table->timestamp('dormant_notified_at')->nullable()->after('lifecycle_status');
            $table->timestamp('deletion_scheduled_at')->nullable()->after('dormant_notified_at');

            $table->index('lifecycle_status');
            $table->index('last_activity_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropIndex(['lifecycle_status']);
            $table->dropIndex(['last_activity_at']);
            $table->dropColumn([
                'last_activity_at',
                'lifecycle_status',
                'dormant_notified_at',
                'deletion_scheduled_at',
            ]);
        });
    }
};
