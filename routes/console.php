<?php

use App\Museum\Jobs\EmbedChunksJob;
use Illuminate\Support\Facades\Schedule;

/*
| Museum background schedule. Heavy work runs on dedicated queues (see config/museum.php);
| workers: php artisan queue:work redis --queue=museum-crawl,museum-extract,museum-ai,museum-index,museum-media,default
*/
Schedule::command('museum:backup --with-files')->dailyAt('02:30')->withoutOverlapping()->onOneServer();
Schedule::command('museum:quality')->hourly()->withoutOverlapping();
Schedule::command('museum:crawl')->dailyAt('03:30')->withoutOverlapping()->onOneServer();
Schedule::command('museum:candidates')->everyFifteenMinutes()->withoutOverlapping();
Schedule::job(new EmbedChunksJob)->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=720')->daily();
