<?php

namespace App\Console\Commands;

use App\Services\InvestmentService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:analyze-stock {symbol : รหัสหุ้น เช่น PTT.BK หรือ AAPL} {--years=5 : ระยะเวลาลงทุน (ปี)} {--monthly=5000 : เงิน DCA ต่อเดือน} {--initial=0 : เงินลงทุนครั้งแรก}')]
#[Description('วิเคราะห์หุ้นด้วย Gemini AI และคาดการณ์ผลตอบแทน Bull/Base/Bear')]
class AnalyzeStock extends Command
{
    public function handle(InvestmentService $service): int
    {
        $symbol  = strtoupper($this->argument('symbol'));
        $years   = (int) $this->option('years');
        $monthly = (float) $this->option('monthly');
        $initial = (float) $this->option('initial');

        $this->info("กำลังวิเคราะห์ {$symbol} สำหรับการลงทุน {$years} ปี ด้วย Gemini AI...");
        $this->newLine();

        $result = $service->projectFutureAI($symbol, $initial, $monthly, $years);

        if (!$result['success']) {
            $this->error($result['error']);
            return self::FAILURE;
        }

        $currency = $result['currency'];

        $this->line("<fg=yellow>ความเสี่ยง: {$result['risk_score']}/10</>");
        $this->line("<fg=cyan>บทวิเคราะห์: {$result['summary']}</>");
        $this->newLine();

        $rows = [];
        foreach ($result['projections'] as $case => $data) {
            $label = match($case) {
                'bull' => '🚀 Bull Case',
                'base' => '📊 Base Case',
                'bear' => '🐻 Bear Case',
                default => $case,
            };
            $profitSign = $data['profit_loss_value'] >= 0 ? '+' : '';
            $rows[] = [
                $label,
                number_format($data['cagr'], 1) . '% CAGR',
                number_format($data['total_invested'], 0) . " {$currency}",
                number_format($data['future_value'], 0) . " {$currency}",
                $profitSign . number_format($data['profit_loss_value'], 0) . " {$currency} (" . $profitSign . number_format($data['profit_loss_percentage'], 1) . '%)',
                $data['rationale'],
            ];
        }

        $this->table(
            ['กรณี', 'CAGR', 'ลงทุนรวม', 'มูลค่าอนาคต', 'กำไร/ขาดทุน', 'เหตุผล'],
            $rows
        );

        return self::SUCCESS;
    }
}
