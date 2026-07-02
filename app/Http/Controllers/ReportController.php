<?php

namespace App\Http\Controllers;

use App\Services\PortfolioService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * รายงานภาษีรายปี (cross-portfolio)
 * - Realized P/L รายปี (จากการขาย) ทุกสินทรัพย์
 * - ยอดซื้อกองลดหย่อน RMF/SSF/ThaiESG รายปี (ไว้ยื่นลดหย่อน)
 * + export CSV (UTF-8 BOM เปิดใน Excel ไทยได้)
 */
class ReportController extends Controller
{
    public function __construct(private PortfolioService $svc) {}

    public function index()
    {
        $report = $this->svc->realizedReport($this->portfolios());

        return view('report.index', [
            'report' => $report,
            'rate'   => $this->svc->currentFx(),
        ]);
    }

    /** ดาวน์โหลด CSV — ?type=realized (การขาย) หรือ contrib (ยอดลงทุนลดหย่อน) */
    public function export(Request $request): StreamedResponse
    {
        $type   = $request->query('type') === 'contrib' ? 'contrib' : 'realized';
        $report = $this->svc->realizedReport($this->portfolios());

        [$filename, $header, $rows] = $type === 'contrib'
            ? $this->contribCsv($report['contrib_detail'])
            : $this->realizedCsv($report['realized_detail']);

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM — ให้ Excel อ่านภาษาไทยถูก
            fputcsv($out, $header);
            foreach ($rows as $r) {
                fputcsv($out, $r);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ───────────────────────── helpers ─────────────────────────

    private function portfolios()
    {
        return auth()->user()->portfolios()->with('items')->get();
    }

    /** ปี พ.ศ. จากปี ค.ศ. */
    private function be(int $ce): int
    {
        return $ce + 543;
    }

    private function realizedCsv(array $detail): array
    {
        $header = ['ปีภาษี (พ.ศ.)', 'วันที่', 'พอร์ต', 'สินทรัพย์', 'ชื่อ', 'จำนวน', 'เงินที่ได้ (บาท)', 'ต้นทุน (บาท)', 'กำไร/ขาดทุน (บาท)'];
        $rows = [];
        foreach ($detail as $d) {
            $rows[] = [
                $this->be($d['year_ce']), $d['date'], $d['portfolio'], $d['symbol'], $d['name'],
                rtrim(rtrim(number_format($d['shares'], 7, '.', ''), '0'), '.'),
                number_format($d['proceeds_thb'], 2, '.', ''),
                number_format($d['cost_thb'], 2, '.', ''),
                number_format($d['pl_thb'], 2, '.', ''),
            ];
        }
        return ['realized-pl-' . now()->format('Ymd') . '.csv', $header, $rows];
    }

    private function contribCsv(array $detail): array
    {
        $header = ['ปีภาษี (พ.ศ.)', 'วันที่', 'พอร์ต', 'สินทรัพย์', 'ชื่อ', 'ประเภท', 'ยอดลงทุน (บาท)'];
        $rows = [];
        foreach ($detail as $d) {
            $rows[] = [
                $this->be($d['year_ce']), $d['date'], $d['portfolio'], $d['symbol'], $d['name'],
                $d['tax_type'], number_format($d['amount_thb'], 2, '.', ''),
            ];
        }
        return ['tax-deduction-' . now()->format('Ymd') . '.csv', $header, $rows];
    }
}
