<?php

namespace App\Museum\Search\Embeddings;

abstract class EmbeddingProvider
{
    public static function make(array $cfg): self
    {
        return match ($cfg['driver'] ?? 'hashing') {
            'voyage' => new HttpEmbeddingProvider($cfg, 'voyage'),
            'openai_compatible' => new HttpEmbeddingProvider($cfg, 'openai'),
            default => new HashingEmbeddingProvider((int) ($cfg['dimensions'] ?? 512)),
        };
    }

    /** Model identifier stored with each vector (vectors of different models never mix). */
    abstract public function model(): string;

    /**
     * @param  list<string>  $texts
     * @return list<list<float>> L2-normalized vectors
     */
    abstract public function embed(array $texts, string $inputType = 'document'): array;

    public static function normalize(array $v): array
    {
        $n = sqrt(array_sum(array_map(fn ($x) => $x * $x, $v)));

        return $n > 0 ? array_map(fn ($x) => $x / $n, $v) : $v;
    }

    public static function pack(array $v): string
    {
        return pack('g*', ...$v);
    }

    public static function unpack(string $bin): array
    {
        return array_values(unpack('g*', $bin));
    }
}
