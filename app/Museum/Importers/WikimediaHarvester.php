<?php

namespace App\Museum\Importers;

use App\Models\Museum\CrawlerSource;
use App\Models\Museum\Entity;
use App\Models\Museum\EntityType;
use App\Models\Museum\Media;
use App\Models\Museum\Property;
use App\Models\Museum\Source;
use App\Museum\Enums\License;
use App\Museum\Pipeline\DocumentFetcher;
use App\Museum\Pipeline\TextExtractor;
use App\Museum\Services\EntityService;
use App\Museum\Services\FactService;
use App\Museum\Services\SourceService;
use App\Museum\Services\VerificationService;
use App\Museum\Support\TextNormalizer;

/**
 * Broad, multi-domain harvest from Wikimedia projects through robots-allowed paths only:
 *
 *   fa.wikipedia.org/wiki/رده:…   category tree under «استان هرمزگان» (subcategories only; the
 *                                 /w/index.php pagination is disallowed, so very large categories
 *                                 are covered through their subcategories)
 *   fa.wikipedia.org/wiki/<page>  article: Wikidata id, revision id, lead and named sections
 *   www.wikidata.org/wiki/Special:EntityData/<Q>.json   structured facts (via the geography importer)
 *   commons.wikimedia.org/wiki/File:<name>              licence + author for each image (P18)
 *
 * Text is never paraphrased: lead and section paragraphs are stored verbatim as facts cited to the
 * exact article revision (source_type encyclopedia, reliability tier 2). Images are linked (not
 * copied) from upload.wikimedia.org and are published only when Commons shows a free licence.
 */
class WikimediaHarvester
{
    public const ROOT = 'رده:استان_هرمزگان';

    /** Category-name keyword → entity type hint (first match wins). */
    public const HINTS = [
        'آشپزی' => 'food', 'غذا' => 'food', 'خوراک' => 'food', 'نان' => 'food', 'شیرینی' => 'food',
        'استانداران' => 'person', 'اهالی' => 'person', 'شاعران' => 'person', 'نویسندگان' => 'person', 'خوانندگان' => 'person',
        'نوازندگان' => 'person', 'بازیگران' => 'person', 'ورزشکاران' => 'person', 'فوتبالیست' => 'person', 'نمایندگان' => 'person', 'زادگان' => 'person',
        'دهستان' => 'rural_district', 'روستا' => 'village', 'شهرهای' => 'city', 'بخش‌های' => 'district', 'شهرستان‌های' => 'county',
        'جزیره' => 'island', 'جزایر' => 'island', 'کوه' => 'mountain', 'رود' => 'river', 'بندر' => 'port', 'خور' => 'bay', 'تنگه' => 'bay',
        'محله' => 'neighborhood', 'مسجد' => 'building', 'قلعه' => 'historical_site', 'آثار' => 'historical_site', 'ساختمان' => 'building',
        'جاذبه' => 'historical_site', 'موزه' => 'building', 'آب‌انبار' => 'building', 'حمام' => 'building', 'بازار' => 'market',
        'موسیقی' => 'music_genre', 'رقص' => 'tradition', 'جشن' => 'tradition', 'آیین' => 'tradition', 'فرهنگ' => 'tradition',
        'گویش' => 'dialect', 'زبان' => 'dialect', 'باد' => 'natural_phenomenon', 'تاریخ' => 'historical_event', 'نبرد' => 'historical_event',
        'جنگ' => 'historical_event', 'کشتی' => 'ship', 'لنج' => 'boat_type', 'گیاه' => 'plant', 'جانور' => 'animal', 'ماهی' => 'animal',
        'طایفه' => 'social_group', 'قوم' => 'social_group', 'جغرافیا' => 'natural_feature', 'منطقه حفاظت' => 'natural_feature',
    ];

