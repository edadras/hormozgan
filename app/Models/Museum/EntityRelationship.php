<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntityRelationship extends MuseumModel
{
    protected $table = 'museum_entity_relationships';

    protected $casts = [
        'confidence_score' => 'float',
    ];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'subject_id');
    }

    public function object(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'object_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(RelationshipType::class, 'relationship_type_id');
    }

    public function fact(): BelongsTo
    {
        return $this->belongsTo(Fact::class, 'fact_id');
    }
}
