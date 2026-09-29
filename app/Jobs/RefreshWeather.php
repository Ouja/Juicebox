<?php

namespace App\Jobs;

use App\Services\WeatherService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshWeather implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 300;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 60, 120];
    }

    public function handle(WeatherService $weather): void
    {
        $weather->refresh();
    }
}
