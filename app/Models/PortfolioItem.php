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
        'purchase_date'   => 'date',
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
