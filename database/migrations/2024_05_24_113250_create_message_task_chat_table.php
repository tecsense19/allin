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
        Schema::create('message_task_chat', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id',10)->nullable()->unsigned()->constrained('message_task');
            $table->foreignId('message_id',10)->nullable()->unsigned()->constrained('message');
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
        Schema::dropIfExists('message_task_chat');
    }
};
