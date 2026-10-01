<?php

namespace App\Museum\Importers;

use App\Models\Museum\Category;
use App\Models\Museum\Concept;
use App\Models\Museum\Entity;
use App\Models\Museum\Sentence;
use App\Models\Museum\Source;
use App\Models\Museum\WordMeaning;
use App\Museum\Services\EntityResolver;
use App\Museum\Services\EntityService;
use App\Museum\Services\FactService;
use App\Museum\Services\SourceService;
use App\Museum\Support\TextNormalizer;
use SplFileObject;

/**
 * Verified CSV imports (UTF-8, header row) for curated datasets: words, sentences, proverbs,
 * historical names and place facts. Every row must reference a registered source
 * (source_uuid) and should give a page/locator; rows without a source are rejected.
 *
 * Columns per kind are documented in docs/museum/IMPORT_FORMATS.md.
 */
class TabularImporter
{
    public const KINDS = ['words', 'sentences', 'proverbs', 'historical_names', 'place_facts'];

    public function __construct(
        private EntityService $entities,
        private EntityResolver $resolver,
        private FactService $facts,
        private SourceService $sources,
    ) {}

    public function run(string $kind, string $path, ?int $userId = null, string $status = 'source_verified'): ImportRun
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Unknown import kind '.$kind);
        }
        $run = ImportRun::start('csv_'.$kind, ['status' => $status], null, $userId, $path);
        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);
        $header = null;
        foreach ($file as $n => $row) {
            if ($row === [null] || $row === false) {
                continue;
            }
            if ($header === null) {
                $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $row);

                continue;
            }
            $data = array_combine($header, array_pad(array_map(fn ($v) => trim((string) $v), $row), count($header), ''));
            $run->inc('documents_processed');
            try {
                $source = Source::where('uuid', $data['source_uuid'] ?? '')->first();
                if (! $source) {
                    throw new \InvalidArgumentException('row has no registered source_uuid');
                }
                $extract = ! empty($data['quote'])
                    ? $this->sources->extract($source, $data['quote'], ['page' => $data['page'] ?? null, 'locator' => $data['locator'] ?? null])
                    : null;
                $ctx = ['source' => $source, 'extract' => $extract, 'page' => $data['page'] ?? null, 'status' => $status, 'user_id' => $userId, 'run' => $run];
                $this->{'row'.str_replace('_', '', ucwords($kind, '_'))}($data, $ctx);
            } catch (\Throwable $e) {
                $run->error($e->getMessage(), ['line' => $n + 1]);
            }
        }

        return tap($run, fn ($r) => $r->finish());
    }

    private function place(?string $name, ImportRun $run): ?Entity
    {
        if (! $name) {
            return null;
        }
        $r = $this->resolver->resolve($name, ['types' => ['place']]);
        if ($r['decision'] !== EntityResolver::MATCHED) {
            throw new \InvalidArgumentException("place '$name' not resolved unambiguously (".$r['decision'].')');
        }

        return $r['entity'];
    }

    private function dialect(?string $name): ?Entity
    {
        if (! $name) {
            return null;
        }
        $r = $this->resolver->resolve($name, ['types' => ['dialect']]);

        return $r['decision'] === EntityResolver::MATCHED ? $r['entity'] : null;
    }

    private function rowWords(array $d, array $ctx): void
    {
        $run = $ctx['run'];
        if (($d['word'] ?? '') === '' || ($d['meaning_fa'] ?? '') === '') {
            throw new \InvalidArgumentException('word and meaning_fa are required');
        }
        $dialect = $this->dialect($d['dialect'] ?? null);
        $place = $this->place($d['place'] ?? null, $run);
        $concept = ! empty($d['concept']) ? Concept::firstOrCreate(['key' => TextNormalizer::slug($d['concept'])], ['gloss_fa' => $d['concept'], 'gloss_en' => $d['concept_en'] ?? $d['concept']]) : null;
        $entity = $this->entities->create('word', [
            'canonical_name' => $d['word'],
            'name_local' => $d['word'],
            'name_local_latin' => $d['transliteration'] ?? null,
            'verification_status' => $ctx['status'],
            'primary_place_id' => $place?->id,
        ], [
            'headword' => $d['word'],
            'normalized_headword' => TextNormalizer::normalize($d['word']),
            'transcription_fa' => $d['transcription_fa'] ?? null,
            'transliteration' => $d['transliteration'] ?? null,
            'ipa' => $d['ipa'] ?? null,
            'part_of_speech' => $d['part_of_speech'] ?? null,
            'dialect_id' => $dialect?->id,
            'place_id' => $place?->id,
            'concept_id' => $concept?->id,
            'etymology' => $d['etymology'] ?? null,
            'usage_notes' => $d['usage_notes'] ?? null,
        ]);
        $run->inc('entities_created');
        WordMeaning::create(['word_id' => $entity->id, 'sense_no' => 1, 'meaning_fa' => $d['meaning_fa'], 'meaning_en' => $d['meaning_en'] ?? null,
            'is_primary' => true, 'source_id' => $ctx['source']->id, 'page_number' => $ctx['page'], 'verification_status' => $ctx['status']]);
        $this->sources->cite($entity->word, $ctx['source'], ['extract' => $ctx['extract'], 'page' => $ctx['page']]);
        if ($place) {
            $this->facts->assert($entity, 'used_in', $place, ['source' => $ctx['source'], 'extract' => $ctx['extract'], 'page' => $ctx['page'], 'status' => $ctx['status'], 'method' => 'structured_import']);
            $run->inc('facts_extracted');
        }
    }

    private function rowSentences(array $d, array $ctx): void
    {
        if (($d['text_local'] ?? '') === '') {
            throw new \InvalidArgumentException('text_local is required');
        }
        $s = Sentence::create([
            'text_local' => $d['text_local'],
            'normalized_text' => TextNormalizer::normalize($d['text_local']),
            'transcription_fa' => $d['transcription_fa'] ?? null,
            'ipa' => $d['ipa'] ?? null,
            'text_fa' => $d['text_fa'] ?? null,
            'text_en' => $d['text_en'] ?? null,
            'category_id' => ! empty($d['category']) ? Category::where('scheme', 'sentence')->where('key', $d['category'])->value('id') : null,
            'dialect_id' => $this->dialect($d['dialect'] ?? null)?->id,
            'place_id' => $this->place($d['place'] ?? null, $ctx['run'])?->id,
            'verification_status' => $ctx['status'],
        ]);
        $this->sources->cite($s, $ctx['source'], ['extract' => $ctx['extract'], 'page' => $ctx['page']]);
        $ctx['run']->inc('entities_created');
    }

    private function rowProverbs(array $d, array $ctx): void
    {
        if (($d['text_local'] ?? '') === '') {
            throw new \InvalidArgumentException('text_local is required');
        }
        $place = $this->place($d['place'] ?? null, $ctx['run']);
        $e = $this->entities->create('proverb', ['canonical_name' => mb_substr($d['text_local'], 0, 200), 'verification_status' => $ctx['status'], 'primary_place_id' => $place?->id], [
            'kind' => $d['kind'] ?? 'proverb',
            'text_local' => $d['text_local'],
            'normalized_text' => TextNormalizer::normalize($d['text_local']),
            'transcription_fa' => $d['transcription_fa'] ?? null,
            'literal_meaning' => $d['literal_meaning'] ?? null,
            'figurative_meaning' => $d['figurative_meaning'] ?? null,
            'usage_context' => $d['usage_context'] ?? null,
            'backstory' => $d['backstory'] ?? null,
            'persian_equivalent' => $d['persian_equivalent'] ?? null,
            'dialect_id' => $this->dialect($d['dialect'] ?? null)?->id,
            'place_id' => $place?->id,
        ]);
        $this->sources->cite($e->proverb, $ctx['source'], ['extract' => $ctx['extract'], 'page' => $ctx['page']]);
        $ctx['run']->inc('entities_created');
    }

    private function rowHistoricalNames(array $d, array $ctx): void
    {
        $place = $this->place($d['place'] ?? null, $ctx['run']);
        if (! $place || ($d['historical_name'] ?? '') === '') {
            throw new \InvalidArgumentException('place and historical_name are required');
        }
        $this->entities->addHistoricalName($place, [
            'name' => $d['historical_name'],
            'local_pronunciation' => $d['local_pronunciation'] ?? null,
            'period_label' => $d['period'] ?? null,
            'year_from' => is_numeric($d['year_from'] ?? null) ? (int) $d['year_from'] : null,
            'year_to' => is_numeric($d['year_to'] ?? null) ? (int) $d['year_to'] : null,
            'meaning' => $d['meaning'] ?? null,
            'naming_reason' => $d['reason'] ?? null,
            'source_id' => $ctx['source']->id,
            'source_extract_id' => $ctx['extract']?->id,
            'page_number' => $ctx['page'],
            'verification_status' => $ctx['status'],
        ]);
        $ctx['run']->inc('facts_extracted');
        $ctx['run']->inc('entities_matched');
    }

    private function rowPlaceFacts(array $d, array $ctx): void
    {
        $place = $this->place($d['place'] ?? null, $ctx['run']);
        if (! $place || empty($d['property'])) {
            throw new \InvalidArgumentException('place and property are required');
        }
        $qualifiers = ! empty($d['census_year']) ? ['census_year' => (int) TextNormalizer::asciiDigits($d['census_year'])] : null;
        $fact = $this->facts->assert($place, $d['property'], ($d['value'] ?? '') === '' ? FactService::UNKNOWN : $d['value'], [
            'source' => $ctx['source'], 'extract' => $ctx['extract'], 'page' => $ctx['page'], 'status' => $ctx['status'],
            'method' => 'structured_import', 'qualifiers' => $qualifiers, 'user_id' => $ctx['user_id'],
        ]);
        $ctx['run']->inc('facts_extracted');
        if ($fact->conflict_id) {
            $ctx['run']->inc('conflicts');
        }
    }
}
