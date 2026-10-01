<?php

namespace App\Http\Controllers\Museum\Api;

use App\Models\Museum\Entity;
use App\Models\Museum\EntityType;
use App\Models\Museum\Media;
use Illuminate\Http\Request;

/** «کشف هرمزگان»: one random published item per domain (never invents filler when empty). */
class ExploreController extends ApiController
{
    public const SLOTS = [
        'word' => ['word'], 'food' => ['food'], 'place' => ['village', 'city', 'island', 'historical_site', 'port', 'mountain'],
        'proverb' => ['proverb'], 'person' => ['person'], 'plant' => ['plant', 'plant_variety'], 'music' => ['music_work', 'music_instrument'],
    ];

    public function index(Request $r)
    {
        return response()->json(['data' => $this->pick()]);
    }

    public function pick(): array
    {
        $out = [];
        foreach (self::SLOTS as $slot => $types) {
            $ids = EntityType::idsFor($types);
            $q = Entity::published()->whereIn('entity_type_id', $ids);
            $count = (clone $q)->count();
            $out[$slot] = $count ? $this->presenter->summary((clone $q)->with('type')->skip(random_int(0, $count - 1))->first()) : null;
        }
        $photos = Media::public()->where('media_type', 'image')->whereNotNull('year');
        $n = (clone $photos)->count();
        $photo = $n ? (clone $photos)->skip(random_int(0, $n - 1))->first() : null;
        $out['historical_photo'] = $photo ? ['title' => $photo->title, 'year' => $photo->year, 'url' => $photo->publicUrl(), 'thumb' => $photo->publicUrl('thumb')] : null;

        return $out;
    }
}
