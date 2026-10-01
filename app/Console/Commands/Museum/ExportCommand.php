<?php

namespace App\Console\Commands\Museum;

use App\Models\Museum\Entity;
use App\Models\Museum\Fact;
use Illuminate\Console\Command;

/**
 * Research export: one JSON line per entity with names, aliases, historical names and every
 * fact with its full provenance (source, page/locator, quote, status, revision). Suitable for
 * researchers and as a reproducible dataset snapshot.
 */
class ExportCommand extends Command
{
    protected $signature = 'museum:export {path : output .jsonl or .jsonl.gz} {--all : include unpublished entities and non-public facts}';

    protected $description = 'Export the knowledge base with full provenance as JSON Lines';

    public function handle(): int
    {
        $path = $this->argument('path');
        $fh = str_ends_with($path, '.gz') ? gzopen($path, 'w9') : fopen($path, 'w');
        $write = str_ends_with($path, '.gz') ? 'gzwrite' : 'fwrite';
        $n = 0;
        $q = Entity::with(['type', 'aliases', 'historicalNames.source', 'place'])->whereNull('merged_into_id');
        if (! $this->option('all')) {
            $q->published();
        }
        $q->orderBy('id')->chunk(200, function ($entities) use ($fh, $write, &$n) {
            foreach ($entities as $e) {
                $facts = Fact::where('entity_id', $e->id)->when(! $this->option('all'), fn ($f) => $f->public())
                    ->with(['property', 'valueEntity', 'sources.source', 'sources.extract'])->orderBy('id')->get();
                $row = [
                    'uuid' => $e->uuid, 'wikidata' => $e->wikidata_id, 'type' => $e->type->key, 'slug' => $e->slug,
                    'names' => array_filter(['canonical' => $e->canonical_name, 'fa' => $e->name_fa, 'en' => $e->name_en, 'ar' => $e->name_ar, 'local' => $e->name_local]),
                    'aliases' => $e->aliases->map(fn ($a) => ['alias' => $a->alias, 'lang' => $a->language, 'type' => $a->alias_type])->values(),
                    'historical_names' => $e->historicalNames->map(fn ($h) => ['name' => $h->name, 'period' => $h->period_label, 'from' => $h->year_from,
                        'to' => $h->year_to, 'status' => $h->verification_status, 'source' => $h->source?->uuid, 'page' => $h->page_number])->values(),
                    'parent' => $e->place?->parent_id ? Entity::find($e->place->parent_id)?->uuid : null,
                    'coordinates' => $e->latitude !== null ? [$e->latitude, $e->longitude] : null,
                    'facts' => $facts->map(fn ($f) => [
                        'uuid' => $f->uuid, 'property' => $f->property->key, 'value' => $f->is_unknown ? 'UNKNOWN' : $f->displayValue('en'),
                        'value_entity' => $f->valueEntity?->uuid, 'qualifiers' => $f->qualifiers, 'status' => $f->verification_status,
                        'confidence' => $f->confidence_score, 'disputed' => $f->conflict_id !== null, 'revision' => $f->revision,
                        'sources' => $f->sources->map(fn ($s) => ['source' => $s->source->uuid, 'title' => $s->source->title, 'url' => $s->source->url,
                            'license' => $s->source->license, 'page' => $s->page_number, 'locator' => $s->locator, 'quote' => $s->quote,
                            'extract' => $s->extract?->original_text])->values(),
                    ])->values(),
                ];
                $write($fh, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
                $n++;
            }
        });
        str_ends_with($path, '.gz') ? gzclose($fh) : fclose($fh);
        $this->info("exported {$n} entities to {$path}");

        return self::SUCCESS;
    }
}
