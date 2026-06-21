<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stock_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->onDelete('cascade');
            $table->date('date');
            $table->decimal('open', 15, 4)->nullable();
            $table->decimal('high', 15, 4)->nullable();
            $table->decimal('low', 15, 4)->nullable();
            $table->decimal('close', 15, 4);
            $table->decimal('adj_close', 15, 4)->nullable();
            $table->bigInteger('volume')->nullable();
            $table->decimal('dividends', 15, 4)->default(0.0000);
            $table->timestamps();

            $table->unique(['stock_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_prices');
    }
};
