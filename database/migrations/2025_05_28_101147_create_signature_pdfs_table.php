<?php

// database/migrations/xxxx_xx_xx_create_signature_pdfs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSignaturePdfsTable extends Migration
{
    public function up()
    {
        Schema::create('signature_pdfs', function (Blueprint $table) {
            $table->id();
            $table->string('file_upload');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('signature_pdfs');
    }
}
