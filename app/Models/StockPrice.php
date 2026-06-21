<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockPrice extends Model
{
    protected $guarded = [];

    public function stock()
    {
        return $this->belongsTo(Stock::class);
    }
}
