<?php

namespace App\Museum\Importers;

use App\Models\Museum\Concept;
use App\Models\Museum\CrawlerSource;
use App\Models\Museum\Entity;
use App\Models\Museum\FactSource;
use App\Models\Museum\GrammarRule;
use App\Models\Museum\Sentence;
use App\Models\Museum\Source;
use App\Models\Museum\Word;
use App\Models\Museum\WordMeaning;
use App\Museum\Search\SearchIndexer;
use App\Museum\Services\EntityService;
use App\Museum\Services\SourceService;
use App\Museum\Services\VerificationService;
use App\Museum\Support\TextNormalizer;

/**
 * Language atlas data from the Wikipedia articles about the languages/dialects of Hormozgan.
 *
 * Only what the articles literally contain is imported, cited to the article revision:
 *  - the dialect entities themselves (Wikidata + verbatim article text, via WikimediaHarvester)
 *  - glossary tables (dialect ↔ standard Persian / English) → words with meanings, concepts
 *  - multi-dialect comparison tables → words per dialect column (feeds dialect comparison) and
 *    the full table as a grammar paradigm
 *  - phoneme and pronoun tables → grammar rules (phonology / pronouns) with the table as paradigm
 *  - "A = B" lists → words or example sentences; the reading direction of each list was checked
 *    by hand and is fixed in PAIR_RULES so that word and meaning are never swapped by guessing
 */
class LanguageHarvester
{
    /** fa.wikipedia article => dialect it documents. */
    public const ARTICLES = [
        'گویش_بندری' => 'bandari', 'زبان_کمزاری' => 'kumzari', 'زبان_بشکردی' => 'bashkardi', 'گویش_رودانی' => 'rudani',
        'زبان_لارستانی' => 'larestani', 'زبان_اچمی' => 'achomi', 'زبان_بلوچی' => 'baluchi', 'گویش_بستکی' => 'bastaki',
    ];

    /** Additional English articles parsed for tables only. */
    public const EN_ARTICLES = ['Kumzari_language' => 'kumzari'];

    /** Column / label keywords → dialect key (only Hormozgan dialects are imported from comparison tables). */
    public const DIALECT_KEYWORDS = [
        'بندرعباسی' => 'bandari', 'بندری' => 'bandari', 'لارستانی' => 'larestani', 'اچمی' => 'achomi', 'کمزاری' => 'kumzari',
        'لارکی' => 'kumzari', 'Kumzari' => 'kumzari', 'رودانی' => 'rudani', 'بشکردی' => 'bashkardi', 'بستکی' => 'bastaki', 'بلوچی' => 'baluchi',
    ];

    /** Columns that hold the gloss (meaning) rather than a dialect form. */
    public const GLOSS_COLUMNS = ['فارسی معیار' => 'fa', 'فارسی' => 'fa', 'New Persian (Farsi)' => 'fa', 'English' => 'en'];

    /**
     * Hand-checked "A = B" lists: [article, text marker that precedes the list, direction, kind].
     * direction: persian_first = "Persian = dialect"; local_first = "dialect = Persian".
     */
    public const PAIR_RULES = [
        ['گویش_رودانی', 'معادل کلمات', 'persian_first', 'word'],
        ['زبان_کمزاری', 'مانند:', 'persian_first', 'word'],
        ['زبان_کمزاری', 'چند جملهٔ کوتاه', 'local_first', 'sentence'],
    ];

    private array $dialects = [];

    private array $stats = ['words' => 0, 'sentences' => 0, 'grammar_rules' => 0, 'skipped_columns' => 0];

    public function __construct(
        private WikimediaHarvester $wiki,
        private EntityService $entities,
        private SourceService $sources,
        private VerificationService $verification,
    ) {}

