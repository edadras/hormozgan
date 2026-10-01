<?php

namespace App\Models\Museum;

use App\Models\Museum\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Speaker extends MuseumModel
{
    use HasUuid, SoftDeletes;

    protected $table = 'museum_speakers';

    protected $casts = [
        'consent_scope' => 'array',
        'consent_date' => 'date',
        'publish_name' => 'boolean',
        'private_contact' => 'encrypted',
    ];

    protected $hidden = ['private_contact', 'notes'];

    /** Name shown publicly; anonymous unless the speaker consented to publishing it. */
    public function publicName(): string
    {
        return $this->publish_name && $this->display_name ? $this->display_name : 'گوینده ناشناس #'.$this->id;
    }

    public function hasConsentFor(string $scope): bool
    {
        if (! in_array($this->consent_status, ['granted', 'granted_restricted'], true)) {
            return false;
        }
        if ($this->consent_status === 'granted') {
            return ($this->consent_scope[$scope] ?? true) === true;
        }

        return ($this->consent_scope[$scope] ?? false) === true;
    }

    public function homePlace(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'home_place_id');
    }

    public function dialect(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'dialect_id');
    }
}
