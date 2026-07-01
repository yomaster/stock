<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * แผน DCA (Phase 2) — ดู migration create_plans_table
 * result = ผลคำนวณด้วยสูตร (cache), ai_analysis = บทวิเคราะห์ AI (markdown)
 */
class Plan extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'result'        => 'array',
            'asset_dca'     => 'array',
            'asset_cagr'    => 'array',
            'asset_excluded' => 'array',
            'frequency_days' => 'array',
            'start_date'    => 'date',
            'computed_at'   => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function portfolio()
    {
        return $this->belongsTo(Portfolio::class);
    }
}
