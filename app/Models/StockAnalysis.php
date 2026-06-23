<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockAnalysis extends Model
{
    protected $guarded = [];

    protected $casts = [
        'result' => 'array',
        'chart'  => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function stock()
    {
        return $this->belongsTo(Stock::class);
    }
}
