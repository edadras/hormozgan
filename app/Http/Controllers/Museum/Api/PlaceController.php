<?php

namespace App\Http\Controllers\Museum\Api;

use Illuminate\Http\Request;

class PlaceController extends EntityController
{
    public const PLACE_TYPES = ['province', 'county', 'district', 'city', 'rural_district', 'village', 'neighborhood', 'island',
        'mountain', 'river', 'port', 'bay', 'historical_site', 'building', 'market', 'street', 'natural_feature', 'region', 'place'];

    public function index(Request $r)
    {
        $r->validate(['type' => 'nullable|in:'.implode(',', self::PLACE_TYPES), 'parent' => 'nullable|string|max:191', 'q' => 'nullable|string|max:200']);

        return $this->cached($r, function () use ($r) {
            $q = $this->baseQuery($r, [$r->query('type', 'place')]);
            if ($parent = $r->query('parent')) {
                $q->whereIn('id', fn ($s) => $s->select('p.entity_id')->from('museum_places as p')
                    ->join('museum_entities as pe', 'pe.id', '=', 'p.parent_id')->where('pe.slug', $parent));
            }
            $p = $q->orderBy('canonical_name')->paginate($this->perPage($r))->withQueryString();

            return $this->paginated($p, fn ($e) => $this->presenter->summary($e, $this->locale($r)));
        });
    }
}
