<?php

namespace App\Http\Controllers\Museum\Api;

use App\Models\Museum\Entity;
use App\Museum\Search\SearchEngine;
use Illuminate\Http\Request;

class SearchController extends ApiController
{
    public function search(Request $r, SearchEngine $engine)
    {
        $r->validate(['q' => 'required|string|min:1|max:200', 'types' => 'nullable|string|max:300', 'place' => 'nullable|string|max:191',
            'from' => 'nullable|integer', 'to' => 'nullable|integer', 'page' => 'nullable|integer|min:1|max:500']);

        return $this->cached($r, function () use ($r, $engine) {
            $placeId = $r->filled('place') ? Entity::where('slug', $r->query('place'))->value('id') : null;
            $res = $engine->search($r->query('q'), [
                'types' => array_filter(explode(',', (string) $r->query('types'))),
                'place_id' => $placeId,
                'year_from' => $r->integer('from') ?: null,
                'year_to' => $r->integer('to') ?: null,
                'public_only' => true,
            ], max(1, $r->integer('page', 1)), $this->perPage($r));

            return [
                'query' => $r->query('q'),
                'total' => $res['total'],
                'data' => collect($res['hits'])->map(fn ($h) => [
                    'type' => $h['doc']->doc_type,
                    'title' => $h['doc']->title,
                    'excerpt' => mb_substr((string) $h['doc']->body, 0, 240),
                    'url' => url($h['doc']->url),
                    'verification_status' => $h['doc']->verification_status,
                    'score' => round($h['score'], 3),
                ])->values(),
                'facets' => collect($res['hits'])->countBy(fn ($h) => $h['doc']->doc_type),
            ];
        });
    }
}