    /** Branches that are out of scope for a cultural museum or that duplicate other branches. */
    public const SKIP = ['حوزه‌های_انتخابیه', 'ورزش', 'آموزش', 'نشریه', 'ترابری', 'مقاله‌های_خرد', 'ناوبری', 'الگو', 'باشگاه', 'تیم', 'شرکت', 'مسائل_زیست‌محیطی', 'دانشگاه', 'بیمارستان'];

    /** Section headings → property keys (verbatim paragraphs). */
    public const SECTIONS = [
        'تاریخچه' => 'history_note', 'تاریخ' => 'history_note', 'پیشینه' => 'history_note',
        'وجه تسمیه' => 'etymology', 'نام‌گذاری' => 'etymology', 'ریشه نام' => 'etymology', 'ریشه‌شناسی' => 'etymology',
        'طرز تهیه' => 'preparation', 'روش تهیه' => 'preparation', 'دستور پخت' => 'preparation', 'طرز پخت' => 'preparation',
        'اقتصاد' => 'economy', 'کشاورزی' => 'agriculture', 'زندگی‌نامه' => 'biography', 'زندگینامه' => 'biography',
        'آداب و رسوم' => 'cultural_significance', 'فرهنگ' => 'cultural_significance', 'جغرافیا' => 'description',
        'معماری' => 'description', 'ویژگی‌ها' => 'description', 'کاربرد' => 'usage',
    ];

    private CrawlerSource $wiki;

    private CrawlerSource $commons;

    private Source $commonsSource;

    public function __construct(
        private DocumentFetcher $fetcher,
        private WikidataGeographyImporter $wikidata,
        private SourceService $sources,
        private FactService $facts,
        private VerificationService $verification,
        private TextExtractor $text,
    ) {}

    public function run(array $options = [], ?callable $progress = null): ImportRun
    {
        $maxDepth = (int) ($options['depth'] ?? 5);
        $limit = (int) ($options['limit'] ?? 50000);
        $this->setup();
        $run = ImportRun::start('wikimedia_harvest', $options, null, $options['user_id'] ?? null, 'fa.wikipedia '.self::ROOT);

        // ---- 1. category tree → article titles with type hints
        $articles = [];
        $queue = [[$options['root'] ?? self::ROOT, 0, null]];
        $seenCats = [];
        while ($queue && count($articles) < $limit) {
            [$cat, $depth, $hint] = array_shift($queue);
            if (isset($seenCats[$cat])) {
                continue;
            }
            $seenCats[$cat] = true;
            // Only collection-like types are inherited by subcategories (people, dishes).
            $hint = $this->hintFor($cat) ?? (in_array($hint, ['person', 'food'], true) ? $hint : null);
            $page = $this->fetchHtml('https://fa.wikipedia.org/wiki/'.rawurlencode($cat), $run);
            if (! $page) {
                continue;
            }
            [$subcats, $pages] = $this->parseCategory($page['html']);
            $run->inc('sources_found');
            foreach ($pages as $title) {
                if (! isset($articles[$title]) && ! str_starts_with($title, 'فهرست')) {
                    $articles[$title] = $hint;
                }
            }
            if ($depth < $maxDepth) {
                foreach ($subcats as $sc) {
                    if (! collect(self::SKIP)->contains(fn ($s) => str_contains($sc, $s))) {
                        $queue[] = [$sc, $depth + 1, $hint];
                    }
                }
            }
            $progress && $progress('category', $cat, count($articles));
        }
        $articles = array_slice($articles, 0, $limit, true);

        // ---- 2–4. articles in batches: fetch → structured import → verbatim text + images → publish.
        // Batching makes progress durable: an interrupted run keeps everything already imported.
        $total = 0;
        foreach (array_chunk($articles, (int) ($options['batch'] ?? 50), true) as $chunk) {
            $total += $this->processBatch($chunk, $run, $options, $progress);
            $run->flush();
        }

        return tap($run, fn ($r) => $r->finish('completed', ['categories' => count($seenCats), 'articles' => count($articles), 'items' => $total]));
    }

    private ?array $keywords = null;

