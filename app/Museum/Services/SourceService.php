<?php

namespace App\Museum\Services;

use App\Models\Museum\Citation;
use App\Models\Museum\Source;
use App\Models\Museum\SourceExtract;
use App\Museum\Support\TextNormalizer;
use Illuminate\Database\Eloquent\Model;

/**
 * Source registry and the Fact → Source → Page → Extract chain.
 */
class SourceService
{
    /**
     * Registers (or finds) a source. Sources are deduplicated by canonical URL, DOI or ISBN,
     * so the same work cited from different places stays one record.
     */
    public function register(array $data, ?int $userId = null): Source
    {
        $existing = null;
        if (! empty($data['doi'])) {
            $existing = Source::where('doi', $data['doi'])->first();
        }
        if (! $existing && ! empty($data['isbn'])) {
            $existing = Source::where('isbn', $data['isbn'])->first();
        }
        if (! $existing && ! empty($data['url'])) {
            $existing = Source::where('url_hash', hash('sha256', Source::canonicalUrl($data['url'])))->first();
        }
        if ($existing) {
            // Fill only missing metadata; never overwrite reviewed metadata silently.
            foreach ($data as $k => $v) {
                if ($v !== null && $v !== '' && ($existing->{$k} === null || $existing->{$k} === '' || $existing->{$k} === [])) {
                    $existing->{$k} = $v;
                }
            }
            $existing->save();

            return $existing;
        }
        $data['created_by'] = $userId;
        if (! empty($data['url'])) {
            $data['url'] = Source::canonicalUrl($data['url']);
        }

        return Source::create($data);
    }

    /** Stores an exact excerpt; identical text from the same source/page is reused. */
    public function extract(Source $source, string $originalText, array $options = []): SourceExtract
    {
        $originalText = trim($originalText);
        if ($originalText === '') {
            throw new \InvalidArgumentException('Extract text cannot be empty.');
        }
        $page = $options['page'] ?? null;
        $locator = $options['locator'] ?? null;
        $hash = hash('sha256', $source->id.'|'.$page.'|'.$locator.'|'.$originalText);

        return SourceExtract::firstOrCreate(
            ['source_id' => $source->id, 'text_hash' => $hash],
            [
                'raw_document_id' => $options['raw_document_id'] ?? null,
                'document_page_id' => $options['document_page_id'] ?? null,
                'page_number' => $page,
                'locator' => $locator,
                'original_text' => $originalText,
                'normalized_text' => TextNormalizer::normalize($originalText),
                'language' => $options['language'] ?? null,
                'char_start' => $options['char_start'] ?? null,
                'char_end' => $options['char_end'] ?? null,
                'extracted_by' => $options['extracted_by'] ?? 'human',
            ]
        );
    }

    /** Citation for a non-fact record (word, sentence, recipe, media, ...). */
    public function cite(Model $record, Source $source, array $options = []): Citation
    {
        return Citation::firstOrCreate([
            'citable_type' => $record->getMorphClass(),
            'citable_id' => $record->getKey(),
            'source_id' => $source->id,
            'source_extract_id' => $options['extract']?->id ?? null,
        ], [
            'page_number' => $options['page'] ?? null,
            'locator' => $options['locator'] ?? null,
            'quote' => $options['quote'] ?? null,
            'role' => $options['role'] ?? 'evidence',
        ]);
    }

    /**
     * Evidence check used against hallucination: the quote must literally occur in the
     * source text (after normalization). Returns the char offset or null.
     */
    public function locateQuote(string $haystack, string $quote): ?int
    {
        $q = TextNormalizer::normalize($quote);
        if (mb_strlen($q) < 3) {
            return null;
        }
        $pos = mb_strpos(TextNormalizer::normalize($haystack), $q);

        return $pos === false ? null : $pos;
    }
}
