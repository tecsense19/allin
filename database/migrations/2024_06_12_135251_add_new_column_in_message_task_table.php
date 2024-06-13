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
        Schema::table('message_meeting', function (Blueprint $table) {
            $table->string('latitude')->nullable()->after('users');
            $table->string('longitude')->nullable()->after('latitude');
            $table->longText('location_url')->nullable()->after('longitude');
            $table->longText('location')->nullable()->after('location_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('message_meeting', function (Blueprint $table) {
            //
        });
    }
};
