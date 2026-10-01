<?php

namespace App\Museum\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Base for museum queue jobs; each subclass picks its dedicated queue. */
abstract class MuseumJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    protected function onMuseumQueue(string $key): void
    {
        $this->onQueue(config('museum.queues.'.$key, 'default'));
    }
}