    public function run(array $options = []): ImportRun
    {
        $this->wiki->setup();
        $cs = CrawlerSource::where('kind', 'wikipedia_category')->firstOrFail();
        $cs->allowed_patterns = array_values(array_unique(array_merge($cs->allowed_patterns ?? [], ['https://en.wikipedia.org/wiki/*'])));
        $cs->save();
        $this->wiki->setup(); // reload the crawler record with the en.wikipedia pattern
        $run = ImportRun::start('language_atlas', $options, null, $options['user_id'] ?? null, 'Wikipedia dialect articles');

        // 1. dialect entities with their verbatim descriptions (scope filter applies).
        $titles = [];
        foreach (array_keys(self::ARTICLES) as $t) {
            $titles[str_replace('_', ' ', $t)] = 'dialect';
        }
        // Hand-picked articles about the languages of Hormozgan: the keyword scope filter is not needed.
        $this->wiki->processBatch($titles, $run, ['publish' => true, 'skip_scope' => true], null);

        // Articles without a Wikidata item (e.g. «گویش رودانی»): create the dialect entry from the article itself.
        foreach (self::ARTICLES as $title => $key) {
            if ($this->dialect($key, ['article' => $title])) {
                continue;
            }
            $page = $this->wiki->fetchHtml('https://fa.wikipedia.org/wiki/'.rawurlencode($title), $run);
            if (! $page) {
                continue;
            }
            $meta = $this->wiki->parseArticle($page['html']);
            if ($meta['qid'] || ! $meta['lead']) {
                continue;
            }
            $entity = $this->entities->create('dialect', ['canonical_name' => $meta['title'] ?? str_replace('_', ' ', $title), 'name_fa' => $meta['title']], ['level' => 'dialect']);
            $this->wiki->importText($entity, $meta + ['raw_id' => $page['raw_id'], 'url' => 'https://fa.wikipedia.org/wiki/'.rawurlencode($title)], $run);
            $this->verification->publish($entity->fresh(), null);
            unset($this->dialects[$key]);
            $run->inc('entities_created');
        }

        // 2. structured language data from each article.
        foreach (self::ARTICLES as $title => $key) {
            $this->article('https://fa.wikipedia.org/wiki/'.rawurlencode($title), $title, $key, 'fa', $run);
        }
        foreach (self::EN_ARTICLES as $title => $key) {
            $this->article('https://en.wikipedia.org/wiki/'.rawurlencode($title), $title, $key, 'en', $run);
        }

        return tap($run, fn ($r) => $r->finish('completed', $this->stats + ['dialects' => array_keys(array_filter($this->dialects))]));
    }

    private function article(string $url, string $title, string $key, string $lang, ImportRun $run): void
    {
        $page = $this->wiki->fetchHtml($url, $run);
        if (! $page) {
            return;
        }
        $html = $page['html'];
        preg_match('/"wgRevisionId":(\d+)/', $html, $rev);
        $revision = $rev[1] ?? null;
        $label = str_replace('_', ' ', $title);
        $source = $this->sources->register([
            'source_type' => 'encyclopedia', 'title' => ($lang === 'fa' ? 'ویکی‌پدیا: ' : 'Wikipedia: ').$label,
            'publisher' => $lang === 'fa' ? 'ویکی‌پدیای فارسی' : 'English Wikipedia',
            'url' => 'https://'.$lang.'.wikipedia.org/w/index.php?title='.rawurlencode($title).'&oldid='.$revision,
            'language' => $lang, 'license' => 'cc_by_sa', 'reliability_tier' => 2, 'crawl_policy' => 'allowed', 'retrieved_at' => now(),
            'metadata' => ['short_label' => $lang === 'fa' ? 'ویکی‌پدیا' : 'Wikipedia (en)', 'revision' => $revision, 'article_url' => $url],
        ]);
        $ctx = ['source' => $source, 'raw_id' => $page['raw_id'], 'revision' => $revision, 'article' => $title, 'dialect' => $key, 'lang' => $lang, 'run' => $run];

        $body = preg_match('/class="[^"]*\bmw-parser-output\b[^"]*"/', $html, $m, PREG_OFFSET_CAPTURE) ? substr($html, $m[0][1]) : $html;
        $body = preg_replace('#<style.*?</style>#s', '', $body);
        $end = strpos($body, 'class="printfooter"');
        $body = $end !== false ? substr($body, 0, $end) : $body;

        // Tables with the nearest preceding heading.
        preg_match_all('#<h[234][^>]*>(.*?)</h[234]>|<table(?![^>]*(?:infobox|navbox|metadata|ambox|sidebar))[^>]*>.*?</table>#s', $body, $all, PREG_SET_ORDER);
        $heading = $label;
        $n = 0;
        foreach ($all as $a) {
            if (! empty($a[1])) {
                $heading = $this->clean($a[1]);

                continue;
            }
            $this->table($this->rows($a[0]), $heading, ++$n, $ctx);
        }
        // Hand-checked "A = B" lists.
        foreach (self::PAIR_RULES as [$art, $marker, $direction, $kind]) {
            if ($art === $title) {
                $this->pairs($body, $marker, $direction, $kind, $ctx);
            }
        }
    }

