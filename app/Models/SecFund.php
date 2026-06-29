<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Catalog กองทุน SEC สำหรับค้นหา (sync ผ่าน app:sync-fund-catalog) */
class SecFund extends Model
{
    protected $guarded = [];
}
