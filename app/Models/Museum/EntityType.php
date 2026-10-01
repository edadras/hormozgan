<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class EntityType extends MuseumModel
{
    protected $table = 'museum_entity_types';

    protected $casts = ['is_system' => 'boolean'];

    public function entities(): HasMany
    {
        return $this->hasMany(Entity::class);
    }

    /** key => id map, cached (types are seeded system data). */
    public static function map(): array
    {
        return Cache::remember('museum:entity-types:map', 3600, fn () => static::pluck('id', 'key')->all());
    }

    public static function idFor(string $key): int
    {
        $map = static::map();
        if (! isset($map[$key])) {
            Cache::forget('museum:entity-types:map');
            $map = static::map();
        }
        if (! isset($map[$key])) {
            throw new \InvalidArgumentException("Unknown museum entity type [$key]");
        }

        return $map[$key];
    }

    public static function idsFor(array $keys): array
    {
        $map = static::map();
        $ids = [];
        foreach ($keys as $k) {
            if (isset($map[$k])) {
                $ids[] = $map[$k];
            }
        }
        // Include child types (e.g. "place" includes "village").
        $children = static::whereIn('parent_id', $ids)->pluck('id')->all();

        return array_values(array_unique(array_merge($ids, $children)));
    }

    public static function flush(): void
    {
        Cache::forget('museum:entity-types:map');
    }
}
