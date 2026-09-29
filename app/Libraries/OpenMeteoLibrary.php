<?php

namespace App\Libraries;

use App\Exceptions\WeatherUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class OpenMeteoLibrary
{
    /** @return array<string, mixed> */
    public function current(): array
    {
        try {
            $response = Http::acceptJson()
                ->connectTimeout(3)
                ->timeout(5)
                ->get(config('weather.api_url'), [
                    'latitude' => config('weather.latitude'),
                    'longitude' => config('weather.longitude'),
                    'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,is_day,precipitation,weather_code,wind_speed_10m',
                    'timezone' => config('weather.timezone'),
                    'temperature_unit' => 'celsius',
                    'wind_speed_unit' => 'kmh',
                    'precipitation_unit' => 'mm',
                    'forecast_days' => 1,
                ])->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw new WeatherUnavailableException('The weather provider request failed.', previous: $exception);
        }

        $payload = $response->json();

        if (! is_array($payload) || Validator::make($payload, [
            'current.time' => ['required', 'date_format:Y-m-d\\TH:i'],
            'current.temperature_2m' => ['required', 'numeric'],
            'current.apparent_temperature' => ['required', 'numeric'],
            'current.relative_humidity_2m' => ['required', 'numeric', 'between:0,100'],
            'current.is_day' => ['required', 'integer', 'in:0,1'],
            'current.precipitation' => ['required', 'numeric', 'min:0'],
            'current.weather_code' => ['required', 'integer', 'between:0,99'],
            'current.wind_speed_10m' => ['required', 'numeric', 'min:0'],
        ])->fails()) {
            throw new WeatherUnavailableException('The weather provider returned invalid data.');
        }

        return [
            'location' => 'Perth, Australia',
            'latitude' => config('weather.latitude'),
            'longitude' => config('weather.longitude'),
            'timezone' => config('weather.timezone'),
            'observed_at' => $payload['current']['time'],
            'temperature_c' => (float) $payload['current']['temperature_2m'],
            'feels_like_c' => (float) $payload['current']['apparent_temperature'],
            'humidity_percent' => (float) $payload['current']['relative_humidity_2m'],
            'is_day' => (bool) $payload['current']['is_day'],
            'precipitation_mm' => (float) $payload['current']['precipitation'],
            'weather_code' => (int) $payload['current']['weather_code'],
            'wind_speed_kmh' => (float) $payload['current']['wind_speed_10m'],
            'fetched_at' => now()->toISOString(),
            'source' => 'Open-Meteo',
            'attribution_url' => 'https://open-meteo.com/',
        ];
    }
}
