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
}
