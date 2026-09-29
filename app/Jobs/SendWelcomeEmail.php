<?php

namespace App\Jobs;

use App\Services\WelcomeEmailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendWelcomeEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $userId) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 60, 120];
    }

    public function handle(WelcomeEmailService $welcome): void
    {
        $welcome->send($this->userId);
    }
}
