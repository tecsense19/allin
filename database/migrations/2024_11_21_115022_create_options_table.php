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
        Schema::create('options', function (Blueprint $table) {
            $table->id(); // Primary auto-increment ID
            $table->unsignedBigInteger('message_id'); // Foreign key to messages
            $table->foreign('message_id')->references('id')->on('message')->onDelete('cascade'); // Define foreign key constraint manually
            $table->string('option'); // The option text
            $table->string('option_id')->unique(); // Unique identifier for each option
            $table->string('users'); // The option text
            $table->timestamps();

            // Unique constraint for message_id and option combination
            $table->unique(['message_id', 'option']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('options');
    }
};
