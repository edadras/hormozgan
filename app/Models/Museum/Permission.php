<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends MuseumModel
{
    protected $table = 'museum_permissions';

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'museum_permission_role');
    }
}