    // ------------------------------------------------------------------ tables

    private function table(array $rows, string $heading, int $n, array $ctx): void
    {
        if (count($rows) < 2) {
            return;
        }
        $header = $rows[0];
        $flat = implode(' ', $header);
        $locator = 'table '.$n.' («'.$heading.'»)@rev'.$ctx['revision'];

        if (preg_match('/دولبی|لبی|لثوی|Labial|Alveolar/u', $flat)) {
            $this->grammar('phonology', $heading ?: 'همخوان‌ها', $rows, $locator, $ctx);

            return;
        }
        if (preg_match('/پیشین|Front/u', $flat) && preg_match('/پسین|Back/u', $flat)) {
            $this->grammar('phonology', $heading ?: 'واکه‌ها', $rows, $locator, $ctx);

            return;
        }
        if (in_array('مفرد', $header, true) && in_array('جمع', $header, true)) {
            $this->grammar('pronouns', $heading, $rows, $locator, $ctx);
            $this->pronounWords($rows, $locator, $ctx);

            return;
        }
        // Glossary / comparison: one gloss column + one or more dialect columns.
        $glossCol = null;
        $glossLang = null;
        $dialectCols = [];
        foreach ($header as $i => $h) {
            if ($glossCol === null && isset(self::GLOSS_COLUMNS[$h])) {
                $glossCol = $i;
                $glossLang = self::GLOSS_COLUMNS[$h];

                continue;
            }
            $d = $this->dialectFromLabel($h);
            if ($d) {
                $dialectCols[$i] = $d;
            } elseif (! isset(self::GLOSS_COLUMNS[$h])) {
                $this->stats['skipped_columns']++;
            }
        }
        if ($glossCol === null || ! $dialectCols) {
            return;
        }
        if (count($header) > 2) {
            $this->grammar('conjugation', $heading, $rows, $locator, $ctx);
        }
        foreach (array_slice($rows, 1) as $r => $row) {
            $gloss = $row[$glossCol] ?? '';
            if ($gloss === '') {
                continue;
            }
            foreach ($dialectCols as $i => $dialectKey) {
                $form = $row[$i] ?? '';
                if ($form === '') {
                    continue;
                }
                $this->lexical($form, $gloss, $glossLang, $dialectKey, $locator.' row '.($r + 2), $ctx);
            }
        }
    }

    private function pronounWords(array $rows, string $locator, array $ctx): void
    {
        $header = $rows[0];
        foreach (array_slice($rows, 1) as $row) {
            $person = $row[0] ?? '';
            foreach ($header as $i => $number) {
                if ($i === 0 || ! in_array($number, ['مفرد', 'جمع'], true) || empty($row[$i])) {
                    continue;
                }
                $this->lexical($row[$i], 'ضمیر '.$person.' '.$number, 'fa', $ctx['dialect'], $locator, $ctx, 'pronoun');
            }
        }
    }

    // ------------------------------------------------------------------ "A = B" lists

