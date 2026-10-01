<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FactValue extends MuseumModel
{
    protected $table = 'museum_fact_values';

    public function fact(): BelongsTo
    {
        return $this->belongsTo(Fact::class, 'fact_id');
    }
}
