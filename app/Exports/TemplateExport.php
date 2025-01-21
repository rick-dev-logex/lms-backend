<?php

namespace App\Exports;

use App\Models\Account;
use App\Models\User;
use App\Models\Transport;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TemplateExport implements FromCollection, WithHeadings, WithEvents, WithTitle
{
    public function title(): string
    {
        return 'Plantilla de descuentos masivos';
    }

    public function headings(): array
    {
        return [
            'Fecha (YYYY-MM-DD)',
            'Número de Factura',
            'Cuenta',
            'Monto',
            'Proyecto',
            'Responsable',
            'Transportista',
            'Observaciones'
        ];
    }

    public function collection()
    {
        return new Collection();
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $worksheet = $event->sheet->getDelegate();

                // Obtener los datos
                $accounts = Account::select('id', 'name')
                    ->orderBy('name')
                    ->pluck('name')
                    ->map(function ($name) {
                        return str_replace(',', ' ', $name); // Evitar problemas con comas
                    })->toArray();

                $projects = DB::connection('onix')
                    ->table('onix_personal')
                    ->select('proyecto')
                    ->distinct()
                    ->whereNotNull('proyecto')
                    ->where('proyecto', '<>', '')
                    ->orderBy('proyecto')
                    ->pluck('proyecto')
                    ->map(function ($name) {
                        return str_replace(',', ' ', $name);
                    })->toArray();

                $users = User::select('id', 'name')
                    ->orderBy('name')
                    ->pluck('name')
                    ->map(function ($name) {
                        return str_replace(',', ' ', $name);
                    })->toArray();

                $transports = Transport::select('id', 'placa')
                    ->whereNotNull('placa')
                    ->where('placa', '<>', '')
                    ->orderBy('placa')
                    ->pluck('placa')
                    ->map(function ($name) {
                        return str_replace(',', ' ', $name);
                    })->toArray();

                // Crear validaciones
                $validations = [
                    'C' => $accounts,
                    'E' => $projects,
                    'F' => $users,
                    'G' => $transports
                ];

                foreach ($validations as $column => $values) {
                    // Convertir array a string para la validación
                    $validationList = implode(',', array_map(function ($value) {
                        return '"' . str_replace('"', '""', $value) . '"';
                    }, $values));

                    // Aplicar validación a cada celda
                    for ($row = 2; $row <= 1000; $row++) {
                        $validation = $worksheet->getCell($column . $row)->getDataValidation();
                        $validation->setType(DataValidation::TYPE_LIST);
                        $validation->setErrorStyle(DataValidation::STYLE_STOP);
                        $validation->setAllowBlank(false);
                        $validation->setShowInputMessage(true);
                        $validation->setShowErrorMessage(true);
                        $validation->setErrorTitle('Error');
                        $validation->setError('Por favor seleccione un valor de la lista');
                        $validation->setFormula1($validationList);
                    }
                }

                // Formato de fecha
                $worksheet->getStyle('A2:A1000')
                    ->getNumberFormat()
                    ->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);

                // Formato de número para monto
                $worksheet->getStyle('D2:D1000')
                    ->getNumberFormat()
                    ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2);

                // Estilos de cabecera
                $worksheet->getStyle('A1:H1')->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => [
                        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'E2E8F0']
                    ]
                ]);

                // Ajustar ancho de columnas
                foreach (range('A', 'H') as $col) {
                    $worksheet->getColumnDimension($col)->setAutoSize(true);
                }
            }
        ];
    }
}