    private ?array $knownQids = null;

    /**
     * Scope filter: the article text names Hormozgan or one of its counties/cities/islands, or the
     * item's location / birthplace / death place is an entity already known to be in Hormozgan.
     */
    public function isRelevant(array $meta, array $item): bool
    {
        $this->knownQids ??= array_fill_keys(Entity::whereNotNull('wikidata_id')
            ->whereIn('entity_type_id', EntityType::idsFor(['place']))->pluck('wikidata_id')->all(), true);
        foreach (['P131', 'P19', 'P20', 'P276', 'P706'] as $pid) {
            foreach ($item['claims'][$pid] ?? [] as $c) {
                if (isset($this->knownQids[$c['mainsnak']['datavalue']['value']['id'] ?? ''])) {
                    return true;
                }
            }
        }
        if ($this->keywords === null) {
            $names = Entity::whereIn('entity_type_id', EntityType::idsFor(['province', 'county', 'city', 'island']))
                ->pluck('name_fa')->filter()->map(fn ($n) => TextNormalizer::normalize(preg_replace('/^(شهرستان|استان|جزیره)\s+/u', '', $n)))
                ->filter(fn ($n) => mb_strlen($n) >= 3)->unique()->values()->all();
            $this->keywords = array_values(array_unique(array_merge(['هرمزگان'], $names)));
        }
        $text = TextNormalizer::normalize(implode(' ', array_merge($meta['lead'], ...array_values($meta['sections'] ?: [[]]))));
        foreach ($this->keywords as $kw) {
            if (preg_match('/(^|\s)'.preg_quote($kw, '/').'/u', $text)) {
                return true;
            }
        }

        return false;
    }

    public function processBatch(array $chunk, ImportRun $run, array $options, ?callable $progress): int
    {
        $items = [];
        $texts = [];
        foreach ($chunk as $title => $hint) {
            $articleUrl = 'https://fa.wikipedia.org/wiki/'.rawurlencode(str_replace(' ', '_', $title));
            if (! empty($options['resume']) && Source::where('metadata->article_url', $articleUrl)->exists()) {
                continue; // imported by an earlier (interrupted) run
            }
            $page = $this->fetchHtml('https://fa.wikipedia.org/wiki/'.rawurlencode(str_replace(' ', '_', $title)), $run);
            if (! $page) {
                continue;
            }
            $meta = $this->parseArticle($page['html']);
            if (! $meta['qid']) {
                $run->error('article without Wikidata item', ['title' => $title]);

                continue;
            }
            $entity = $this->wikidata->fetchEntity($meta['qid'], $run);
            if (! $entity) {
                continue;
            }
            if (empty($options['skip_scope']) && ! $this->isRelevant($meta, $entity)) {
                $run->inc('sources_rejected');

                continue; // category drift (e.g. Zagros peaks outside the province)
            }
            $entity['_type_hint'] = $hint;
            $entity['_wp_title'] = $meta['title'] ?? $title;
            $items[$meta['qid']] = $entity;
            $texts[$meta['qid']] = $meta + ['raw_id' => $page['raw_id'], 'url' => 'https://fa.wikipedia.org/wiki/'.rawurlencode(str_replace(' ', '_', $title))];
            $run->inc('documents_processed');
            $progress && $progress('article', $title, count($items));
        }

        // ---- 3. structured import (same provenance path as the geography importer)
        $this->wikidata->importCollected($items, $run, ['publish' => false]);

        // ---- 4. verbatim Wikipedia text + Commons images, then publication
        $this->facts->deferCounters = true;
        foreach ($texts as $qid => $meta) {
            $entity = Entity::where('wikidata_id', $qid)->first();
            if (! $entity) {
                continue;
            }
            try {
                $this->importText($entity, $meta, $run);
                foreach ($this->imageNames($items[$qid]) as $i => $file) {
                    $this->importImage($entity, $file, $i === 0 ? 'primary' : 'gallery', $run);
                }
            } catch (\Throwable $e) {
                $run->error($e->getMessage(), ['qid' => $qid]);
            }
        }
        $this->facts->flushCounters();
        $this->facts->deferCounters = false;
        foreach (array_keys($texts) as $qid) {
            $e = Entity::where('wikidata_id', $qid)->first();
            if ($e) {
                $this->verification->refreshEntityStatus($e);
                if (($options['publish'] ?? false) && ! $e->fresh()->isPublished()) {
                    $this->verification->publish($e->fresh(), $options['user_id'] ?? null);
                }
            }
        }

        return count($items);
    }

