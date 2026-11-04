<?php

namespace Dashed\DashedEcommerceRendabiliteit\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Dashed\DashedEcommerceRendabiliteit\Models\AdvertentieUitgave;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\AdvertentieApiOpslag;

class AdvertentieUitgavenController extends Controller
{
    /** POST /api/advertentie-uitgaven: {"rows": [{"datum": "2026-10-29", "platform": "meta", "bedrag": 24.10}]} */
    public function store(Request $request, AdvertentieApiOpslag $opslag): JsonResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:500'],
            'rows.*.datum' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'rows.*.platform' => ['required', 'string', 'max:50'],
            'rows.*.bedrag' => ['required', 'numeric', 'min:0', 'max:100000'],
        ]);

        return response()->json($opslag->sla($data['rows']));
    }

    /** GET /api/advertentie-uitgaven?van=2026-10-01&tot=2026-10-31: regels plus totaal per platform. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'van' => ['nullable', 'date_format:Y-m-d'],
            'tot' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $van = $data['van'] ?? now()->subDays(30)->toDateString();
        $tot = $data['tot'] ?? now()->toDateString();

        $rijen = AdvertentieUitgave::query()
            ->whereDate('datum', '>=', $van)
            ->whereDate('datum', '<=', $tot)
            ->orderBy('datum')->orderBy('platform')
            ->get(['datum', 'platform', 'bedrag', 'bron']);

        return response()->json([
            'van' => $van,
            'tot' => $tot,
            'totaal_per_platform' => $rijen->groupBy('platform')->map(fn ($groep) => round($groep->sum('bedrag'), 2)),
            'totaal' => round($rijen->sum('bedrag'), 2),
            'rows' => $rijen->map(fn ($r) => [
                'datum' => $r->datum->toDateString(),
                'platform' => $r->platform,
                'bedrag' => $r->bedrag,
                'bron' => $r->bron,
            ]),
        ]);
    }
}
