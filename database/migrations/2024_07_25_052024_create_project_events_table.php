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
        Schema::create('project_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id',10)->nullable()->unsigned()->constrained('users');
            $table->string('event_title');
            $table->longText('event_description')->nullable();
            $table->string('event_image')->nullable();
            $table->date('event_date');
            $table->time('event_time');
            $table->string('latitude')->nullable();
            $table->string('longitude')->nullable();
            $table->longText('location_url')->nullable();
            $table->longText('location')->nullable();
            $table->string('users')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->integer('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_events');
    }
};