    public function setup(): void
    {
        $wpSource = $this->sources->register([
            'source_type' => 'encyclopedia', 'title' => 'ویکی‌پدیای فارسی — رده استان هرمزگان', 'publisher' => 'Wikimedia Foundation / ویرایشگران ویکی‌پدیا',
            'url' => 'https://fa.wikipedia.org/wiki/'.self::ROOT, 'language' => 'fa', 'license' => 'cc_by_sa', 'reliability_tier' => 2,
            'crawl_policy' => 'allowed', 'phase' => 'D', 'priority' => 3, 'metadata' => ['short_label' => 'ویکی‌پدیا'],
            'notes' => 'Category root used for discovery. Each article revision is registered as its own source.',
        ]);
        $this->wiki = CrawlerSource::firstOrCreate(['kind' => 'wikipedia_category', 'source_id' => $wpSource->id], [
            'name' => 'fa.wikipedia category tree (Hormozgan)', 'base_url' => 'https://fa.wikipedia.org/wiki/',
            'allowed_patterns' => ['https://fa.wikipedia.org/wiki/*'], 'terms_url' => 'https://foundation.wikimedia.org/wiki/Policy:Terms_of_Use',
            'terms_reviewed' => true, 'crawl_delay_ms' => 1000, 'max_documents' => 50000, 'enabled' => true,
            'config' => ['note' => 'Text is CC BY-SA; stored verbatim with attribution and revision id. /w/ is disallowed by robots.txt and not used.'],
        ]);
        $this->commonsSource = $this->sources->register([
            'source_type' => 'photo_archive', 'title' => 'Wikimedia Commons', 'publisher' => 'Wikimedia Foundation',
            'url' => 'https://commons.wikimedia.org/', 'license' => 'unknown', 'reliability_tier' => 3, 'crawl_policy' => 'allowed',
            'metadata' => ['short_label' => 'ویکی‌انبار'], 'notes' => 'Licence is recorded per file from its Commons description page.',
        ]);
        $this->commons = CrawlerSource::firstOrCreate(['kind' => 'commons_file', 'source_id' => $this->commonsSource->id], [
            'name' => 'Wikimedia Commons file pages', 'base_url' => 'https://commons.wikimedia.org/wiki/',
            'allowed_patterns' => ['https://commons.wikimedia.org/wiki/File:*'], 'terms_reviewed' => true, 'crawl_delay_ms' => 1000,
            'max_documents' => 50000, 'enabled' => true,
        ]);
    }

    public function fetchHtml(string $url, ImportRun $run, ?CrawlerSource $cs = null): ?array
    {
        try {
            $res = $this->fetcher->fetch($cs ?? $this->wiki, $url);
        } catch (\Throwable $e) {
            $run->error('fetch error: '.$e->getMessage(), ['url' => $url]);

            return null;
        }
        if (! in_array($res['status'], ['fetched', 'unchanged'], true) || empty($res['raw'])) {
            $run->error('fetch '.$res['status'].': '.($res['reason'] ?? ''), ['url' => $url]);

            return null;
        }

        return ['html' => $res['raw']->contents(), 'raw_id' => $res['raw']->id];
    }

