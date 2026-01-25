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
        Schema::table('email_signatures', function (Blueprint $table) {
            $table->longText('content')->nullable()->change();
        });

        Schema::table('email_templates', function (Blueprint $table) {
            $table->longText('body')->nullable()->change();
            $table->string('subject', 998)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_signatures', function (Blueprint $table) {
            $table->longText('content')->nullable(false)->change();
        });

        Schema::table('email_templates', function (Blueprint $table) {
            $table->longText('body')->nullable(false)->change();
            $table->string('subject')->nullable(false)->change();
        });
    }
};
