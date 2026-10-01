<?php

namespace App\Models\Museum;

use App\Models\Museum\Concerns\HasMedia;
use App\Models\Museum\Concerns\HasUuid;
use App\Museum\Enums\Visibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A nameable thing in the knowledge graph (place, word, food, person, document ...).
 * Typed attributes live in a 1:1 extension table; descriptive claims live in facts.
 */
class Entity extends MuseumModel
{
    use HasMedia, HasUuid, SoftDeletes;

    protected $table = 'museum_entities';

    protected $casts = [
        'external_ids' => 'array',
        'is_candidate' => 'boolean',
        'published_at' => 'datetime',
        'latitude' => 'float',
        'longitude' => 'float',
        'confidence_score' => 'float',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(EntityType::class, 'entity_type_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(EntityAlias::class);
    }

    public function historicalNames(): HasMany
    {
        return $this->hasMany(HistoricalName::class)->orderBy('year_from');
    }

    public function facts(): HasMany
    {
        return $this->hasMany(Fact::class);
    }

    public function outgoingRelationships(): HasMany
    {
        return $this->hasMany(EntityRelationship::class, 'subject_id');
    }

    public function incomingRelationships(): HasMany
    {
        return $this->hasMany(EntityRelationship::class, 'object_id');
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(Mention::class);
    }

    public function primaryPlace(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'primary_place_id');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'merged_into_id');
    }

    // ---- domain extensions
    public function place(): HasOne
    {
        return $this->hasOne(Place::class, 'entity_id');
    }

    public function word(): HasOne
    {
        return $this->hasOne(Word::class, 'entity_id');
    }

    public function dialect(): HasOne
    {
        return $this->hasOne(Dialect::class, 'entity_id');
    }

    public function food(): HasOne
    {
        return $this->hasOne(Food::class, 'entity_id');
    }

    public function plant(): HasOne
    {
        return $this->hasOne(Plant::class, 'entity_id');
    }

    public function plantVariety(): HasOne
    {
        return $this->hasOne(PlantVariety::class, 'entity_id');
    }

    public function person(): HasOne
    {
        return $this->hasOne(Person::class, 'entity_id');
    }

    public function document(): HasOne
    {
        return $this->hasOne(Document::class, 'entity_id');
    }

    public function interview(): HasOne
    {
        return $this->hasOne(Interview::class, 'entity_id');
    }

    public function historicalEvent(): HasOne
    {
        return $this->hasOne(HistoricalEvent::class, 'entity_id');
    }

    public function proverb(): HasOne
    {
        return $this->hasOne(Proverb::class, 'entity_id');
    }

    public function tradition(): HasOne
    {
        return $this->hasOne(Tradition::class, 'entity_id');
    }

    public function clothingItem(): HasOne
    {
        return $this->hasOne(ClothingItem::class, 'entity_id');
    }

    public function craft(): HasOne
    {
        return $this->hasOne(Craft::class, 'entity_id');
    }

    public function game(): HasOne
    {
        return $this->hasOne(Game::class, 'entity_id');
    }

    public function musicItem(): HasOne
    {
        return $this->hasOne(MusicItem::class, 'entity_id');
    }

    public function diagrams(): HasMany
    {
        return $this->hasMany(Diagram::class);
    }

    // ---- scopes
    public function scopePublished(Builder $q): Builder
    {
        return $q->where('visibility', Visibility::Published->value)->whereNull('merged_into_id');
    }

    public function scopeOfType(Builder $q, string|array $typeKeys): Builder
    {
        $keys = (array) $typeKeys;

        return $q->whereIn('entity_type_id', EntityType::idsFor($keys));
    }

    public function isPublished(): bool
    {
        return $this->visibility === Visibility::Published->value && $this->merged_into_id === null;
    }

    public function displayName(string $locale = 'fa'): string
    {
        return match ($locale) {
            'en' => $this->name_en ?: $this->canonical_name,
            'ar' => $this->name_ar ?: $this->canonical_name,
            default => $this->name_fa ?: $this->canonical_name,
        };
    }

    public function publicUrl(): string
    {
        return url('/museum/e/'.$this->slug);
    }
}