    private function pairs(string $body, string $marker, string $direction, string $kind, array $ctx): void
    {
        // Prefer the section heading with this id; otherwise the first occurrence in running text.
        $fromHeading = (bool) preg_match('#<h[23][^>]*id="'.preg_quote(str_replace(' ', '_', $marker), '#').'"#u', $body, $hm, PREG_OFFSET_CAPTURE);
        $anchor = $fromHeading ? $hm[0][1] + strlen($hm[0][0]) : strpos($body, $marker);
        if ($anchor === false) {
            $ctx['run']->error('pair marker not found', ['article' => $ctx['article'], 'marker' => $marker]);

            return;
        }
        $chunk = substr($body, $anchor);
        if (preg_match('#<h[23]#', $chunk, $nm, PREG_OFFSET_CAPTURE)) {
            $chunk = substr($chunk, 0, $nm[0][1]);
        }
        $locator = 'list «'.$marker.'»@rev'.$ctx['revision'];
        // One element (paragraph / list item / line) at a time.
        $elements = preg_split('#<(?:br|/p|/li|/dd|/div)[^>]*>#', $chunk);
        if (! $fromHeading && $kind === 'word') {
            // A word list introduced in running text («… مانند: خانه = خانوغو، …») is only that one paragraph;
            // what follows may be a different list (e.g. sentences in the other direction).
            $elements = [preg_replace('#<br[^>]*>#', ' ', explode('</p>', $chunk)[0])];
        }
        foreach ($elements as $el) {
            $t = $this->clean($el);
            if (! str_contains($t, '=')) {
                continue;
            }
            if (($p = mb_strpos($t, $marker)) !== false) {
                $t = trim(mb_substr($t, $p + mb_strlen($marker)));
            }
            if ($kind === 'sentence') {
                if (substr_count($t, '=') === 1) {
                    [$a, $b] = array_map('trim', explode('=', $t));
                    [$local, $fa] = $direction === 'local_first' ? [$a, $b] : [$b, $a];
                    if ($local !== '' && $fa !== '') {
                        $this->sentence($local, $fa, $ctx['dialect'], $locator, $ctx);
                    }
                }

                continue;
            }
            foreach ($this->splitPairs($t) as [$a, $b]) {
                [$fa, $local] = $direction === 'persian_first' ? [$a, $b] : [$b, $a];
                if ($fa !== '' && $local !== '' && mb_strlen($local) <= 40) {
                    $this->lexical($local, $fa, 'fa', $ctx['dialect'], $locator, $ctx);
                }
            }
        }
    }

    /**
     * «آب=هو سبز=سوز … قلقلک=نخ و شاکی لباس=جومَه و غیره» → [[آب,هو],[سبز,سوز],…,[قلقلک,نخ و شاکی],[لباس,جومَه]]
     * The last token of each middle segment is the next key, so multi-word values survive.
     */
    public function splitPairs(string $text): array
    {
        $text = trim(preg_replace(['/\*+/u', '/\s+\/\s+/u', '/\s+و غیره\s*\.?\s*$/u', '/\s+/u'], ['', ' ', '', ' '], $text));
        $segments = array_map('trim', explode('=', $text));
        $pairs = [];
        if (count($segments) < 2) {
            return $pairs;
        }
        $tok = preg_split('/[\s،,:]+/u', $segments[0]);
        $key = (string) end($tok);
        for ($i = 1; $i < count($segments); $i++) {
            $tokens = preg_split('/[\s،,]+/u', $segments[$i]);
            $next = $i < count($segments) - 1 ? array_pop($tokens) : null;
            $value = preg_replace('/^[\s،,.]+|[\s،,.]+$/u', '', implode(' ', $tokens)); // multibyte-safe trim
            if ($key !== '' && $value !== '') {
                $pairs[] = [$key, $value];
            }
            $key = (string) $next;
        }

        return $pairs;
    }

    // ------------------------------------------------------------------ writers

