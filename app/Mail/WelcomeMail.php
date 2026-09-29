<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WelcomeMail extends Mailable
{
    use Queueable;

    public function __construct(public string $name) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Welcome to Juicebox');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.welcome');
    }
}
