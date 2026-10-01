<?php

namespace App\Museum\Ai;

use App\Models\Museum\EntityType;
use App\Models\Museum\ExtractionCandidate;
use App\Models\Museum\ExtractionJob;
use App\Models\Museum\Property;
use App\Models\Museum\RelationshipType;
use App\Models\Museum\Source;
use App\Models\Museum\TextChunk;
use App\Museum\Services\SourceService;
use App\Museum\Support\TextNormalizer;

/**
 * Information extraction from a text chunk with Claude (structured outputs).
 *
 * Anti-hallucination contract:
 *  - The model may only report what the text states; every item must carry an exact
 *    evidence quote copied from the chunk.
 *  - Items whose quote cannot be located in the chunk are rejected (kept with reason).
 *  - Unknown entity types / properties / relations are rejected, not coerced.
 *  - Accepted items become extraction candidates; nothing is published from here.
 */
class LlmExtractor
{
    public function __construct(private LlmClient $llm, private SourceService $sources) {}

    public function extractChunk(TextChunk $chunk, ?int $importBatchId = null): ExtractionJob
    {
        $job = ExtractionJob::create([
            'source_id' => $chunk->source_id,
            'target_type' => $chunk->getMorphClass(),
            'target_id' => $chunk->id,
            'extractor' => 'llm',
            'prompt_version' => config('museum.extraction.prompt_version'),
            'import_batch_id' => $importBatchId,
            'status' => 'running',
        ]);
        try {
            $result = $this->llm->json($this->systemPrompt(), $this->userPrompt($chunk), $this->schema(), [
                'model' => config('museum.llm.extraction_model'),
                'job_type' => 'extract',
                'subject' => $chunk,
                'max_tokens' => 8000,
            ]);
            $job->ai_job_id = $result->aiJobId;
            [$accepted, $rejected] = $this->persist($job, $chunk, $result->data ?? []);
            $job->forceFill(['status' => 'succeeded', 'candidates_count' => $accepted, 'rejected_count' => $rejected,
                'stats' => ['input_tokens' => $result->inputTokens, 'output_tokens' => $result->outputTokens]])->save();
        } catch (\Throwable $e) {
            $job->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)])->save();
        }

        return $job;
    }

    /** @return array{0: int, 1: int} accepted, rejected */
    public function persist(ExtractionJob $job, TextChunk $chunk, array $data): array
    {
        $types = array_keys(EntityType::map());
        $props = Property::pluck('key')->all();
        $rels = RelationshipType::pluck('key')->all();
        $source = $chunk->source_id ? Source::find($chunk->source_id) : null;
        $accepted = 0;
        $rejected = 0;

        $items = [];
        foreach ($data['entities'] ?? [] as $e) {
            $items[] = ['entity', $e, $e['type'] ?? null, $e['name'] ?? null, in_array($e['type'] ?? '', $types, true) ? null : 'unknown entity type'];
        }
        foreach ($data['facts'] ?? [] as $f) {
            $items[] = ['fact', $f, $f['subject_type'] ?? null, $f['subject'] ?? null, in_array($f['property'] ?? '', $props, true) ? null : 'unknown property'];
        }
        foreach ($data['relationships'] ?? [] as $r) {
            $items[] = ['relationship', $r, $r['subject_type'] ?? null, $r['subject'] ?? null, in_array($r['relation'] ?? '', $rels, true) ? null : 'unknown relation'];
        }
        foreach ($data['historical_names'] ?? [] as $h) {
            $items[] = ['historical_name', $h, $h['entity_type'] ?? null, $h['entity'] ?? null, null];
        }

        foreach ($items as [$kind, $payload, $typeKey, $surface, $reason]) {
            $quote = trim((string) ($payload['evidence_quote'] ?? ''));
            $offset = $quote !== '' ? $this->sources->locateQuote($chunk->text, $quote) : null;
            if (! $reason && $offset === null) {
                $reason = 'evidence quote not found in source text';
            }
            if (! $reason && $kind === 'fact' && ($payload['value'] ?? '') === '') {
                $reason = 'empty value (should be omitted or UNKNOWN)';
            }
            $extract = null;
            if (! $reason && $source) {
                $extract = $this->sources->extract($source, $quote, [
                    'page' => $chunk->page_number,
                    'raw_document_id' => $chunk->chunkable_type === (new \App\Models\Museum\RawDocument)->getMorphClass() ? $chunk->chunkable_id : null,
                    'extracted_by' => 'ai',
                ]);
            }
            $confidence = isset($payload['confidence']) ? max(0, min(1, (float) $payload['confidence'])) : null;
            ExtractionCandidate::create([
                'extraction_job_id' => $job->id,
                'kind' => $kind,
                'entity_type_key' => in_array($typeKey, $types, true) ? $typeKey : null,
                'surface_form' => $surface ? mb_substr($surface, 0, 500) : null,
                'normalized_form' => $surface ? mb_substr(TextNormalizer::normalize($surface), 0, 500) : null,
                'payload' => $payload,
                'evidence_quote' => $quote ?: null,
                'source_id' => $source?->id,
                'source_extract_id' => $extract?->id,
                'confidence_score' => $confidence,
                'evidence_source_ids' => $source ? [$source->id] : [],
                'status' => $reason ? 'rejected' : 'pending',
                'rejection_reason' => $reason,
            ]);
            $reason ? $rejected++ : $accepted++;
        }

        return [$accepted, $rejected];
    }

    public function systemPrompt(): string
    {
        $types = implode(', ', array_keys(EntityType::map()));
        $props = Property::orderBy('key')->get(['key', 'datatype'])->map(fn ($p) => $p->key.':'.$p->datatype)->implode(', ');
        $rels = RelationshipType::orderBy('key')->pluck('key')->implode(', ');

        return <<<PROMPT
You extract structured knowledge about Hormozgan province (Iran) for a museum archive whose
non-negotiable principles are accuracy, traceability and preservation.

Rules:
- Report only what the given text explicitly states. Do not use outside knowledge, do not infer,
  do not complete missing details, do not normalize spellings of names (copy them as written).
- Every item must include "evidence_quote": an exact, contiguous copy of the words in the text
  that state it (5–300 characters). Items without such a quote must be omitted.
- If the text says something is unknown or uncertain, keep that uncertainty in the value; never
  pick a value yourself.
- confidence: your certainty (0–1) that the text states exactly this.
- Dates: give years as written; if a calendar other than CE is used, put it in "qualifiers".
- Prefer omission over speculation. An empty result is a correct answer for irrelevant text.

Allowed entity types: {$types}
Allowed fact properties (key:datatype): {$props}
Allowed relations: {$rels}
PROMPT;
    }

    private function userPrompt(TextChunk $chunk): string
    {
        $src = $chunk->source ? $chunk->source->citationLabel() : 'unknown source';

        return "Source: {$src}\nPage: ".($chunk->page_number ?? 'n/a')."\n\n<text>\n{$chunk->text}\n</text>";
    }

    public function schema(): array
    {
        $str = ['type' => 'string'];
        $conf = ['type' => 'number'];
        $obj = fn (array $props) => [
            'type' => 'object',
            'properties' => $props,
            'required' => array_keys($props),
            'additionalProperties' => false,
        ];

        return $obj([
            'entities' => ['type' => 'array', 'items' => $obj([
                'name' => $str, 'type' => $str, 'name_language' => $str,
                'evidence_quote' => $str, 'confidence' => $conf,
            ])],
            'facts' => ['type' => 'array', 'items' => $obj([
                'subject' => $str, 'subject_type' => $str, 'property' => $str, 'value' => $str,
                'qualifiers' => $str, 'evidence_quote' => $str, 'confidence' => $conf,
            ])],
            'relationships' => ['type' => 'array', 'items' => $obj([
                'subject' => $str, 'subject_type' => $str, 'relation' => $str, 'object' => $str, 'object_type' => $str,
                'evidence_quote' => $str, 'confidence' => $conf,
            ])],
            'historical_names' => ['type' => 'array', 'items' => $obj([
                'entity' => $str, 'entity_type' => $str, 'historical_name' => $str, 'period' => $str,
                'evidence_quote' => $str, 'confidence' => $conf,
            ])],
        ]);
    }
}
