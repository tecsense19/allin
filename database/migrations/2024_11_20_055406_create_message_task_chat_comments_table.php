<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('message_task_chat_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_chat_id');
            $table->unsignedBigInteger('message_id');
            $table->unsignedBigInteger('user_id');
            $table->text('comment');
            $table->timestamps();
    
            // Add foreign keys
            $table->foreign('task_chat_id')->references('id')->on('message_task')->onDelete('cascade');
            $table->foreign('message_id')->references('id')->on('message')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }
    
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_task_chat_comments');
    }
};
