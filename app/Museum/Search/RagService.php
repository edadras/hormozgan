<?php

namespace App\Museum\Search;

use App\Models\Museum\Entity;
use App\Models\Museum\EntityAlias;
use App\Models\Museum\Fact;
use App\Models\Museum\RagQuery;
use App\Models\Museum\TextChunk;
use App\Museum\Ai\LlmClient;
use App\Museum\Search\Embeddings\EmbeddingProvider;
use App\Museum\Search\Vector\VectorStore;
use App\Museum\Support\TextNormalizer;

/**
 * Knowledge-base question answering (sections 33–34).
 *
 * Question → analysis (entities mentioned) → hybrid retrieval (keyword + vector)
 * → reciprocal-rank fusion → rerank → numbered context of *sourced* items → Claude
 * → citation validation → answer + citations.
 *
 * Guarantees: only published, sourced material is used; the model is told to answer only
 * from the context; every [n] marker is validated; an answer without valid citations is
 * withheld; when nothing relevant is found the LLM is not called at all.
 */
class RagService
{
    private const STOPWORDS = ['و', 'در', 'به', 'از', 'که', 'این', 'آن', 'با', 'را', 'برای', 'چه', 'چی', 'است', 'بود', 'بوده', 'می',
        'شده', 'شد', 'های', 'ها', 'یک', 'هم', 'تا', 'کدام', 'چطور', 'چگونه', 'آیا', 'مردم', 'the', 'of', 'and', 'in', 'what', 'is', 'a', 'to', 'how'];

    public function __construct(
        private SearchEngine $search,
        private EmbeddingProvider $embeddings,
        private VectorStore $vectors,
        private LlmClient $llm,
    ) {}

    public function ask(string $question, ?int $userId = null, ?string $ip = null): RagQuery
    {
        $started = microtime(true);
        $analysis = $this->analyze($question);
        $items = $this->retrieve($question, $analysis);

        $log = new RagQuery([
            'question' => $question,
            'analysis' => $analysis,
            'retrieved' => array_map(fn ($i) => ['key' => $i['key'], 'score' => round($i['score'], 5)], $items),
            'user_id' => $userId,
            'ip_hash' => $ip ? hash('sha256', $ip.config('app.key')) : null,
        ]);

        if (! $items) {
            $log->fill(['status' => 'insufficient_context', 'answer' => null, 'citations' => []]);
        } elseif (! $this->llm->available()) {
            $log->fill(['status' => 'retrieval_only', 'answer' => null, 'citations' => $this->citationList($items)]);
        } else {
            $this->generate($question, $items, $log);
        }
        $log->latency_ms = (int) ((microtime(true) - $started) * 1000);
        $log->save();

        return $log;
    }

    /** Detects known entities in the question (gazetteer over n-grams of the question). */
    public function analyze(string $question): array
    {
        $tokens = TextNormalizer::tokens($question);
        $grams = [];
        for ($i = 0; $i < count($tokens); $i++) {
            for ($n = 1; $n <= 3 && $i + $n <= count($tokens); $n++) {
                $g = implode(' ', array_slice($tokens, $i, $n));
                if (mb_strlen($g) >= 3 && ! in_array($g, self::STOPWORDS, true)) {
                    $grams[] = $g;
                }
            }
        }
        $entityIds = $grams ? EntityAlias::whereIn('normalized_alias', $grams)
            ->whereIn('entity_id', Entity::published()->select('id'))
            ->pluck('entity_id')->unique()->take(10)->values()->all() : [];

        return [
            'normalized' => TextNormalizer::normalize($question),
            'keywords' => array_values(array_diff($tokens, self::STOPWORDS)),
            'entity_ids' => $entityIds,
        ];
    }

