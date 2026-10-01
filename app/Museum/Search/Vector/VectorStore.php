<?php

namespace App\Museum\Search\Vector;

abstract class VectorStore
{
    public static function make(array $cfg): self
    {
        return ($cfg['driver'] ?? 'database') === 'qdrant' ? new QdrantVectorStore($cfg) : new DatabaseVectorStore($cfg);
    }

    /** @param array<int, list<float>> $vectors chunk_id => vector */
    abstract public function upsert(string $model, array $vectors): void;

    /** @return array<int, float> chunk_id => cosine similarity, best first */
    abstract public function search(string $model, array $query, int $k, bool $publicOnly = true): array;
}
