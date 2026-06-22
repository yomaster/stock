<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortfolioHealthCheck extends Model
{
    protected $guarded = [];

    public function portfolio()
    {
        return $this->belongsTo(Portfolio::class);
    }
}
