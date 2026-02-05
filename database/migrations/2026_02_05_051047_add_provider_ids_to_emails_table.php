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
        Schema::table('emails', function (Blueprint $table) {
            $table->string('provider_id')->nullable()->index()->after('imap_uid')->comment('Provider-specific ID (e.g. Gmail API ID)');
            $table->index(['email_account_id', 'provider_id']);
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropIndex(['email_account_id', 'provider_id']);
            $table->dropColumn('provider_id');
        });
    }
};
