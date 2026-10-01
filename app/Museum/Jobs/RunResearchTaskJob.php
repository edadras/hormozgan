<?php

namespace App\Museum\Jobs;

use App\Models\Museum\ResearchTask;
use App\Museum\Ai\ResearchAgent;

class RunResearchTaskJob extends MuseumJob
{
    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $taskId)
    {
        $this->onMuseumQueue('ai');
    }

    public function handle(ResearchAgent $agent): void
    {
        $agent->run(ResearchTask::findOrFail($this->taskId));
    }
}
