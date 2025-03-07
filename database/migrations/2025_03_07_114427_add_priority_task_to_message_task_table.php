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
        Schema::table('message_task', function (Blueprint $table) {
            $table->boolean('priority_task')->default(false)->after('task_description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('message_task', function (Blueprint $table) {
            $table->dropColumn('priority_task');
        });
    }
};
