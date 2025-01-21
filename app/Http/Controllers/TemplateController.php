<?php

namespace App\Http\Controllers;

use App\Exports\TemplateExport;
use Maatwebsite\Excel\Facades\Excel;

class TemplateController extends Controller
{
    public function downloadTemplate()
    {
        return Excel::download(new TemplateExport, 'Plantilla para descuentos masivos - LogeX.xlsx');
    }
}
