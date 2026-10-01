<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use App\Models\Museum\Role;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function museumRoles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'museum_role_user', 'user_id', 'role_id');
    }

    public function hasMuseumRole(string ...$keys): bool
    {
        return $this->museumRoles->whereIn('key', $keys)->isNotEmpty();
    }

    /** Permission check for the museum module; admins hold every permission. */
    public function hasMuseumPermission(string $permission): bool
    {
        $roles = $this->museumRoles()->with('permissions')->get();
        if ($roles->contains('key', 'admin')) {
            return true;
        }

        return $roles->flatMap->permissions->contains('key', $permission);
    }
}