    /** @return list<array{key: string, kind: string, score: float, text: string, citation: array}> */
    public function retrieve(string $question, array $analysis): array
    {
        $cfg = config('museum.rag');
        $publicOnly = (bool) ($cfg['public_only'] ?? true);
        $lists = [];

        // 1) keyword search over entities
        $hits = $this->search->search($question, ['public_only' => $publicOnly], 1, (int) $cfg['keyword_k'])['hits'];
        $lists[] = collect($hits)->filter(fn ($h) => $h['doc']->searchable_type === 'entity')
            ->map(fn ($h) => 'E:'.$h['doc']->searchable_id)->values()->all();
        // entities named in the question
        $lists[] = array_map(fn ($id) => 'E:'.$id, $analysis['entity_ids']);

        // 2) keyword search over text chunks
        $kw = array_slice(array_filter($analysis['keywords'], fn ($k) => mb_strlen($k) >= 2), 0, 6);
        if ($kw) {
            $chunks = TextChunk::query()->when($publicOnly, fn ($q) => $q->where('is_public', true))
                ->where(function ($q) use ($kw) {
                    foreach ($kw as $k) {
                        $q->orWhere('normalized_text', 'like', '%'.$k.'%');
                    }
                })->limit(300)->get(['id', 'normalized_text', 'chunkable_type', 'chunkable_id']);
            $lists[] = $chunks->map(function ($c) use ($kw) {
                $hits = 0;
                foreach ($kw as $k) {
                    $hits += str_contains($c->normalized_text, $k) ? 1 : 0;
                }

                return [$this->chunkKey($c), $hits];
            })->sortByDesc(1)->take((int) $cfg['keyword_k'])->pluck(0)->values()->all();
        }

        // 3) vector search
        try {
            $qv = $this->embeddings->embed([$question], 'query')[0] ?? null;
            if ($qv) {
                $vhits = $this->vectors->search($this->embeddings->model(), $qv, (int) $cfg['vector_k'], $publicOnly);
                $chunkMeta = TextChunk::whereIn('id', array_keys($vhits))->get(['id', 'chunkable_type', 'chunkable_id'])->keyBy('id');
                $lists[] = collect(array_keys($vhits))->map(fn ($id) => isset($chunkMeta[$id]) ? $this->chunkKey($chunkMeta[$id]) : null)->filter()->values()->all();
            }
        } catch (\Throwable) {
            // Vector search is an enhancement; keyword retrieval still answers.
        }

        // Reciprocal rank fusion (k = 60), plus a boost for entities named in the question.
        $scores = [];
        foreach ($lists as $list) {
            foreach (array_values(array_unique($list)) as $rank => $key) {
                $scores[$key] = ($scores[$key] ?? 0) + 1 / (60 + $rank + 1);
            }
        }
        foreach ($analysis['entity_ids'] as $id) {
            if (isset($scores['E:'.$id])) {
                $scores['E:'.$id] += 0.02;
            }
        }
        arsort($scores);

        return $this->expand(array_slice($scores, 0, (int) $cfg['context_chunks'] * 2, true), (int) $cfg['max_context_chars']);
    }

    private function chunkKey($chunk): string
    {
        return $chunk->chunkable_type === 'entity' ? 'E:'.$chunk->chunkable_id : 'C:'.$chunk->id;
    }

