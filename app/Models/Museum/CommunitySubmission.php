<?php

namespace App\Models\Museum;

use App\Models\Museum\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommunitySubmission extends MuseumModel
{
    use HasUuid, SoftDeletes;

    protected $table = 'museum_community_submissions';

    protected $casts = [
        'payload' => 'array',
        'ai_check' => 'array',
        'consent_publish' => 'boolean',
        'is_own_work' => 'boolean',
        'submitter_contact' => 'encrypted',
    ];

    protected $hidden = ['submitter_contact', 'ip_hash'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'place_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id');
    }

    public function result(): MorphTo
    {
        return $this->morphTo();
    }
}
