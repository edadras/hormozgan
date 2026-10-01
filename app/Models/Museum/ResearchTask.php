<?php

namespace App\Models\Museum;

use App\Models\Museum\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchTask extends MuseumModel
{
    use HasUuid;

    protected $table = 'museum_research_tasks';

    protected $casts = [
        'steps' => 'array',
        'source_ids' => 'array',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }
}
