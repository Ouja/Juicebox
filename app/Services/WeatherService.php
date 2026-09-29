<?php

namespace App\Services;

use App\Exceptions\WeatherUnavailableException;
use App\Libraries\OpenMeteoLibrary;
use App\Repositories\WeatherRepository;
use Illuminate\Contracts\Cache\LockTimeoutException;

class WeatherService
{
    public function __construct(
        private WeatherRepository $weather,
        private OpenMeteoLibrary $provider,
    ) {}

    /** @return array<string, mixed> */
    public function current(): array
    {
        if (($cached = $this->weather->get()) !== null) {
            return $cached;
        }

        try {
            return $this->weather->withRefreshLock(fn (): array => $this->weather->get() ?? $this->fetchAndCache());
        } catch (LockTimeoutException $exception) {
            throw new WeatherUnavailableException('A weather refresh is already in progress.', previous: $exception);
        }
    }

    /** @return array<string, mixed> */
    public function refresh(): array
    {
        try {
            return $this->weather->withRefreshLock(fn (): array => $this->fetchAndCache());
        } catch (LockTimeoutException $exception) {
            throw new WeatherUnavailableException('A weather refresh is already in progress.', previous: $exception);
        }
    }

    /** @return array<string, mixed> */
    private function fetchAndCache(): array
    {
        $weather = $this->provider->current();
        $this->weather->put($weather, max(1, (int) config('weather.cache_ttl')));

        return $weather;
    }
}
