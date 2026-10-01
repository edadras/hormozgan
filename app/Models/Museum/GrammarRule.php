<?php

namespace App\Models\Museum;

use App\Models\Museum\Concerns\HasCitations;
use App\Models\Museum\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class GrammarRule extends MuseumModel
{
    use HasCitations, HasUuid, SoftDeletes;

    protected $table = 'museum_grammar_rules';

    protected $casts = [
        'paradigm' => 'array',
    ];

    public function dialect(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'dialect_id');
    }

    public function examples(): HasMany
    {
        return $this->hasMany(GrammarExample::class, 'grammar_rule_id');
    }
}
