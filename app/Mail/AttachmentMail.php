<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Storage;

class AttachmentMail extends Mailable
{
    use Queueable, SerializesModels;

    public $filePath;

    public function __construct($filePath)
    {
        $this->filePath = $filePath;
    }

    public function build()
    {
        $fullPath = storage_path("app/" . $this->filePath);

        if (!file_exists($fullPath)) {
            \Log::error("Attachment file not found: " . $fullPath);
            return $this->subject('Attachment not found')
                        ->view('emails.attachment');
        }

        return $this->subject('Here is your attached file')
                    ->view('email-templates.attechmentEmail')
                    ->attach($fullPath);
    }
}
