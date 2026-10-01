<?php

namespace App\Museum\Search\Embeddings;

use App\Museum\Support\TextNormalizer;

/**
 * Offline, deterministic lexical embedding (feature hashing of normalized word tokens and
 * character trigrams). It is not a neural semantic model: it captures spelling-variant and
 * token overlap similarity. It keeps hybrid retrieval working without an external service;
 * production deployments should configure a neural provider (voyage / openai_compatible).
 */
class HashingEmbeddingProvider extends EmbeddingProvider
{
    public function __construct(private int $dims = 512) {}

    public function model(): string
    {
        return 'hashing-v1-'.$this->dims;
    }

    public function embed(array $texts, string $inputType = 'document'): array
    {
        return array_map(fn ($t) => $this->one((string) $t), $texts);
    }

    private function one(string $text): array
    {
        $v = array_fill(0, $this->dims, 0.0);
        $add = function (string $feature, float $w) use (&$v) {
            $h = crc32($feature);
            $v[$h % $this->dims] += ($h & 0x80000000) ? -$w : $w;
        };
        foreach (TextNormalizer::tokens($text) as $tok) {
            $add('w:'.$tok, 1.0);
            $chars = mb_str_split(' '.$tok.' ');
            for ($i = 0; $i < count($chars) - 2; $i++) {
                $add('c:'.$chars[$i].$chars[$i + 1].$chars[$i + 2], 0.35);
            }
        }

        return self::normalize($v);
    }
}
