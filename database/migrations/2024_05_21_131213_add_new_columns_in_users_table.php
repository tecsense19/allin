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
            $table->integer('account_id')->unique()->nullable()->after('id');
            $table->longText('instagram_profile_url')->nullable()->after('cover_image');
            $table->longText('facebook_profile_url')->nullable()->after('instagram_profile_url');
            $table->longText('twitter_profile_url')->nullable()->after('facebook_profile_url');
            $table->longText('youtube_profile_url')->nullable()->after('twitter_profile_url');
            $table->longText('linkedin_profile_url')->nullable()->after('youtube_profile_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            //
        });
    }
};
