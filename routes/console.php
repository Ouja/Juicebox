<?php

use App\Jobs\RefreshWeather;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new RefreshWeather)->hourly()->name('weather:refresh')->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
