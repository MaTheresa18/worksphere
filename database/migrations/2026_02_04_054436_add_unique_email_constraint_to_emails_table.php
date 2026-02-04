<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            // Add unique constraint to prevent duplicate emails
            // Keep existing index as it may be used by foreign keys
            $table->unique(
                ['email_account_id', 'imap_uid', 'folder'],
                'emails_unique_account_uid_folder'
            );
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropUnique('emails_unique_account_uid_folder');
        });
    }
};
