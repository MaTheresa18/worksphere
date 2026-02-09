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
        Schema::table('page_views', function (Blueprint $table) {
            $table->string('country')->nullable()->after('platform');
            $table->string('city')->nullable()->after('country');
            $table->string('iso_code')->nullable()->after('city');
            $table->decimal('lat', 10, 8)->nullable()->after('iso_code');
            $table->decimal('lon', 11, 8)->nullable()->after('lat');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('page_views', function (Blueprint $table) {
            $table->dropColumn(['country', 'city', 'iso_code', 'lat', 'lon']);
        });
    }
};
