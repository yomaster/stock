<?php

namespace App\Http\Controllers;

use App\Services\PortfolioService;

/**
 * ภาพรวมรวมทุกพอร์ต (cross-portfolio overview)
 * รวมมูลค่า/กำไรทุกพอร์ตของ user ในหน้าเดียว + แยกตามชนิด + สินทรัพย์รวมข้ามพอร์ต
 */
class OverviewController extends Controller
{
    public function __construct(private PortfolioService $svc) {}

    public function index()
    {
        $portfolios = auth()->user()->portfolios()->with('items')->orderBy('name')->get();
        $overview   = $this->svc->crossPortfolioOverview($portfolios);

        return view('overview.index', [
            'overview' => $overview,
            'rate'     => $this->svc->currentFx(),
        ]);
    }
}
