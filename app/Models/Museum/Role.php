<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends MuseumModel
{
    protected $table = 'museum_roles';

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'museum_permission_role');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(\App\Models\User::class, 'museum_role_user');
    }
}
