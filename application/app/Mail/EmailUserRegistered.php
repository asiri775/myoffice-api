<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EmailUserRegistered extends Mailable
{
    use Queueable, SerializesModels;

    public string $subjectText;
    public string $htmlBody;

    public function __construct(string $subjectText, string $htmlBody)
    {
        $this->subjectText = $subjectText;
        $this->htmlBody    = $htmlBody;
    }

    public function build()
    {
        return $this->subject($this->subjectText)
                    ->html($this->htmlBody);
    }
}