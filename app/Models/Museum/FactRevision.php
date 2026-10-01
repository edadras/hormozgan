<?php

namespace App\Models\Museum;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FactRevision extends MuseumModel
{
    protected $table = 'museum_fact_revisions';

    protected $casts = [
        'previous_value' => 'array',
        'new_value' => 'array',
        'created_at' => 'datetime',
    ];

    public $timestamps = false;

    public function fact(): BelongsTo
    {
        return $this->belongsTo(Fact::class, 'fact_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editor_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id');
    }
}
