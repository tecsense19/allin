<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class taskMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    protected $summary;
    public function __construct($summary)
    {
        $this->summary = $summary;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $data = [
            'title' => 'Summary of my Tasks',
            'name' => auth()->user()->first_name . auth()->user()->first_name,
            'summary' => $this->summary
        ];
        return $this->view('email-templates.work-hours-email', $data)
                    ->subject('Summary of my Tasks');
    }
}
