<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Portfolio extends Model
{
    protected $guarded = [];

    public function items()
    {
        return $this->hasMany(PortfolioItem::class);
    }
}
