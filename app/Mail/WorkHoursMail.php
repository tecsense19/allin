<?php

namespace App\Mail;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WorkHoursMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    protected $filePath;
    protected $month;
    protected $fileName;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($filePath,$month,$fileName)
    {
        $this->filePath = $filePath;
        $this->month = $month;
        $this->fileName = $fileName;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $month = Carbon::parse($this->month)->format('M Y');
        $data = [
            'name' => auth()->user()->first_name . auth()->user()->first_name,
            'month' => $month
        ];
        return $this->view('email-templates.work-hours-email', $data)
                    ->subject('Submission of Work Hours for'.$month)
                    ->attach($this->filePath);
    }
}
