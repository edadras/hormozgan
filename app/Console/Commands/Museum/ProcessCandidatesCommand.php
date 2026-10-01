<?php

namespace App\Console\Commands\Museum;

use App\Museum\Services\CandidateProcessor;
use Illuminate\Console\Command;

class ProcessCandidatesCommand extends Command
{
    protected $signature = 'museum:candidates {--limit=1000}';

    protected $description = 'Route pending extraction candidates (resolution, discovery, ai_extracted facts)';

    public function handle(CandidateProcessor $processor): int
    {
        $stats = $processor->processPending((int) $this->option('limit'));
        $this->table(['Outcome', 'Count'], collect($stats)->map(fn ($v, $k) => [$k, $v])->values());

        return self::SUCCESS;
    }
}
