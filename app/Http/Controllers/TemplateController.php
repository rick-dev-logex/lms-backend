<?php

namespace App\Http\Controllers;

use App\Exports\TemplateExport;
use Maatwebsite\Excel\Facades\Excel;

class TemplateController extends Controller
{
    public function downloadDiscountsTemplate()
    {
        return Excel::download(new TemplateExport('discounts'), 'plantilla_descuentos.xlsx');
    }

    public function downloadExpensesTemplate()
    {
        return Excel::download(new TemplateExport('expenses'), 'plantilla_gastos.xlsx');
    }
}
