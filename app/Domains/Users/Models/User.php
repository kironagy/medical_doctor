<?php

namespace App\Domains\Users\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'phone',
        'address',
        'specialization',
        'code',
        'status',
        'last_login_at',
        'uuid',
        'client_updated_at',
        'avatar_path',
        'preferences'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'avatar_url'
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
            'last_login_at' => 'datetime',
            'client_updated_at' => 'datetime',
            'password' => 'hashed',
            'preferences' => 'array',
        ];
    }

    public function getAvatarUrlAttribute()
    {
        if ($this->avatar_path) {
            return url('/storage/' . $this->avatar_path);
        }
        return 'https://ui-avatars.com/api/?name=' . urlencode($this->name) . '&color=14b8a6&background=ccfbf1';
    }

    public function patients(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Domains\Patients\Models\Patient::class, 'primary_doctor_id');
    }

    /**
     * Users holding $roleName — without exploding when the role row is absent.
     *
     * Spatie's role() scope resolves the name to a Role model and THROWS
     * RoleDoesNotExist when there isn't one. That is fine on the server, whose
     * roles table is seeded, but the embedded app ships with empty
     * roles/model_has_roles tables and only ever gains the roles of accounts
     * that have signed in on that device. So an admin who signed in on the
     * phone reached /admin/doctors, User::role('doctor') threw, and — because
     * RoleDoesNotExist is not an HttpException — the SQLite catch-all in
     * bootstrap/app.php swallowed it and redirected to /workspace. From the
     * outside that looked exactly like "it won't let me into the admin screen".
     *
     * Falls back to the users.role column, which is populated on both sides
     * and is what the app's own super-admin checks already read.
     */
    public function scopeHavingRole($query, string $roleName)
    {
        $guard = config('auth.defaults.guard', 'web');

        $roleExists = \Spatie\Permission\Models\Role::where('name', $roleName)
            ->where('guard_name', $guard)
            ->exists();

        return $roleExists
            ? $query->role($roleName)
            : $query->where('role', $roleName);
    }
}
