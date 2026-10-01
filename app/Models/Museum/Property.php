<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class Property extends MuseumModel
{
    protected $table = 'museum_properties';

    protected $casts = [
        'entity_types' => 'array',
        'qualifier_keys' => 'array',
        'is_multivalued' => 'boolean',
    ];

    public function relationshipType(): BelongsTo
    {
        return $this->belongsTo(RelationshipType::class);
    }

    public static function byKey(string $key): self
    {
        $id = Cache::remember('museum:property:'.$key, 3600, fn () => static::where('key', $key)->value('id'));
        $prop = $id ? static::find($id) : null;
        if (! $prop) {
            Cache::forget('museum:property:'.$key);
            throw new \InvalidArgumentException("Unknown museum property [$key]");
        }

        return $prop;
    }

    public function appliesTo(?string $entityTypeKey): bool
    {
        return empty($this->entity_types) || $entityTypeKey === null || in_array($entityTypeKey, $this->entity_types, true);
    }
}
