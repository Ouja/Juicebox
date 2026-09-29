<?php

namespace App\Libraries;

use App\Mail\WelcomeMail;
use Illuminate\Support\Facades\Mail;

class MailLibrary
{
    public function sendWelcome(string $email, string $name): void
    {
        Mail::to($email)->send(new WelcomeMail($name));
    }
}