    public function hintFor(string $category): ?string
    {
        $name = ' '.str_replace(['_', 'رده:'], [' ', ''], $category).' ';
        foreach (self::HINTS as $kw => $type) {
            // Whole words only (plural suffixes allowed): «آباد» must not match «باد», «رودان» not «رود».
            if (preg_match('/\s'.preg_quote($kw, '/').'(?:‌?های|‌?ها)?\s/u', $name)) {
                return $type;
            }
        }

        return null;
    }

    /** @return array{0: list<string>, 1: list<string>} subcategories, article titles */
    public function parseCategory(string $html): array
    {
        $sub = [];
        $pages = [];
        if (preg_match('/id="mw-subcategories"(.*?)(?:id="mw-pages"|id="catlinks"|<\/main>)/s', $html, $m)) {
            preg_match_all('#href="/wiki/([^"]+)"#', $m[1], $mm);
            foreach ($mm[1] as $h) {
                $t = rawurldecode($h);
                if (str_starts_with($t, 'رده:')) {
                    $sub[] = $t;
                }
            }
        }
        if (preg_match('/id="mw-pages"(.*?)(?:id="catlinks"|id="mw-category-media"|<\/main>)/s', $html, $m)) {
            preg_match_all('#<li[^>]*><a href="/wiki/([^"]+)"#', $m[1], $mm);
            foreach ($mm[1] as $h) {
                $t = str_replace('_', ' ', rawurldecode($h));
                if (! str_contains($t, ':')) {
                    $pages[] = $t;
                }
            }
        }

        return [array_values(array_unique($sub)), array_values(array_unique($pages))];
    }

