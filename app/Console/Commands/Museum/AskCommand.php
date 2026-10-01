<?php

namespace App\Console\Commands\Museum;

use App\Museum\Search\RagService;
use Illuminate\Console\Command;

class AskCommand extends Command
{
    protected $signature = 'museum:ask {question}';

    protected $description = 'Ask the knowledge base (RAG with citations)';

    public function handle(RagService $rag): int
    {
        $q = $rag->ask($this->argument('question'));
        $this->info('status: '.$q->status);
        if ($q->answer) {
            $this->line($q->answer);
        }
        foreach ($q->citations ?? [] as $c) {
            $this->line('['.$c['n'].'] '.$c['source'].($c['page'] ? ', p. '.$c['page'] : '').' — '.$c['url']);
        }

        return self::SUCCESS;
    }
}
