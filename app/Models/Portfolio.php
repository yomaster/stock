<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Portfolio extends Model
{
    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(PortfolioItem::class);
    }

    public function healthChecks()
    {
        return $this->hasMany(PortfolioHealthCheck::class);
    }

    /** ผลวิเคราะห์ล่าสุดของพอร์ตนี้ */
    public function latestHealthCheck()
    {
        return $this->hasOne(PortfolioHealthCheck::class)->latestOfMany();
    }
}
