<?php

namespace App\Console\Commands\Museum;

use App\Models\Museum\Entity;
use App\Models\Museum\Fact;
use App\Museum\Importers\WikimediaHarvester;
use App\Museum\Services\VerificationService;
use Illuminate\Console\Command;

/**
 * Re-applies the harvester's Hormozgan scope rule to already-imported Wikipedia-derived entities
 * and unpublishes (never deletes) those that fail it, with a logged reason.
 */
class AuditScopeCommand extends Command
{
    protected $signature = 'museum:audit:scope {--apply : Unpublish out-of-scope entities (default: report only)}';

    protected $description = 'Find harvested entities that are not about Hormozgan';

    public function handle(WikimediaHarvester $h, VerificationService $v): int
    {
        $ids = Fact::where('extraction_method', 'structured_import')->whereHas('sources.source', fn ($q) => $q->where('title', 'like', 'ویکی‌پدیا:%'))
            ->distinct()->pluck('entity_id');
        $out = 0;
        foreach (Entity::whereIn('id', $ids)->where('visibility', '!=', 'hidden')->get() as $e) {
            $text = Fact::where('entity_id', $e->id)->whereHas('property', fn ($q) => $q->whereIn('key', ['description', 'biography', 'history_note']))->pluck('value_text')->all();
            $item = ['claims' => []];
            $parentQids = Entity::whereIn('id', Fact::where('entity_id', $e->id)->whereNotNull('value_entity_id')->pluck('value_entity_id'))->pluck('wikidata_id')->filter();
            foreach ($parentQids as $q) {
                $item['claims']['P131'][] = ['mainsnak' => ['datavalue' => ['value' => ['id' => $q]]]];
            }
            if (! $h->isRelevant(['lead' => $text, 'sections' => []], $item)) {
                $out++;
                $this->line('out of scope: '.$e->canonical_name.' ('.$e->wikidata_id.')');
                if ($this->option('apply')) {
                    $v->unpublish($e, null, 'Out of Hormozgan scope (category drift during harvest)');
                }
            }
        }
        $this->info("{$out} out-of-scope entities".($this->option('apply') ? ' unpublished' : ''));

        return self::SUCCESS;
    }
}
