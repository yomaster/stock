<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'stock_id']); // กันติดตามหุ้นเดียวกันซ้ำ
        });

        // backfill: หุ้นเดิมทั้งหมด (ก่อนมีระบบ user) → ผูกให้ user คนแรก (admin)
        $firstUserId = DB::table('users')->orderBy('id')->value('id');
        if ($firstUserId) {
            $now = now();
            $rows = DB::table('stocks')->pluck('id')->map(fn ($sid) => [
                'user_id'    => $firstUserId,
                'stock_id'   => $sid,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();
            if (!empty($rows)) {
                DB::table('user_stocks')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_stocks');
    }
};