    private function lexical(string $form, string $gloss, string $glossLang, string $dialectKey, string $locator, array $ctx, ?string $pos = null): void
    {
        // «اُندِه/اُندِن»: alternative forms given in one cell are stored as separate variants.
        if (str_contains($form, '/') && ! preg_match('/\p{Latin}/u', $form)) {
            foreach (array_filter(array_map('trim', explode('/', $form))) as $variant) {
                $this->lexical($variant, $gloss, $glossLang, $dialectKey, $locator, $ctx, $pos);
            }

            return;
        }
        [$script, $latin] = $this->splitScript($form);
        if ($script === '' && $latin === '') {
            return;
        }
        $headword = $script !== '' ? $script : $latin;
        $words = fn ($x) => count(preg_split('/\s+/u', trim($x)));
        $isSentence = preg_match('/[؟?!.]$/u', trim($headword)) || preg_match('/[؟?!.]$/u', trim($gloss))
            || ($words($headword) >= 3 && $words($gloss) >= 2);
        if (! $pos && $isSentence) {
            $this->sentence($script !== '' ? $script : $latin, $gloss, $dialectKey, $locator, $ctx, $glossLang);

            return;
        }
        $dialect = $this->dialect($dialectKey, $ctx);
        if (! $dialect) {
            return; // never store a form without knowing its dialect
        }
        $norm = TextNormalizer::normalize($headword);
        $existing = Word::where('normalized_headword', $norm)->where('dialect_id', $dialect?->id)->first();
        $quote = $form.' — '.$gloss;
        $extract = $this->sources->extract($ctx['source'], $quote, ['locator' => $locator, 'raw_document_id' => $ctx['raw_id'], 'extracted_by' => 'import', 'language' => $ctx['lang']]);
        if ($existing) {
            $word = $existing;
        } else {
            $glossFa = $glossLang === 'fa' ? $gloss : null;
            $concept = $this->concept($gloss, $glossLang);
            $entity = $this->entities->create('word', [
                'canonical_name' => $headword, 'name_local' => $headword, 'name_local_latin' => $latin ?: null,
                'verification_status' => 'source_verified',
            ], [
                'headword' => $headword, 'normalized_headword' => $norm, 'transliteration' => $latin ?: null,
                'ipa' => $this->looksIpa($latin) ? $latin : null, 'part_of_speech' => $pos, 'dialect_id' => $dialect?->id, 'concept_id' => $concept?->id,
            ]);
            $word = Word::find($entity->id);
            WordMeaning::create(['word_id' => $word->entity_id, 'sense_no' => 1, 'meaning_fa' => $glossFa ?? $gloss, 'meaning_en' => $glossLang === 'en' ? $gloss : null,
                'is_primary' => true, 'source_id' => $ctx['source']->id, 'verification_status' => 'source_verified']);
            $this->verification->publish($entity->fresh(), null, true, 'Lexical entry copied verbatim from a cited source table/list ('.$locator.')');
            $this->stats['words']++;
            $ctx['run']->inc('entities_created');
        }
        $this->sources->cite($word, $ctx['source'], ['extract' => $extract, 'locator' => $locator, 'quote' => $quote]);
    }

    private function sentence(string $local, string $gloss, string $dialectKey, string $locator, array $ctx, string $glossLang = 'fa'): void
    {
        $dialect = $this->dialect($dialectKey, $ctx);
        if (! $dialect) {
            return;
        }
        $norm = TextNormalizer::normalize($local);
        $s = Sentence::firstOrCreate(['normalized_text' => $norm, 'dialect_id' => $dialect?->id], [
            'text_local' => $local, 'text_fa' => $glossLang === 'fa' ? $gloss : null, 'text_en' => $glossLang === 'en' ? $gloss : null,
            'verification_status' => 'source_verified', 'visibility' => 'published',
        ]);
        $extract = $this->sources->extract($ctx['source'], $local.' = '.$gloss, ['locator' => $locator, 'raw_document_id' => $ctx['raw_id'], 'extracted_by' => 'import']);
        $this->sources->cite($s, $ctx['source'], ['extract' => $extract, 'locator' => $locator]);
        if ($s->wasRecentlyCreated) {
            $this->stats['sentences']++;
        }
        app(SearchIndexer::class)->indexSentence($s);
    }

