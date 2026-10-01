<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrammarExample extends MuseumModel
{
    protected $table = 'museum_grammar_examples';

    public function rule(): BelongsTo
    {
        return $this->belongsTo(GrammarRule::class, 'grammar_rule_id');
    }

    public function sentence(): BelongsTo
    {
        return $this->belongsTo(Sentence::class, 'sentence_id');
    }
}
