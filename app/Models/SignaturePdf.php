<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SignaturePdf extends Model
{
    use HasFactory;

    protected $table = 'signature_pdfs';

    protected $fillable = ['file_upload'];
}
