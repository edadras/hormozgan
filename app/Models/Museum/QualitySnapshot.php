<?php

namespace App\Models\Museum;

class QualitySnapshot extends MuseumModel
{
    protected $table = 'museum_quality_snapshots';

    protected $casts = [
        'metrics' => 'array',
        'created_at' => 'datetime',
    ];

    public $timestamps = false;
}
