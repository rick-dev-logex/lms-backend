<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\SriDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class StatsController extends Controller
{
    /**
     * Obtiene estadísticas para el dashboard
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        // Total de documentos
        $totalDocuments = SriDocument::count();

        // Monto total
        $totalAmount = SriDocument::sum('importe_total');

        // Conteo de proveedores únicos
        $providerCount = SriDocument::select('ruc_emisor')->distinct()->count('ruc_emisor');

        // Documentos por proveedor (top 10)
        $byProvider = SriDocument::select(
            'razon_social_emisor as name',
            'ruc_emisor as ruc',
            DB::raw('COUNT(*) as value'),
            DB::raw('SUM(importe_total) as amount')
        )
            ->groupBy('razon_social_emisor', 'ruc_emisor')
            ->orderByDesc('value')
            ->limit(10)
            ->get();

        // Documentos por mes (últimos 6 meses)
        $sixMonthsAgo = Carbon::now()->subMonths(5)->startOfMonth();

        $byMonth = SriDocument::select(
            DB::raw('DATE_FORMAT(fecha_emision, "%b %Y") as name'),
            DB::raw('MONTH(fecha_emision) as month_num'),
            DB::raw('YEAR(fecha_emision) as year_num'),
            DB::raw('SUM(importe_total) as amount'),
            DB::raw('COUNT(*) as count')
        )
            ->where('fecha_emision', '>=', $sixMonthsAgo)
            ->groupBy('name', 'month_num', 'year_num')
            ->orderBy('year_num')
            ->orderBy('month_num')
            ->get();

        return response()->json([
            'totalDocuments' => $totalDocuments,
            'totalAmount' => (float) $totalAmount,
            'providerCount' => $providerCount,
            'byProvider' => $byProvider,
            'byMonth' => $byMonth
        ]);
    }
}
