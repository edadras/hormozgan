<?php

namespace App\Models\Museum;

use App\Models\Museum\Concerns\HasUuid;
use App\Museum\Enums\ValueType;
use App\Museum\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single sourced claim: (entity, property, value[, scope place, qualifiers]).
 * Values must only be changed through App\Museum\Services\FactService so that
 * revisions and conflicts are recorded.
 */
class Fact extends MuseumModel
{
    use HasUuid, SoftDeletes;

    protected $table = 'museum_facts';

    protected $casts = [
        'value_json' => 'array',
        'qualifiers' => 'array',
        'is_unknown' => 'boolean',
        'is_preferred' => 'boolean',
        'value_number' => 'float',
        'confidence_score' => 'float',
        'reviewed_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function valueEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'value_entity_id');
    }

    public function scopePlace(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'scope_place_id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(FactSource::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(FactValue::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(FactRevision::class)->orderBy('revision');
    }

    public function conflict(): BelongsTo
    {
        return $this->belongsTo(FactConflict::class, 'conflict_id');
    }

    public function relationship(): HasOne
    {
        return $this->hasOne(EntityRelationship::class);
    }

    public function status(): VerificationStatus
    {
        return VerificationStatus::from($this->verification_status);
    }

    public function scopePublic(Builder $q): Builder
    {
        $min = VerificationStatus::from(config('museum.publication.min_fact_status', 'source_verified'));

        return $q->where(function (Builder $w) use ($min) {
            $w->whereIn('verification_status', VerificationStatus::atLeastValues($min))
                ->orWhere('verification_status', VerificationStatus::Disputed->value);
        })->where('sources_count', '>', 0);
    }

    /** Human readable value; UNKNOWN is shown explicitly, never guessed. */
    public function displayValue(string $locale = 'fa'): string
    {
        if ($this->is_unknown || $this->value_type === ValueType::Unknown->value) {
            return $locale === 'fa' ? 'نامعلوم (UNKNOWN)' : 'UNKNOWN';
        }
        $localized = $this->relationLoaded('values')
            ? $this->values->firstWhere('locale', $locale)
            : null;
        if ($localized) {
            return $localized->display_value;
        }

        return match ($this->value_type) {
            'entity' => $this->valueEntity?->displayName($locale) ?? '#'.$this->value_entity_id,
            'integer' => number_format((int) $this->value_number),
            'decimal' => rtrim(rtrim(number_format($this->value_number, 6, '.', ','), '0'), '.'),
            'year' => $this->value_year_to && $this->value_year_to !== $this->value_year_from
                ? $this->value_year_from.'–'.$this->value_year_to
                : (string) $this->value_year_from,
            'boolean' => $this->value_number ? ($locale === 'fa' ? 'بله' : 'yes') : ($locale === 'fa' ? 'خیر' : 'no'),
            'geo' => isset($this->value_json['lat']) ? $this->value_json['lat'].', '.$this->value_json['lng'] : '',
            'date' => (string) $this->value_date,
            'json' => json_encode($this->value_json, JSON_UNESCAPED_UNICODE),
            default => (string) $this->value_text,
        };
    }

    /** Snapshot of the value columns, used for revisions. */
    public function valueSnapshot(): array
    {
        return array_filter([
            'value_type' => $this->value_type,
            'value_text' => $this->value_text,
            'value_number' => $this->value_number,
            'value_year_from' => $this->value_year_from,
            'value_year_to' => $this->value_year_to,
            'value_date' => $this->value_date,
            'value_entity_id' => $this->value_entity_id,
            'value_json' => $this->value_json,
            'is_unknown' => $this->is_unknown,
        ], fn ($v) => $v !== null);
    }
}
