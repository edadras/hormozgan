<?php

namespace App\Console\Commands\Museum;

use App\Models\Museum\ResearchTask;
use App\Museum\Ai\ResearchAgent;
use App\Museum\Services\EntityResolver;
use Illuminate\Console\Command;

class ResearchCommand extends Command
{
    protected $signature = 'museum:research {topic} {--max-chunks=25}';

    protected $description = 'Run the research agent on a topic (results go to review queues only)';

    public function handle(ResearchAgent $agent, EntityResolver $resolver): int
    {
        $r = $resolver->resolve($this->argument('topic'));
        $task = ResearchTask::create(['topic' => $this->argument('topic'), 'entity_id' => $r['entity']?->id, 'status' => 'queued']);
        $task = $agent->run($task, (int) $this->option('max-chunks'));
        $this->info('status: '.$task->status);
        foreach ($task->steps ?? [] as $s) {
            $this->line($s['step'].': '.json_encode(array_diff_key($s, ['step' => 1, 'at' => 1]), JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
