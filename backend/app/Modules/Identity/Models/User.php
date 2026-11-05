<?php

namespace App\Modules\Identity\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $username
 * @property bool $is_active
 * @property bool $must_change_password
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'username', 'password', 'is_active', 'must_change_password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    /** Penugasan peran yang sedang berlaku. */
    public function activeAssignments(): Collection
    {
        return $this->roleAssignments()
            ->where('status', RoleAssignment::STATUS_ACTIVE)
            ->with(['role', 'locations'])
            ->get();
    }

    public function hasActiveRole(string $roleCode): bool
    {
        return $this->roleAssignments()
            ->where('status', RoleAssignment::STATUS_ACTIVE)
            ->whereHas('role', fn ($q) => $q->where('code', $roleCode))
            ->exists();
    }

    public function isOwner(): bool
    {
        return $this->hasActiveRole(Role::OWNER);
    }

    /**
     * Lokasi tempat user boleh bertindak dengan peran itu (2D-P1):
     * null = semua lokasi, [] = tidak memegang peran itu.
     *
     * @return list<int>|null
     */
    public function locationIdsFor(string $roleCode): ?array
    {
        $assignments = $this->roleAssignments()
            ->where('status', RoleAssignment::STATUS_ACTIVE)
            ->whereHas('role', fn ($q) => $q->where('code', $roleCode))
            ->with('locations:id')
            ->get();

        if ($assignments->contains(fn (RoleAssignment $a) => $a->scope === RoleAssignment::SCOPE_ALL)) {
            return null;
        }

        return $assignments->flatMap(fn (RoleAssignment $a) => $a->locations->pluck('id'))->unique()->values()->all();
    }

    public function hasRoleAt(string $roleCode, int $locationId): bool
    {
        $ids = $this->locationIdsFor($roleCode);

        return $ids === null ? $this->hasActiveRole($roleCode) : in_array($locationId, $ids, true);
    }
}
