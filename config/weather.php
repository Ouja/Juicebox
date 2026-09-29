<?php

return [
    'api_url' => env('WEATHER_API_URL', 'https://api.open-meteo.com/v1/forecast'),
    'cache_ttl' => (int) env('WEATHER_CACHE_TTL', 900),
    'latitude' => -31.9523,
    'longitude' => 115.8613,
    'timezone' => 'Australia/Perth',
];
