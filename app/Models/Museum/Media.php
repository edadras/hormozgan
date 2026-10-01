<?php

namespace App\Models\Museum;

use App\Models\Museum\Concerns\HasCitations;
use App\Models\Museum\Concerns\HasUuid;
use App\Museum\Enums\License;
use App\Museum\Enums\Visibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Media extends MuseumModel
{
    use HasCitations, HasUuid, SoftDeletes;

    protected $table = 'museum_media';

    protected $casts = ['variants' => 'array', 'captured_on' => 'date'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'place_id');
    }

    public function audio(): HasOne
    {
        return $this->hasOne(AudioRecording::class, 'media_id');
    }

    public function photo(): HasOne
    {
        return $this->hasOne(Photo::class, 'media_id');
    }

    public function video(): HasOne
    {
        return $this->hasOne(Video::class, 'media_id');
    }

    public function licenseEnum(): License
    {
        return License::tryFrom($this->license) ?? License::Unknown;
    }

    /**
     * Copyright gate (section 53): the file itself may only be served publicly when the
     * record is published, its license allows it, and — for audio with a speaker — the
     * speaker consented to publication.
     */
    public function isPubliclyServable(): bool
    {
        if ($this->visibility !== Visibility::Published->value || ! $this->licenseEnum()->allowsPublicDisplay()) {
            return false;
        }
        $speaker = $this->audio?->speaker;
        if ($speaker && ! $speaker->hasConsentFor('publish_audio')) {
            return false;
        }

        return true;
    }

    public function scopePublic(Builder $q): Builder
    {
        $allowed = array_map(fn (License $l) => $l->value, array_filter(License::cases(), fn (License $l) => $l->allowsPublicDisplay()));

        return $q->where('visibility', Visibility::Published->value)->whereIn('license', $allowed);
    }

    public function publicUrl(?string $variant = null): ?string
    {
        if (! $this->isPubliclyServable()) {
            return null;
        }
        if ($variant && ! isset($this->variants[$variant])) {
            return null;
        }
        $path = $variant ? $this->variants[$variant] : $this->path;
        if ($cdn = config('museum.media.cdn_url')) {
            return rtrim($cdn, '/').'/'.ltrim($path, '/');
        }
        if (config('filesystems.disks.'.$this->disk.'.driver') === 's3') {
            return Storage::disk($this->disk)->temporaryUrl($path, now()->addHour());
        }

        // Local storage is never exposed directly; files go through the copyright/consent gate.
        return route('museum.file', ['uuid' => $this->uuid] + ($variant ? ['v' => $variant] : []));
    }
}
