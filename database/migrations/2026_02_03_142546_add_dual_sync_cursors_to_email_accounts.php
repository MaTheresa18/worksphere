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
        Schema::table('email_accounts', function (Blueprint $table) {
            // Forward crawler cursor - tracks newest synced UID
            $table->unsignedBigInteger('forward_uid_cursor')->nullable()->after('sync_cursor');
            $table->timestamp('last_forward_sync_at')->nullable()->after('forward_uid_cursor');

            // Backfill crawler cursor - tracks oldest synced UID
            $table->unsignedBigInteger('backfill_uid_cursor')->nullable()->after('last_forward_sync_at');
            $table->boolean('backfill_complete')->default(false)->after('backfill_uid_cursor');
            $table->timestamp('last_backfill_at')->nullable()->after('backfill_complete');

            // Sync started timestamp (to detect "new" emails after this point)
            $table->timestamp('sync_started_at')->nullable()->after('last_backfill_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'forward_uid_cursor',
                'last_forward_sync_at',
                'backfill_uid_cursor',
                'backfill_complete',
                'last_backfill_at',
                'sync_started_at',
            ]);
        });
    }
};
