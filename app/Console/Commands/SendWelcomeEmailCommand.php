<?php

namespace App\Console\Commands;

use App\Services\WelcomeEmailService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class SendWelcomeEmailCommand extends Command
{
    protected $signature = 'welcome:send {user : The ID of the registered user}';

    protected $description = 'Queue a welcome email for an existing user';

    public function handle(WelcomeEmailService $welcome): int
    {
        $id = (string) $this->argument('user');

        if (! preg_match('/^[1-9][0-9]{0,17}$/', $id)) {
            $this->error('The user ID must be a positive integer.');

            return self::FAILURE;
        }

        try {
            $welcome->queue((int) $id);
        } catch (ModelNotFoundException) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $this->info('Welcome email queued.');

        return self::SUCCESS;
    }
}