    private function grammar(string $topic, string $title, array $rows, string $locator, array $ctx): void
    {
        $dialect = $this->dialect($ctx['dialect'], $ctx);
        if (! $dialect) {
            return;
        }
        $rule = GrammarRule::firstOrCreate(['dialect_id' => $dialect->id, 'topic' => $topic, 'title' => $title], [
            'description' => 'جدول «'.$title.'» در مقالهٔ «'.str_replace('_', ' ', $ctx['article']).'» (نقل عین جدول؛ بدون تفسیر).',
            'paradigm' => $rows, 'verification_status' => 'source_verified', 'visibility' => 'published',
        ]);
        $extract = $this->sources->extract($ctx['source'], json_encode($rows, JSON_UNESCAPED_UNICODE), ['locator' => $locator, 'raw_document_id' => $ctx['raw_id'], 'extracted_by' => 'import']);
        $this->sources->cite($rule, $ctx['source'], ['extract' => $extract, 'locator' => $locator]);
        if ($rule->wasRecentlyCreated) {
            $this->stats['grammar_rules']++;
        }
    }

    // ------------------------------------------------------------------ helpers

    private function dialect(string $key, array $ctx): ?Entity
    {
        if (array_key_exists($key, $this->dialects)) {
            return $this->dialects[$key];
        }
        $title = array_search($key, self::ARTICLES, true);
        $source = $title ? Source::where('title', 'ویکی‌پدیا: '.str_replace('_', ' ', $title))->latest('id')->first() : null;
        $entity = null;
        if ($source) {
            $factEntity = FactSource::where('source_id', $source->id)->with('fact.entity')->first()?->fact?->entity;
            $entity = $factEntity && $factEntity->type->key === 'dialect' ? $factEntity : null;
        }

        return $this->dialects[$key] = $entity;
    }

    private function dialectFromLabel(string $label): ?string
    {
        foreach (self::DIALECT_KEYWORDS as $kw => $key) {
            if (str_contains($label, $kw)) {
                return $key;
            }
        }

        return null;
    }

    private function concept(string $gloss, string $lang): ?Concept
    {
        $g = trim(preg_replace('/[؟?.!]+$/u', '', $gloss));
        if ($g === '' || mb_strlen($g) > 40) {
            return null;
        }
        $key = mb_substr(TextNormalizer::slug($lang.'-'.$g), 0, 96);

        return Concept::firstOrCreate(['key' => $key], ['gloss_fa' => $lang === 'fa' ? $g : '', 'gloss_en' => $lang === 'en' ? $g : '']);
    }

    /** "مَی‌اَم mæɪ æm" or "كم\nkam" → [Perso-Arabic part, Latin/IPA part]. */
    private function splitScript(string $form): array
    {
        $form = trim(preg_replace('/\[[^\]]*\]/u', '', $form));
        preg_match_all('/[\p{Arabic}\x{200C}\s]+/u', $form, $ar);
        preg_match_all('/[\p{Latin}ːˠʔʁħɦʃʒɪʊæɑəɛɔ][\p{Latin}ːˠʔʁħɦʃʒɪʊæɑəɛɔ\x{0300}-\x{036F}\-\s:]*/u', $form, $la);
        $script = trim(preg_replace('/\s+/u', ' ', implode(' ', array_filter(array_map('trim', $ar[0])))));
        $latin = trim(preg_replace('/\s+/u', ' ', implode(' ', array_filter(array_map('trim', $la[0])))));

        return [$script, $latin];
    }

    private function looksIpa(string $s): bool
    {
        return (bool) preg_match('/[ːˠʔʁħɦʃʒɪʊæɑə]/u', $s);
    }

    private function rows(string $table): array
    {
        $rows = [];
        preg_match_all('#<tr.*?</tr>#s', $table, $trs);
        foreach ($trs[0] as $tr) {
            preg_match_all('#<t[hd][^>]*>(.*?)</t[hd]>#s', $tr, $cells);
            $row = array_map(fn ($c) => $this->clean(preg_replace('#<br\s*/?>#', "\n", $c), true), $cells[1]);
            if (array_filter($row, fn ($c) => $c !== '')) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function clean(string $h, bool $keepNewlines = false): string
    {
        $t = html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace('/\[(?:[0-9۰-۹]+|[a-z]|ویرایش|نیازمند منبع)\]/u', '', $t);
        $t = $keepNewlines ? preg_replace('/[ \t]+/u', ' ', $t) : preg_replace('/\s+/u', ' ', $t);

        return trim($t);
    }
}
