<?php

namespace App\Console\Commands\Museum;

use App\Models\Museum\Entity;
use App\Models\Museum\Sentence;
use App\Museum\Jobs\EmbedChunksJob;
use App\Museum\Search\SearchIndexer;
use Illuminate\Console\Command;

class ReindexCommand extends Command
{
    protected $signature = 'museum:search:reindex {--embed : Also compute missing embeddings synchronously}';

    protected $description = 'Rebuild the search index (and optionally embeddings) from the knowledge base';

    public function handle(SearchIndexer $indexer): int
    {
        $n = 0;
        Entity::whereNull('merged_into_id')->with('type')->chunkById(200, function ($entities) use ($indexer, &$n) {
            foreach ($entities as $e) {
                $indexer->indexEntity($e);
                $n++;
            }
            $this->line("  indexed {$n} entities");
        });
        Sentence::chunkById(500, fn ($rows) => $rows->each(fn ($s) => $indexer->indexSentence($s)));
        if ($this->option('embed')) {
            EmbedChunksJob::dispatchSync(null, null, 1000000);
            $this->info('embeddings computed');
        }
        $this->info("done: {$n} entities");

        return self::SUCCESS;
    }
}
