<?php

namespace Tests\Feature;

use App\Exceptions\WeatherUnavailableException;
use App\Jobs\RefreshWeather;
use App\Models\User;
use App\Services\WeatherService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WeatherApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, mixed> */
    private function providerPayload(float $temperature = 23.5): array
    {
        return ['current' => [
            'time' => '2026-09-29T16:00',
            'temperature_2m' => $temperature,
            'apparent_temperature' => 22.1,
            'relative_humidity_2m' => 55,
            'is_day' => 1,
            'precipitation' => 0,
            'weather_code' => 2,
            'wind_speed_10m' => 12.4,
        ]];
    }

    public function test_weather_returns_perth_data_from_the_library(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Http::fake(['https://api.open-meteo.com/v1/forecast*' => Http::response($this->providerPayload())]);

        $this->getJson('/api/weather')->assertOk()->assertJsonPath('data.location', 'Perth, Australia')
            ->assertJsonPath('data.temperature_c', 23.5)->assertJsonPath('data.source', 'Open-Meteo')
            ->assertJsonPath('data.timezone', 'Australia/Perth');

        Http::assertSent(fn (Request $request): bool => $request['latitude'] === -31.9523
            && $request['longitude'] === 115.8613 && $request['timezone'] === 'Australia/Perth'
            && str_contains($request['current'], 'temperature_2m'));
    }

    public function test_weather_cache_is_reused_then_expires_after_fifteen_minutes(): void
    {
        $this->freezeTime();
        Sanctum::actingAs(User::factory()->create());
        Http::fake(['https://api.open-meteo.com/v1/forecast*' => Http::sequence()
            ->push($this->providerPayload(23.5))->push($this->providerPayload(25.5))]);

        $this->getJson('/api/weather')->assertOk()->assertJsonPath('data.temperature_c', 23.5);
        $this->travel(14)->minutes();
        $this->getJson('/api/weather')->assertOk()->assertJsonPath('data.temperature_c', 23.5);
        Http::assertSentCount(1);
        $this->travel(2)->minutes();
        $this->getJson('/api/weather')->assertOk()->assertJsonPath('data.temperature_c', 25.5);
        Http::assertSentCount(2);
    }

    public function test_refresh_job_replaces_fresh_cached_data(): void
    {
        Http::fake(['https://api.open-meteo.com/v1/forecast*' => Http::sequence()
            ->push($this->providerPayload(23.5))->push($this->providerPayload(25.5))]);
        $weather = app(WeatherService::class);
        $weather->current();

        RefreshWeather::dispatchSync();

        $this->assertSame(25.5, $weather->current()['temperature_c']);
        Http::assertSentCount(2);
    }

    public function test_weather_cache_and_lock_work_with_database_store(): void
    {
        config(['cache.default' => 'database']);
        Http::fake(['https://api.open-meteo.com/v1/forecast*' => Http::response($this->providerPayload())]);
        $weather = app(WeatherService::class);

        $first = $weather->current();
        $second = $weather->current();

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('cache', 1);
        $this->assertDatabaseCount('cache_locks', 0);
        Http::assertSentCount(1);
    }

    public function test_failed_refresh_preserves_valid_cache_and_releases_lock_for_retry(): void
    {
        $this->freezeTime();
        Http::fake(['https://api.open-meteo.com/v1/forecast*' => Http::sequence()
            ->push($this->providerPayload(23.5))->push([], 503)->push($this->providerPayload(25.5))]);
        $weather = app(WeatherService::class);
        $weather->current();

        try {
            RefreshWeather::dispatchSync();
            $this->fail('A failed refresh must be retried by the queue.');
        } catch (WeatherUnavailableException) {
            $this->assertSame(23.5, $weather->current()['temperature_c']);
        }

        RefreshWeather::dispatchSync();
        $this->assertSame(25.5, $weather->current()['temperature_c']);
        Http::assertSentCount(3);
    }

    public function test_hourly_schedule_dispatches_weather_job(): void
    {
        $this->travelTo(now()->setTime(10, 0));
        Queue::fake();

        $this->artisan('schedule:run')->assertSuccessful();

        Queue::assertPushed(RefreshWeather::class);
    }

    /** @return array<string, array{int, mixed}> */
    public static function providerFailures(): array
    {
        return [
            'server error' => [500, ['message' => 'private provider detail']],
            'rate limit' => [429, ['error' => true]],
            'missing data' => [200, ['current' => []]],
            'non-json response' => [200, '<html>Unavailable</html>'],
            'null response' => [200, 'null'],
        ];
    }

    #[DataProvider('providerFailures')]
    public function test_provider_failures_return_safe_503_and_are_not_cached(int $status, mixed $body): void
    {
        Sanctum::actingAs(User::factory()->create());
        Http::fake(['https://api.open-meteo.com/v1/forecast*' => Http::sequence()
            ->push($body, $status)->push($this->providerPayload())]);

        $this->getJson('/api/weather')->assertServiceUnavailable()
            ->assertExactJson(['data' => ['message' => 'Weather data is temporarily unavailable. Please try again later.']]);
        $this->getJson('/api/weather')->assertOk()->assertJsonPath('data.temperature_c', 23.5);
        Http::assertSentCount(2);
    }

    public function test_connection_failure_returns_503(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Http::fake(['https://api.open-meteo.com/v1/forecast*' => Http::failedConnection()]);

        $this->getJson('/api/weather')->assertServiceUnavailable()
            ->assertExactJson(['data' => ['message' => 'Weather data is temporarily unavailable. Please try again later.']]);
    }

    public function test_invalid_measurements_return_503(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $payload = $this->providerPayload();
        $payload['current']['relative_humidity_2m'] = 999;
        Http::fake(['https://api.open-meteo.com/v1/forecast*' => Http::response($payload)]);

        $this->getJson('/api/weather')->assertServiceUnavailable();
        Http::assertSentCount(1);
    }
}
