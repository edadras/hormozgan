<?php

namespace App\Museum\Importers;

use App\Models\Museum\CrawlerSource;
use App\Models\Museum\Entity;
use App\Models\Museum\Fact;
use App\Museum\Pipeline\DocumentFetcher;

/**
 * Image galleries from Wikimedia Commons categories (Wikidata P373, imported as `commonscat`).
 * For each published entity: up to N images from its category, plus older photographs from
 * history-related subcategories. Every file's licence/author/date is read from its own file
 * page; files without a free licence are kept unpublished.
 */
class CommonsGalleryHarvester
{
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'tif', 'tiff'];

    public function __construct(private WikimediaHarvester $harvester, private DocumentFetcher $fetcher) {}

    public function run(array $options = [], ?callable $progress = null): ImportRun
    {
        $perEntity = (int) ($options['per_entity'] ?? 6);
        $this->harvester->setup();
        $cs = CrawlerSource::where('kind', 'commons_file')->firstOrFail();
        $cs->allowed_patterns = ['https://commons.wikimedia.org/wiki/File:*', 'https://commons.wikimedia.org/wiki/Category:*'];
        $cs->save();
        $run = ImportRun::start('commons_galleries', $options);

        $facts = Fact::whereHas('property', fn ($q) => $q->where('key', 'commonscat'))
            ->whereIn('entity_id', Entity::published()->select('id'))->with('entity')->get();
        foreach ($facts as $i => $fact) {
            $entity = $fact->entity;
            $have = $entity->media()->where('media_type', 'image')->count();
            if ($have >= $perEntity) {
                continue;
            }
            try {
                [$files, $subcats] = $this->category($cs, $fact->value_text, $run);
                // Older photographs first: history / old photographs subcategories.
                foreach ($subcats as $sc) {
                    if (preg_match('/history|old photograph|1[89]\d\ds|century/i', $sc)) {
                        [$old] = $this->category($cs, substr($sc, 9), $run);
                        $files = array_merge(array_slice($old, 0, 3), $files);
                    }
                }
                foreach (array_slice(array_values(array_unique($files)), 0, $perEntity - $have) as $file) {
                    $m = $this->harvester->importImage($entity, $file, $have === 0 ? 'primary' : 'gallery', $run);
                    if ($m) {
                        $m->place_id ??= $entity->place ? $entity->id : $entity->primary_place_id;
                        $m->save();
                        $have++;
                        $run->inc('documents_processed');
                    }
                }
            } catch (\Throwable $e) {
                $run->error($e->getMessage(), ['entity' => $entity->slug]);
            }
            $progress && $progress($i + 1, count($facts), $entity->canonical_name);
            $run->flush();
        }

        return tap($run, fn ($r) => $r->finish());
    }

    /** @return array{0: list<string>, 1: list<string>} image file names, subcategory titles */
    private function category(CrawlerSource $cs, string $name, ImportRun $run): array
    {
        $res = $this->fetcher->fetch($cs, 'https://commons.wikimedia.org/wiki/Category:'.rawurlencode(str_replace(' ', '_', $name)));
        if (empty($res['raw'])) {
            $run->error('category '.$res['status'].' '.($res['reason'] ?? ''), ['category' => $name]);

            return [[], []];
        }
        $html = $res['raw']->contents();
        $files = [];
        if (preg_match('/id="mw-category-media"(.*?)(?:id="catlinks"|<\/main>)/s', $html, $m)) {
            preg_match_all('#href="/wiki/File:([^"]+)"#', $m[1], $mm);
            foreach (array_unique($mm[1]) as $f) {
                $f = rawurldecode($f);
                if (in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), self::IMAGE_EXT, true)) {
                    $files[] = $f;
                }
            }
        }
        $subs = [];
        if (preg_match('/id="mw-subcategories"(.*?)(?:id="mw-pages"|id="mw-category-media"|id="catlinks")/s', $html, $m)) {
            preg_match_all('#href="/wiki/(Category:[^"]+)"#', $m[1], $mm);
            $subs = array_values(array_unique(array_map(fn ($c) => str_replace('_', ' ', rawurldecode($c)), $mm[1])));
        }

        return [$files, $subs];
    }
}
