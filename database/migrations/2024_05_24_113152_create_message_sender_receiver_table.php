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
        Schema::create('message_sender_receiver', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id',10)->nullable()->unsigned()->constrained('message');
            $table->foreignId('sender_id',10)->nullable()->unsigned()->constrained('users');
            $table->foreignId('receiver_id',10)->nullable()->unsigned()->constrained('users');
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
        Schema::dropIfExists('message_sender_receiver');
    }
};
