<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortfolioItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'shares'          => 'float',
        'purchase_price'  => 'float',
        'invested_amount' => 'float',
        'fx_rate'         => 'float',
        'purchase_date'   => 'date',
        'executed_at'     => 'datetime',
    ];

    public function portfolio()
    {
        return $this->belongsTo(Portfolio::class);
    }

    public function stock()
    {
        return $this->belongsTo(Stock::class);
    }
}
