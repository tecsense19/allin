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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('email',188)->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('country_code',5)->nullable();
            $table->string('mobile',12)->unique();
            $table->string('password')->nullable();
            $table->enum('role',['Admin','User'])->default('User');
            $table->enum('status',['Active','Inactive'])->default('Active');
            $table->rememberToken();
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
        Schema::dropIfExists('users');
    }
};
