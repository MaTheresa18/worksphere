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
        Schema::table('users', function (Blueprint $table) {
            $table->string('registration_fingerprint')->nullable()->after('remember_token');
        });

        Schema::table('page_views', function (Blueprint $table) {
            $table->string('fingerprint')->nullable()->index()->after('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('registration_fingerprint');
        });

        Schema::table('page_views', function (Blueprint $table) {
            $table->dropColumn('fingerprint');
        });
    }
};
