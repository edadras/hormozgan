<?php

namespace App\Http\Controllers\Museum\Api;

use App\Models\Museum\Entity;
use App\Models\Museum\EntityType;
use App\Museum\Support\TextNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Generic access to any published entity type (foods, plants, people, traditions, ...). */
class EntityController extends ApiController
{
    public const SORTS = ['name' => 'canonical_name', 'facts' => 'facts_count', 'recent' => 'published_at', 'id' => 'id'];

    public function index(Request $r)
    {
        $r->validate([
            'type' => 'nullable|string|max:64', 'q' => 'nullable|string|max:200', 'place' => 'nullable|string|max:191',
            'sort' => 'nullable|in:'.implode(',', array_keys(self::SORTS)), 'dir' => 'nullable|in:asc,desc',
        ]);

        return $this->cached($r, function () use ($r) {
            $q = $this->baseQuery($r);
            $sort = self::SORTS[$r->query('sort', 'name')];
            $p = $q->orderBy($sort, $r->query('dir', $sort === 'canonical_name' ? 'asc' : 'desc'))->orderBy('id')
                ->paginate($this->perPage($r))->withQueryString();

            return $this->paginated($p, fn ($e) => $this->presenter->summary($e, $this->locale($r)));
        });
    }

    public function foods(Request $r)
    {
        $r->query->set('type', 'food');

        return $this->index($r);
    }

    public function plants(Request $r)
    {
        $r->query->set('type', 'plant,plant_variety');

        return $this->index($r);
    }

    public function show(Request $r, string $slug)
    {
        return $this->cached($r, function () use ($slug, $r) {
            $e = Entity::published()->with('type')->where('slug', $slug)->firstOrFail();

            return ['data' => $this->presenter->detail($e, $this->locale($r))];
        });
    }

    protected function baseQuery(Request $r, array $forceTypes = []): Builder
    {
        $q = Entity::published()->with('type');
        $types = $forceTypes ?: array_filter(explode(',', (string) $r->query('type')));
        if ($types) {
            $q->whereIn('entity_type_id', EntityType::idsFor($types));
        }
        if ($term = $r->query('q')) {
            $norm = TextNormalizer::normalize($term);
            $q->where(fn ($w) => $w->where('normalized_name', 'like', '%'.$norm.'%')
                ->orWhereIn('id', fn ($s) => $s->select('entity_id')->from('museum_entity_aliases')->where('normalized_alias', 'like', $norm.'%')));
        }
        if ($place = $r->query('place')) {
            $placeEntity = Entity::where('slug', $place)->first();
            if ($placeEntity) {
                $q->where(fn ($w) => $w->where('primary_place_id', $placeEntity->id)
                    ->orWhereIn('id', fn ($s) => $s->select('entity_id')->from('museum_places')->where('path', 'like', '%/'.$placeEntity->id.'/%')->where('entity_id', '!=', $placeEntity->id)));
            }
        }

        return $q;
    }
}
