<?php

namespace App\Models\Museum;

use App\Models\Museum\Concerns\HasCitations;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Word extends MuseumModel
{
    use HasCitations;

    protected $table = 'museum_words';

    protected $primaryKey = 'entity_id';

    public $incrementing = false;

    protected $guarded = [];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function dialect(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'dialect_id');
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'place_id');
    }

    public function concept(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'concept_id');
    }

    public function meanings(): HasMany
    {
        return $this->hasMany(WordMeaning::class, 'word_id');
    }

    public function pronunciations(): HasMany
    {
        return $this->hasMany(WordPronunciation::class, 'word_id');
    }

    public function examples(): BelongsToMany
    {
        return $this->belongsToMany(Sentence::class, 'museum_word_examples', 'word_id', 'sentence_id');
    }

    public function wordRelations(): HasMany
    {
        return $this->hasMany(WordRelation::class, 'word_id');
    }
}
