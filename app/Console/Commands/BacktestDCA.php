<?php

namespace App\Console\Commands;

use App\Services\InvestmentService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:backtest-dca {symbol : รหัสหุ้น เช่น PTT.BK หรือ AAPL} {--years=5 : จำนวนปีย้อนหลัง} {--monthly=5000 : เงินลงทุนต่อเดือน} {--no-reinvest : ไม่นำเงินปันผลมาทบต้น}')]
#[Description('จำลองการลงทุนแบบ DCA ย้อนหลัง พร้อมคิดปันผลทบต้น')]
class BacktestDCA extends Command
{
    public function handle(InvestmentService $service): int
    {
        $symbol  = strtoupper($this->argument('symbol'));
        $years   = (int) $this->option('years');
        $monthly = (float) $this->option('monthly');
        $reinvest = !$this->option('no-reinvest');

        $this->info("DCA Backtest: {$symbol} | เดือนละ " . number_format($monthly) . " | ย้อนหลัง {$years} ปี | ปันผลทบต้น: " . ($reinvest ? 'ใช่' : 'ไม่'));
        $this->newLine();

        $result = $service->backtestDCA($symbol, $monthly, $years, 1, $reinvest);

        if (!$result['success']) {
            $this->error($result['error']);
            return self::FAILURE;
        }

        $currency = $result['currency'];
        $this->table(
            ['รายการ', 'มูลค่า'],
            [
                ['เงินลงทุนสะสม', number_format($result['total_invested'], 2) . " {$currency}"],
                ['จำนวนหุ้นรวม', number_format($result['total_shares'], 4) . ' หุ้น'],
                ['เงินปันผลที่ได้รับ', number_format($result['total_dividends_received'], 2) . " {$currency}"],
                ['ราคาหุ้นปัจจุบัน', number_format($result['latest_price'], 2) . " {$currency}"],
                ['มูลค่าพอร์ตปัจจุบัน', number_format($result['portfolio_value'], 2) . " {$currency}"],
                ['กำไร/ขาดทุน (มูลค่า)', number_format($result['profit_loss_value'], 2) . " {$currency}"],
                ['กำไร/ขาดทุน (%)', number_format($result['profit_loss_percentage'], 2) . '%'],
            ]
        );

        return self::SUCCESS;
    }
}
