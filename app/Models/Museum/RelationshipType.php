<?php

namespace App\Models\Museum;

class RelationshipType extends MuseumModel
{
    protected $table = 'museum_relationship_types';

    protected $casts = [
        'subject_types' => 'array',
        'object_types' => 'array',
        'is_symmetric' => 'boolean',
    ];
}
