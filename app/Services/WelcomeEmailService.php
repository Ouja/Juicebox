<?php

namespace App\Services;

use App\Jobs\SendWelcomeEmail;
use App\Libraries\MailLibrary;
use App\Repositories\UserRepository;

class WelcomeEmailService
{
    public function __construct(
        private UserRepository $users,
        private MailLibrary $mail,
    ) {}

    public function queue(int $userId): void
    {
        $user = $this->users->findOrFail($userId);
        SendWelcomeEmail::dispatch($user->id)->afterCommit();
    }

    public function send(int $userId): void
    {
        $user = $this->users->find($userId);

        if ($user !== null) {
            $this->mail->sendWelcome($user->email, $user->name);
        }
    }
}
