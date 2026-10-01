<?php

namespace App\Museum\Jobs;

use App\Models\Museum\Entity;
use App\Museum\Search\SearchIndexer;

class ReindexEntityJob extends MuseumJob
{
    public function __construct(public int $entityId)
    {
        $this->onMuseumQueue('index');
    }

    public function handle(SearchIndexer $indexer): void
    {
        $e = Entity::withTrashed()->find($this->entityId);
        if ($e) {
            $indexer->indexEntity($e);
            EmbedChunksJob::dispatch($e->getMorphClass(), $e->id);
        }
    }
}
