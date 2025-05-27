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
        Schema::create('call_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id',10)->nullable()->unsigned()->constrained('users');
            $table->foreignId('receiver_id',10)->nullable()->unsigned()->constrained('users');
            $table->timestamp('call_start_time')->nullable();
            $table->timestamp('call_end_time')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_log');
    }
};
