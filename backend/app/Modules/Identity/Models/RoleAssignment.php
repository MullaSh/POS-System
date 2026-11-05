<?php

namespace App\Modules\Identity\Models;

use App\Modules\Location\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Peran yang dipegang seorang user, dengan cakupan lokasinya (2D-P1).
 *
 * @property int $id
 * @property int $user_id
 * @property int $role_id
 * @property string $scope
 * @property string $status
 */
class RoleAssignment extends Model
{
    public const SCOPE_ALL = 'ALL_LOCATIONS';
    public const SCOPE_SELECTED = 'SELECTED';

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_REVOKED = 'REVOKED';

    protected $fillable = [
        'user_id', 'role_id', 'scope', 'status',
        'requested_by', 'confirmed_by', 'confirmed_at', 'revoked_by', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'role_assignment_locations');
    }
}