    /** Wikidata id, revision, title, lead paragraph and named sections, verbatim. */
    public function parseArticle(string $html): array
    {
        preg_match('/"wgWikibaseItemId":"(Q\d+)"/', $html, $q);
        preg_match('/"wgRevisionId":(\d+)/', $html, $rev);
        preg_match('/"wgTitle":"((?:[^"\\\\]|\\\\.)*)"/', $html, $title);
        $body = preg_match('/class="[^"]*\bmw-parser-output\b[^"]*"/', $html, $pm, PREG_OFFSET_CAPTURE) ? substr($html, $pm[0][1]) : '';
        $end = strpos($body, 'class="printfooter"');
        if ($end !== false) {
            $body = substr($body, 0, $end);
        }
        // Drop tables (infoboxes, navboxes), references and style blocks before reading paragraphs.
        $body = preg_replace(['#<table.*?</table>#s', '#<sup[^>]*class="reference".*?</sup>#s', '#<style.*?</style>#s', '#<div[^>]*class="[^"]*(navbox|reflist|thumb)[^"]*".*?</div>#s'], '', $body);
        $parts = preg_split('#<h2[^>]*>(.*?)</h2>#s', $body, -1, PREG_SPLIT_DELIM_CAPTURE);
        // Footnote markers like [۴] or [12] are navigation, not content.
        $clean = fn ($h) => trim(preg_replace('/\[[0-9۰-۹]+\]/u', '', html_entity_decode(preg_replace('/\s+/u', ' ', strip_tags($h)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $paras = function (string $chunk) use ($clean) {
            preg_match_all('#<p[^>]*>(.*?)</p>#s', $chunk, $m);

            return array_values(array_filter(array_map($clean, $m[1]), fn ($t) => mb_strlen($t) >= 25));
        };
        $lead = $paras($parts[0] ?? '');
        $sections = [];
        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            $heading = trim(preg_replace('/\[.*?\]/u', '', $clean($parts[$i])));
            $ps = $paras($parts[$i + 1]);
            if ($ps) {
                $sections[$heading] = $ps;
            }
        }

        return [
            'qid' => $q[1] ?? null,
            'revision' => $rev[1] ?? null,
            'title' => isset($title[1]) ? json_decode('"'.$title[1].'"') : null,
            'lead' => $lead,
            'sections' => $sections,
        ];
    }

    public function importText(Entity $entity, array $meta, ImportRun $run): void
    {
        if (! $meta['lead'] && ! $meta['sections']) {
            return;
        }
        $permalink = 'https://fa.wikipedia.org/w/index.php?title='.rawurlencode(str_replace(' ', '_', $meta['title'] ?? '')).'&oldid='.$meta['revision'];
        $source = $this->sources->register([
            'source_type' => 'encyclopedia', 'title' => 'ویکی‌پدیا: '.($meta['title'] ?? $entity->canonical_name),
            'publisher' => 'ویکی‌پدیای فارسی', 'url' => $permalink, 'language' => 'fa', 'license' => 'cc_by_sa',
            'copyright_status' => 'cc_by_sa_attribution_required', 'reliability_tier' => 2, 'crawl_policy' => 'allowed',
            'retrieved_at' => now(), 'metadata' => ['short_label' => 'ویکی‌پدیا', 'revision' => $meta['revision'], 'article_url' => $meta['url']],
        ]);
        $type = $entity->type->key;
        $assert = function (string $prop, string $text, string $locator) use ($entity, $source, $meta, $run, $type) {
            $property = Property::byKey($prop);
            if (! $property->appliesTo($type)) {
                $property = Property::byKey('description');
            }
            $text = mb_substr($text, 0, 6000);
            $extract = $this->sources->extract($source, $text, ['locator' => $locator, 'raw_document_id' => $meta['raw_id'], 'extracted_by' => 'import', 'language' => 'fa']);
            $this->facts->assert($entity, $property, $text, ['source' => $source, 'extract' => $extract, 'locator' => $locator,
                'status' => 'source_verified', 'method' => 'structured_import', 'confidence' => 1.0, 'language' => 'fa', 'import_batch_id' => $run->batch->id]);
            $run->inc('facts_extracted');
        };
        if ($meta['lead']) {
            $assert($type === 'person' ? 'biography' : 'description', implode("\n\n", array_slice($meta['lead'], 0, 3)), 'lead@rev'.$meta['revision']);
        }
        foreach ($meta['sections'] as $heading => $paras) {
            foreach (self::SECTIONS as $kw => $prop) {
                if (str_contains($heading, $kw)) {
                    $assert($prop, implode("\n\n", array_slice($paras, 0, 4)), '§'.$heading.'@rev'.$meta['revision']);
                    break;
                }
            }
        }
        if ($meta['title'] && TextNormalizer::normalize($meta['title']) !== $entity->normalized_name) {
            app(EntityService::class)->addAlias($entity, $meta['title'], ['type' => 'spelling_variant', 'language' => 'fa', 'source_id' => $source->id]);
        }
    }

    private function imageNames(array $item): array
    {
        $out = [];
        foreach ($item['claims']['P18'] ?? [] as $c) {
            if (($c['rank'] ?? '') !== 'deprecated' && is_string($v = $c['mainsnak']['datavalue']['value'] ?? null)) {
                $out[] = $v;
            }
        }

        return array_slice(array_values(array_unique($out)), 0, 3);
    }

    /** Links a Commons image after reading its licence and author from the file page. */
    public function importImage(Entity $entity, string $file, string $role, ImportRun $run): ?Media
    {
        $name = str_replace(' ', '_', $file);
        $pageUrl = 'https://commons.wikimedia.org/wiki/File:'.rawurlencode($name);
        $existing = Media::where('disk', 'remote')->where('rights_statement', 'like', '%'.$pageUrl.'%')->first();
        if (! $existing) {
            $page = $this->fetchHtml($pageUrl, $run, $this->commons);
            if (! $page) {
                return null;
            }
            $info = $this->parseCommons($page['html']);
            $md5 = md5($name);
            $original = 'https://upload.wikimedia.org/wikipedia/commons/'.$md5[0].'/'.substr($md5, 0, 2).'/'.rawurlencode($name);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $thumb = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff'], true)
                ? 'https://upload.wikimedia.org/wikipedia/commons/thumb/'.$md5[0].'/'.substr($md5, 0, 2).'/'.rawurlencode($name).'/500px-'.rawurlencode($name).(in_array($ext, ['tif', 'tiff'], true) ? '.jpg' : '')
                : null;
            $existing = Media::create([
                'media_type' => 'image', 'disk' => 'remote', 'path' => $original, 'original_filename' => $name,
                'mime_type' => null, 'title' => pathinfo(str_replace('_', ' ', $file), PATHINFO_FILENAME), 'description' => $info['description'],
                'creator' => $info['author'], 'year' => $info['year'], 'year_precision' => $info['year'] ? 'exact' : null,
                'license' => $info['license'], 'copyright_status' => $info['license_text'] ?? 'unknown',
                'rights_statement' => 'Wikimedia Commons: '.$pageUrl.($info['license_text'] ? ' — '.$info['license_text'] : ''),
                'source_id' => $this->commonsSource->id, 'processing_status' => 'processed',
                'variants' => $thumb ? ['thumb' => $thumb] : null,
                'verification_status' => 'source_verified',
                'visibility' => License::from($info['license'])->allowsPublicDisplay() ? 'published' : 'draft',
            ]);
        }
        $entity->media()->syncWithoutDetaching([$existing->id => ['role' => $role]]);

        return $existing;
    }

    /** Licence, author, date and description from a Commons file page (machine-readable templates). */
    public function parseCommons(string $html): array
    {
        $short = null;
        if (preg_match('#class="licensetpl_short"[^>]*>(.*?)</span>#s', $html, $m)) {
            $short = trim(strip_tags($m[1]));
        }
        $url = preg_match('#<link rel="license" href="([^"]+)"#', $html, $lm) ? $lm[1] : null;
        $license = 'unknown';
        if ($url) {
            $license = match (true) {
                str_contains($url, '/publicdomain/zero/') => 'cc0',
                str_contains($url, '/publicdomain/') => 'public_domain',
                str_contains($url, '/by-nc-sa/') => 'cc_by_nc_sa',
                str_contains($url, '/by-nc-nd/') => 'cc_by_nc_nd',
                str_contains($url, '/by-nc/') => 'cc_by_nc',
                str_contains($url, '/by-nd/') => 'cc_by_nd',
                str_contains($url, '/by-sa/') => 'cc_by_sa',
                str_contains($url, '/by/') => 'cc_by',
                default => 'unknown',
            };
            $short ??= strtoupper(str_replace(['https://creativecommons.org/licenses/', 'https://creativecommons.org/publicdomain/'], ['CC ', ''], rtrim($url, '/')));
        } elseif ($short) {
            $license = match (true) {
                (bool) preg_match('/^CC0/i', $short) => 'cc0',
                (bool) preg_match('/public domain/i', $short) => 'public_domain',
                (bool) preg_match('/^CC BY-SA/i', $short) => 'cc_by_sa',
                (bool) preg_match('/^CC BY-NC/i', $short) => 'cc_by_nc',
                (bool) preg_match('/^CC BY/i', $short) => 'cc_by',
                default => 'unknown',
            };
        }
        $text = $short;
        $cell = function (string $id) use ($html) {
            $id = str_replace('_', '(?:_|&#95;)', $id);
            if (preg_match('~id="'.$id.'"[^>]*>.*?</td>\s*<td[^>]*>(.*?)</td>~s', $html, $m)) {
                return trim(html_entity_decode(preg_replace('/\s+/u', ' ', strip_tags($m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }

            return null;
        };
        $date = $cell('fileinfotpl_date');
        $year = $date && preg_match('/\b(1[6-9]\d\d|20\d\d)\b/', $date, $y) ? (int) $y[1] : null;

        return [
            'license' => $license,
            'license_text' => $text ?? $url,
            'author' => $cell('fileinfotpl_aut') ? mb_substr($cell('fileinfotpl_aut'), 0, 250) : null,
            'description' => $cell('fileinfotpl_desc') ? mb_substr($cell('fileinfotpl_desc'), 0, 2000) : null,
            'year' => $year,
        ];
    }
}
