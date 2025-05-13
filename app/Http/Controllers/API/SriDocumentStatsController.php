<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\SriDocument;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SriDocumentStatsController extends Controller
{
    public function index()
    {
        // Total de documentos
        $totalDocuments = SriDocument::count();

        // Monto total
        $totalAmount = SriDocument::sum('importe_total');

        // Conteo de proveedores
        $providerCount = SriDocument::distinct('ruc_emisor')->count('ruc_emisor');

        // Documentos por proveedor (top 10)
        $byProvider = SriDocument::select('razon_social_emisor as name', DB::raw('count(*) as value'))
            ->groupBy('razon_social_emisor')
            ->orderByDesc('value')
            ->limit(10)
            ->get();

        // Tendencia por mes (últimos 6 meses)
        $startDate = Carbon::now()->subMonths(5)->startOfMonth();

        $byMonth = DB::table('sri_documents')
            ->select(
                DB::raw('DATE_FORMAT(fecha_emision, "%b %Y") as name'),
                DB::raw('SUM(importe_total) as amount'),
                DB::raw('COUNT(*) as count')
            )
            ->where('fecha_emision', '>=', $startDate)
            ->groupBy('name')
            ->orderBy('fecha_emision')
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
