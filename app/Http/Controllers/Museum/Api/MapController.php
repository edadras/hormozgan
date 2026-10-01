<?php

namespace App\Http\Controllers\Museum\Api;

use App\Models\Museum\Entity;
use App\Models\Museum\EntityType;
use Illuminate\Http\Request;

/** GeoJSON of published, geolocated entities; bbox + type filters; region summary on demand. */
class MapController extends ApiController
{
    public function geojson(Request $r)
    {
        $r->validate(['types' => 'nullable|string|max:300', 'bbox' => ['nullable', 'regex:/^-?[\d.]+,-?[\d.]+,-?[\d.]+,-?[\d.]+$/']]);

        return $this->cached($r, function () use ($r) {
            $q = Entity::published()->whereNotNull('latitude')->with('type');
            if ($types = array_filter(explode(',', (string) $r->query('types')))) {
                $q->whereIn('entity_type_id', EntityType::idsFor($types));
            }
            if ($bbox = $r->query('bbox')) {
                [$w, $s, $e, $n] = array_map('floatval', explode(',', $bbox));
                $q->whereBetween('latitude', [$s, $n])->whereBetween('longitude', [$w, $e]);
            }

            return [
                'type' => 'FeatureCollection',
                'features' => $q->limit(20000)->get(['id', 'slug', 'canonical_name', 'name_fa', 'name_en', 'latitude', 'longitude', 'entity_type_id', 'facts_count'])
                    ->map(fn (Entity $e) => [
                        'type' => 'Feature',
                        'geometry' => ['type' => 'Point', 'coordinates' => [(float) $e->longitude, (float) $e->latitude]],
                        'properties' => ['slug' => $e->slug, 'name' => $e->displayName(), 'name_en' => $e->name_en, 'type' => $e->type->key,
                            'type_label' => $e->type->name_fa, 'facts' => $e->facts_count, 'url' => $e->publicUrl()],
                    ])->values(),
            ];
        });
    }

    /** What the museum holds about a region, by domain (for the map side panel). */
    public function region(Request $r, string $slug)
    {
        return $this->cached($r, function () use ($slug) {
            $place = Entity::published()->where('slug', $slug)->with('place')->firstOrFail();
            $path = $place->place?->path ?? '/'.$place->id.'/';
            $placeIds = \App\Models\Museum\Place::where('path', 'like', $path.'%')->pluck('entity_id');
            $related = Entity::published()->with('type')
                ->where(fn ($q) => $q->whereIn('primary_place_id', $placeIds)
                    ->orWhereIn('id', fn ($s) => $s->select('subject_id')->from('museum_entity_relationships')->whereIn('object_id', $placeIds)))
                ->limit(2000)->get()->groupBy(fn ($e) => $e->type->domain);

            return [
                'place' => $this->presenter->summary($place),
                'sub_places' => $placeIds->count() - 1,
                'domains' => $related->map(fn ($items) => $items->take(12)->map(fn ($e) => $this->presenter->summary($e))->values()),
                'counts' => $related->map->count(),
            ];
        });
    }
}