    /** Turns fused keys into citable context items: entity → its public facts; chunk → its text. */
    private function expand(array $scores, int $maxChars): array
    {
        $items = [];
        $chars = 0;
        foreach ($scores as $key => $score) {
            [$kind, $id] = explode(':', $key);
            if ($kind === 'E') {
                $entity = Entity::published()->find($id);
                if (! $entity) {
                    continue;
                }
                $facts = Fact::where('entity_id', $entity->id)->public()
                    ->with(['property', 'valueEntity', 'values', 'sources.source'])->limit(15)->get();
                foreach ($facts as $f) {
                    $fs = $f->sources->first();
                    if (! $fs) {
                        continue;
                    }
                    $text = $entity->displayName().' — '.$f->property->label_fa.': '.$f->displayValue('fa')
                        .($f->conflict_id ? ' (منابع در این مورد اختلاف دارند)' : '');
                    $items[] = ['key' => 'F:'.$f->id, 'kind' => 'fact', 'score' => $score, 'text' => $text, 'citation' => [
                        'fact_uuid' => $f->uuid, 'entity' => $entity->displayName(), 'entity_url' => $entity->publicUrl(),
                        'source_uuid' => $fs->source->uuid, 'source' => $fs->source->citationLabel(), 'page' => $fs->page_number,
                        'locator' => $fs->locator, 'url' => url('/museum/facts/'.$f->uuid),
                    ]];
                    $chars += mb_strlen($text);
                }
            } else {
                $chunk = TextChunk::with('source')->find($id);
                if (! $chunk || ! $chunk->source) {
                    continue; // unsourced text is never used as evidence
                }
                $items[] = ['key' => $key, 'kind' => 'chunk', 'score' => $score, 'text' => $chunk->text, 'citation' => [
                    'source_uuid' => $chunk->source->uuid, 'source' => $chunk->source->citationLabel(), 'page' => $chunk->page_number,
                    'locator' => $chunk->locator, 'url' => url('/museum/sources/'.$chunk->source->uuid),
                ]];
                $chars += mb_strlen($chunk->text);
            }
            if ($chars >= $maxChars) {
                break;
            }
        }

        return $items;
    }

    private function generate(string $question, array $items, RagQuery $log): void
    {
        $context = '';
        foreach ($items as $i => $item) {
            $n = $i + 1;
            $context .= "[{$n}] (".$item['citation']['source'].($item['citation']['page'] ? ', p. '.$item['citation']['page'] : '').")\n".$item['text']."\n\n";
        }
        $system = <<<'PROMPT'
You answer questions for the Hormozgan Digital Museum using ONLY the numbered context items provided.
- Every sentence that states a fact must end with the number(s) of the supporting item(s), e.g. [2] or [1][3].
- Do not use outside knowledge, do not guess, do not fill gaps. If the context does not answer the question,
  set insufficient_information=true and explain briefly what is missing.
- If items disagree, say so and cite both sides.
- Answer in the language of the question (Persian by default). Keep local names exactly as written in the context.
PROMPT;
        $schema = ['type' => 'object', 'properties' => [
            'answer' => ['type' => 'string'],
            'used_citations' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'insufficient_information' => ['type' => 'boolean'],
        ], 'required' => ['answer', 'used_citations', 'insufficient_information'], 'additionalProperties' => false];

        try {
            $res = $this->llm->json($system, "<context>\n{$context}</context>\n\nQuestion: {$question}", $schema, [
                'job_type' => 'answer', 'max_tokens' => 4000,
            ]);
        } catch (\Throwable $e) {
            $log->fill(['status' => 'error', 'answer' => null, 'citations' => $this->citationList($items)]);

            return;
        }
        $answer = (string) ($res->data['answer'] ?? '');
        preg_match_all('/\[(\d+)\]/', $answer, $m);
        $used = array_values(array_unique(array_map('intval', $m[1])));
        $valid = array_filter($used, fn ($n) => $n >= 1 && $n <= count($items));
        $insufficient = (bool) ($res->data['insufficient_information'] ?? false);
        $log->model = $res->model;

        if ($insufficient) {
            $log->fill(['status' => 'insufficient_context', 'answer' => $answer, 'citations' => $this->citationList($items, $valid)]);
        } elseif (! $valid || count($valid) !== count($used)) {
            // Uncited or wrongly cited output is withheld (anti-hallucination rule).
            $log->fill(['status' => 'rejected_uncited', 'answer' => null, 'citations' => $this->citationList($items)]);
        } else {
            $log->fill(['status' => 'answered', 'answer' => $answer, 'citations' => $this->citationList($items, $valid)]);
        }
    }

    private function citationList(array $items, ?array $only = null): array
    {
        $out = [];
        foreach ($items as $i => $item) {
            $n = $i + 1;
            if ($only !== null && ! in_array($n, $only, true)) {
                continue;
            }
            $out[] = ['n' => $n, 'kind' => $item['kind'], 'excerpt' => mb_substr($item['text'], 0, 300)] + $item['citation'];
        }

        return $out;
    }
}
