<?php

namespace App\Models\Museum;

use App\Models\Museum\Concerns\HasUuid;

class RagQuery extends MuseumModel
{
    use HasUuid;

    protected $table = 'museum_rag_queries';

    protected $casts = [
        'analysis' => 'array',
        'retrieved' => 'array',
        'citations' => 'array',
    ];
}
