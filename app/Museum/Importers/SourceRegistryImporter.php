<?php

namespace App\Museum\Importers;

use App\Models\Museum\CrawlerSource;
use App\Models\Museum\Source;
use App\Museum\Services\SourceService;

/**
 * Imports bibliographic source records from a JSON registry file. Entries are always
 * imported as unverified with license "unknown" and crawl_policy "pending_review", unless
 * the file explicitly says otherwise; a person must review terms before any crawling.
 */
class SourceRegistryImporter
{
    private const FIELDS = ['source_type', 'title', 'title_original', 'author', 'publisher', 'publication_date', 'url',
        'container_title', 'volume', 'edition', 'isbn', 'doi', 'archive_reference', 'language', 'license',
        'copyright_status', 'reliability_tier', 'crawl_policy', 'phase', 'priority', 'topics', 'regions'];

    public function __construct(private SourceService $sources) {}

    public function run(string $path, ?int $userId = null): ImportRun
    {
        $run = ImportRun::start('source_registry', [], null, $userId, $path);
        $json = json_decode((string) @file_get_contents($path), true);
        if (! is_array($json) || ! isset($json['sources']) || ! is_array($json['sources'])) {
            $run->error('Invalid registry file: expected {"sources": [...]}');

            return tap($run, fn ($r) => $r->finish('failed'));
        }
        foreach ($json['sources'] as $i => $row) {
            $run->inc('sources_found');
            $problem = $this->validate($row);
            if ($problem) {
                $run->inc('sources_rejected');
                $run->error($problem, ['index' => $i, 'title' => $row['title'] ?? null]);

                continue;
            }
            $data = array_intersect_key($row, array_flip(self::FIELDS));
            $data += ['license' => 'unknown', 'copyright_status' => 'unknown', 'crawl_policy' => 'pending_review', 'verification_status' => 'unverified'];
            if (! empty($row['compiler_notes'])) {
                $data['metadata'] = ['compiler_notes' => $row['compiler_notes'], 'registry_file' => basename($path)];
            }
            $existed = ! empty($data['url']) && Source::where('url_hash', hash('sha256', Source::canonicalUrl($data['url'])))->exists();
            $source = $this->sources->register($data, $userId);
            $run->inc($existed ? 'entities_matched' : 'sources_accepted');

            // A disabled crawler entry is prepared for each web source; enabling it requires terms review.
            if ($source->url) {
                CrawlerSource::firstOrCreate(['source_id' => $source->id, 'kind' => 'http_document'], [
                    'name' => mb_substr($source->title, 0, 250),
                    'base_url' => $source->url,
                    'seed_urls' => [$source->url],
                    'allowed_patterns' => [rtrim(preg_replace('#^(https?://[^/]+).*$#', '$1', $source->url), '/').'/*'],
                    'enabled' => false,
                    'terms_reviewed' => false,
                    'priority' => $source->priority ?? 3,
                    'max_documents' => 200,
                ]);
            }
        }

        return tap($run, fn ($r) => $r->finish());
    }

    private function validate(array $row): ?string
    {
        if (empty($row['title'])) {
            return 'missing title';
        }
        if (empty($row['source_type']) || ! in_array($row['source_type'], Source::TYPES, true)) {
            return 'invalid source_type';
        }
        if (! empty($row['url']) && ! filter_var($row['url'], FILTER_VALIDATE_URL)
            && ! preg_match('#^https?://\S+$#u', $row['url'])) {
            return 'invalid url';
        }
        if (isset($row['reliability_tier']) && ($row['reliability_tier'] < 1 || $row['reliability_tier'] > 5)) {
            return 'reliability_tier must be 1..5';
        }

        return null;
    }
}
