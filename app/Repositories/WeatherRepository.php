<?php

namespace App\Repositories;

use Closure;
use Illuminate\Support\Facades\Cache;

class WeatherRepository
{
    private const CACHE_KEY = 'weather:perth:current:v1';

    /** @return array<string, mixed>|null */
    public function get(): ?array
    {
        return Cache::get(self::CACHE_KEY);
    }

    /** @param array<string, mixed> $weather */
    public function put(array $weather, int $ttl): void
    {
        Cache::put(self::CACHE_KEY, $weather, $ttl);
    }

    /** @param Closure(): array<string, mixed> $callback
     * @return array<string, mixed>
     */
    public function withRefreshLock(Closure $callback): array
    {
        return Cache::lock(self::CACHE_KEY.':refresh', 15)->block(2, $callback);
    }
}
