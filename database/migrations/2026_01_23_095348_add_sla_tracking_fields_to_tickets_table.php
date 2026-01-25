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
        Schema::table('tickets', function (Blueprint $table) {
            // SLA Warning Timestamps
            $table->timestamp('sla_response_warning_at')->nullable()->after('sla_breached');
            $table->timestamp('sla_resolution_warning_at')->nullable()->after('sla_response_warning_at');

            // SLA Breach Tracking
            $table->timestamp('sla_breached_at')->nullable()->after('sla_resolution_warning_at');
            $table->string('sla_breach_type')->nullable()->after('sla_breached_at'); // 'response' or 'resolution'

            // Index for reporting queries
            $table->index('sla_breached_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['sla_breached_at']);
            $table->dropColumn([
                'sla_response_warning_at',
                'sla_resolution_warning_at',
                'sla_breached_at',
                'sla_breach_type',
            ]);
        });
    }
};
