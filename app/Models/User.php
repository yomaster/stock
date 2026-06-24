<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'nickname', 'email', 'password',
        'role_id', 'status', 'avatar',
        'messaging_provider', 'messaging_chat_id', 'messaging_link_code', 'messaging_link_code_expires_at',
        'alert_enabled', 'alert_price_threshold', 'alert_volume_multiplier', 'summary_enabled',
    ];

    protected $hidden = [
        'password', 'remember_token', 'messaging_link_code',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'              => 'datetime',
            'password'                       => 'hashed',
            'status'                         => 'boolean',
            'alert_enabled'                  => 'boolean',
            'summary_enabled'                => 'boolean',
            'alert_price_threshold'          => 'float',
            'alert_volume_multiplier'        => 'float',
            'messaging_link_code_expires_at' => 'datetime',
        ];
    }

    // ───────────────────────── relations ─────────────────────────

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** หุ้นที่ผู้ใช้ติดตาม (pivot user_stocks) — market data ใช้ร่วมกัน */
    public function stocks(): BelongsToMany
    {
        return $this->belongsToMany(Stock::class, 'user_stocks')->withTimestamps();
    }

    /** พอร์ตการลงทุนของผู้ใช้ */
    public function portfolios(): HasMany
    {
        return $this->hasMany(Portfolio::class);
    }

    // ───────────────────────── RBAC ─────────────────────────

    /** เช็คว่า user มีสิทธิ์ใช้กลุ่มเมนูนี้ไหม (ผ่าน role; super role bypass ทุกอย่าง) */
    public function canAccessMenuGroup(string $group): bool
    {
        return $this->role?->canAccessMenuGroup($group) ?? false;
    }

    /** Notification ไทยตอนส่งอีเมล reset password */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new \App\Notifications\ThaiResetPassword($token));
    }
}
