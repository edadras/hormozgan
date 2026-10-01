<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class VerificationLog extends MuseumModel
{
    protected $table = 'museum_verification_logs';

    protected $casts = [
        'evidence' => 'array',
        'created_at' => 'datetime',
    ];

    public $timestamps = false;

    public function verifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }
}
