<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Stock extends Model
{
    protected $guarded = [];

    public function prices()
    {
        return $this->hasMany(StockPrice::class);
    }

    public function analysisResults()
    {
        return $this->hasMany(AnalysisResult::class);
    }

    /** ผู้ใช้ที่ติดตามหุ้นนี้ (pivot user_stocks) */
    public function users()
    {
        return $this->belongsToMany(User::class, 'user_stocks')->withTimestamps();
    }
}
