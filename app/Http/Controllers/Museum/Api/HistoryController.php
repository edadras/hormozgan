<?php

namespace App\Http\Controllers\Museum\Api;

use App\Models\Museum\Entity;
use App\Models\Museum\HistoricalName;
use App\Models\Museum\Location;
use App\Museum\Enums\VerificationStatus;
use Illuminate\Http\Request;

class HistoryController extends EntityController
{
    /** Timeline filterable by year range, place and topic (event_type). */
    public function timeline(Request $r)
    {
        $r->validate(['from' => 'nullable|integer', 'to' => 'nullable|integer', 'place' => 'nullable|string', 'topic' => 'nullable|string|max:32']);

        return $this->cached($r, function () use ($r) {
            $q = $this->baseQuery($r, ['historical_event'])->join('museum_historical_events as he', 'he.entity_id', '=', 'museum_entities.id')
                ->select('museum_entities.*', 'he.year_from', 'he.year_to', 'he.event_type', 'he.date_precision');
            if ($r->filled('from')) {
                $q->where(fn ($w) => $w->where('he.year_to', '>=', $r->integer('from'))->orWhere('he.year_from', '>=', $r->integer('from')));
            }
            if ($r->filled('to')) {
                $q->where('he.year_from', '<=', $r->integer('to'));
            }
            if ($r->filled('topic')) {
                $q->where('he.event_type', $r->query('topic'));
            }
            $p = $q->orderBy('he.year_from')->paginate($this->perPage($r))->withQueryString();

            return $this->paginated($p, fn ($e) => $this->presenter->summary($e) + [
                'year_from' => $e->year_from, 'year_to' => $e->year_to, 'event_type' => $e->event_type, 'date_precision' => $e->date_precision,
            ]);
        });
    }

    public function historicalNames(Request $r)
    {
        $r->validate(['q' => 'nullable|string|max:200']);

        return $this->cached($r, function () use ($r) {
            $q = HistoricalName::with(['entity.type', 'source'])
                ->whereIn('verification_status', VerificationStatus::atLeastValues(VerificationStatus::SourceVerified))
                ->whereIn('entity_id', Entity::published()->select('id'));
            if ($term = $r->query('q')) {
                $q->where('normalized_name', 'like', '%'.\App\Museum\Support\TextNormalizer::normalize($term).'%');
            }
            $p = $q->orderBy('normalized_name')->paginate($this->perPage($r))->withQueryString();

            return $this->paginated($p, fn ($h) => [
                'historical_name' => $h->name, 'current' => $this->presenter->summary($h->entity), 'period' => $h->period_label,
                'year_from' => $h->year_from, 'year_to' => $h->year_to, 'local_pronunciation' => $h->local_pronunciation,
                'meaning' => $h->meaning, 'reason' => $h->naming_reason,
                'source' => $h->source ? $this->presenter->source($h->source) : null, 'page' => $h->page_number,
            ]);
        });
    }

    /** Time-aware features for the "Hormozgan through time" map. */
    public function layers(Request $r)
    {
        $r->validate(['year' => 'required|integer|between:-3000,2100']);

        return $this->cached($r, function () use ($r) {
            $year = $r->integer('year');
            $features = Location::where(fn ($w) => $w->whereNull('year_from')->orWhere('year_from', '<=', $year))
                ->where(fn ($w) => $w->whereNull('year_to')->orWhere('year_to', '>=', $year))
                ->whereIn('verification_status', VerificationStatus::atLeastValues(VerificationStatus::SourceVerified))
                ->with('source')->limit(5000)->get()
                ->map(fn (Location $l) => [
                    'type' => 'Feature',
                    'geometry' => $l->geometry ? json_decode($l->geometry, true) : ['type' => 'Point', 'coordinates' => [$l->longitude, $l->latitude]],
                    'properties' => ['label' => $l->label, 'feature_type' => $l->feature_type, 'year_from' => $l->year_from, 'year_to' => $l->year_to,
                        'precision' => $l->precision, 'source' => $l->source?->citationLabel()],
                ]);
            $names = HistoricalName::where('year_from', '<=', $year)->where(fn ($w) => $w->whereNull('year_to')->orWhere('year_to', '>=', $year))
                ->whereIn('verification_status', VerificationStatus::atLeastValues(VerificationStatus::SourceVerified))
                ->whereIn('entity_id', Entity::published()->whereNotNull('latitude')->select('id'))
                ->with('entity')->limit(5000)->get()
                ->map(fn ($h) => ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [$h->entity->longitude, $h->entity->latitude]],
                    'properties' => ['label' => $h->name, 'current_name' => $h->entity->displayName(), 'feature_type' => 'historical_name', 'url' => $h->entity->publicUrl()]]);

            return ['type' => 'FeatureCollection', 'year' => $year, 'features' => $features->concat($names)->values()];
        });
    }
}
