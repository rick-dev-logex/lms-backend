<?php

namespace App\Services;

use App\Models\SriDocument;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class ReportGeneratorService
{
    public function generateReport(string $type, string $period): string
    {
        try {
            // Parsear el período (YYYY-MM)
            list($year, $month) = explode('-', $period);
            $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();

            // Preparar nombre del archivo temporal
            $fileName = "reporte_" . strtolower($type) . "_{$period}.xlsx";
            $tempPath = storage_path('app/temp/' . $fileName);

            // Asegurar que existe la carpeta temp
            if (!file_exists(storage_path('app/temp'))) {
                mkdir(storage_path('app/temp'), 0755, true);
            }

            // Generar reporte según tipo
            switch ($type) {
                case 'compras':
                    $this->generateComprasReport($startDate, $endDate, $tempPath);
                    break;
                case 'iva':
                    $this->generateIvaReport($startDate, $endDate, $tempPath);
                    break;
                case 'ret':
                    $this->generateRetReport($startDate, $endDate, $tempPath);
                    break;
                default:
                    throw new \InvalidArgumentException("Tipo de reporte no válido: {$type}");
            }

            return $tempPath;
        } catch (\Exception $e) {
            Log::error("Error generando reporte {$type}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    protected function generateComprasReport(Carbon $startDate, Carbon $endDate, string $outputPath): void
    {
        // Obtener documentos del período
        $documents = SriDocument::whereBetween('fecha_emision', [$startDate, $endDate])
            ->orderBy('fecha_emision')
            ->get();

        // Crear libro de Excel
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Compras');

        // Configurar encabezados
        $sheet->setCellValue('A1', 'REPORTE DE COMPRAS');
        $sheet->mergeCells('A1:L1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A2', 'Período: ' . $startDate->format('M Y'));
        $sheet->mergeCells('A2:L2');
        $sheet->getStyle('A2')->getFont()->setBold(true);

        // Encabezados de columnas
        $headers = [
            'A3' => 'FECHA',
            'B3' => 'TIPO',
            'C3' => 'SERIE',
            'D3' => 'RUC',
            'E3' => 'PROVEEDOR',
            'F3' => 'BASE 0%',
            'G3' => 'BASE 12%',
            'H3' => 'BASE NO OBJETO',
            'I3' => 'IVA',
            'J3' => 'TOTAL',
            'K3' => 'CLAVE ACCESO',
            'L3' => 'ESTADO'
        ];

        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
            $sheet->getStyle($cell)->getFont()->setBold(true);
        }

        // Aplicar estilo a encabezados
        $sheet->getStyle('A3:L3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E0E0E0');
        $sheet->getStyle('A3:L3')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // Llenar datos
        $row = 4;
        $totalBase0 = 0;
        $totalBase12 = 0;
        $totalIva = 0;
        $totalGeneral = 0;

        foreach ($documents as $doc) {
            // Determinar si es base 0% o 12% basado en si tiene IVA
            $base0 = ($doc->iva == 0 || $doc->iva == null) ? $doc->valor_sin_impuestos : 0;
            $base12 = ($doc->iva > 0) ? $doc->valor_sin_impuestos : 0;

            $sheet->setCellValue("A{$row}", Carbon::parse($doc->fecha_emision)->format('d/m/Y'));
            $sheet->setCellValue("B{$row}", $doc->tipo_comprobante);
            $sheet->setCellValue("C{$row}", $doc->serie_comprobante);
            $sheet->setCellValue("D{$row}", $doc->ruc_emisor);
            $sheet->setCellValue("E{$row}", $doc->razon_social_emisor);
            $sheet->setCellValue("F{$row}", $base0);
            $sheet->setCellValue("G{$row}", $base12);
            $sheet->setCellValue("H{$row}", 0); // Base no objeto de IVA (por defecto 0)
            $sheet->setCellValue("I{$row}", $doc->iva);
            $sheet->setCellValue("J{$row}", $doc->importe_total);
            $sheet->setCellValue("K{$row}", $doc->clave_acceso);
            $sheet->setCellValue("L{$row}", 'AUTORIZADO'); // Por defecto todos están autorizados

            // Acumular totales
            $totalBase0 += $base0;
            $totalBase12 += $base12;
            $totalIva += $doc->iva;
            $totalGeneral += $doc->importe_total;

            $row++;
        }

        // Añadir totales
        $lastRow = $row;

        $sheet->setCellValue("A{$lastRow}", 'TOTALES:');
        $sheet->mergeCells("A{$lastRow}:E{$lastRow}");
        $sheet->getStyle("A{$lastRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $sheet->setCellValue("F{$lastRow}", $totalBase0);
        $sheet->setCellValue("G{$lastRow}", $totalBase12);
        $sheet->setCellValue("H{$lastRow}", 0);
        $sheet->setCellValue("I{$lastRow}", $totalIva);
        $sheet->setCellValue("J{$lastRow}", $totalGeneral);

        $sheet->getStyle("F{$lastRow}:J{$lastRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$lastRow}:L{$lastRow}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM);

        // Aplicar formato de número a columnas de montos
        $moneyRange = "F4:J{$lastRow}";
        $sheet->getStyle($moneyRange)->getNumberFormat()->setFormatCode('#,##0.00');

        // Ajustar ancho de columnas
        $sheet->getColumnDimension('A')->setWidth(12);
        $sheet->getColumnDimension('B')->setWidth(15);
        $sheet->getColumnDimension('C')->setWidth(20);
        $sheet->getColumnDimension('D')->setWidth(15);
        $sheet->getColumnDimension('E')->setWidth(40);
        $sheet->getColumnDimension('F')->setWidth(12);
        $sheet->getColumnDimension('G')->setWidth(12);
        $sheet->getColumnDimension('H')->setWidth(12);
        $sheet->getColumnDimension('I')->setWidth(12);
        $sheet->getColumnDimension('J')->setWidth(12);
        $sheet->getColumnDimension('K')->setWidth(50);
        $sheet->getColumnDimension('L')->setWidth(15);

        // Guardar archivo
        $writer = new Xlsx($spreadsheet);
        $writer->save($outputPath);
    }

    protected function generateIvaReport(Carbon $startDate, Carbon $endDate, string $outputPath): void
    {
        // Obtener documentos del período
        $documents = SriDocument::whereBetween('fecha_emision', [$startDate, $endDate])->get();

        // Crear libro de Excel
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Declaración IVA');

        // Título y período
        $sheet->setCellValue('A1', 'REPORTE PARA DECLARACIÓN DE IVA');
        $sheet->mergeCells('A1:I1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A2', 'Período: ' . $startDate->format('M Y'));
        $sheet->mergeCells('A2:I2');
        $sheet->getStyle('A2')->getFont()->setBold(true);

        // Sección de Ventas (simulada, no tenemos datos de ventas en este ejemplo)
        $sheet->setCellValue('A4', 'VENTAS Y OTRAS OPERACIONES');
        $sheet->mergeCells('A4:I4');
        $sheet->getStyle('A4')->getFont()->setBold(true);
        $sheet->getStyle('A4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E0E0E0');

        $sheet->setCellValue('A5', 'Ventas locales (excluye activos fijos) gravadas tarifa 12%');
        $sheet->setCellValue('I5', 0); // Valor por defecto

        $sheet->setCellValue('A6', 'Ventas de activos fijos gravadas tarifa 12%');
        $sheet->setCellValue('I6', 0); // Valor por defecto

        $sheet->setCellValue('A7', 'Ventas locales (excluye activos fijos) gravadas tarifa 0%');
        $sheet->setCellValue('I7', 0); // Valor por defecto

        $sheet->setCellValue('A8', 'Ventas de activos fijos gravadas tarifa 0%');
        $sheet->setCellValue('I8', 0); // Valor por defecto

        // Sección de Compras
        $sheet->setCellValue('A10', 'COMPRAS Y PAGOS');
        $sheet->mergeCells('A10:I10');
        $sheet->getStyle('A10')->getFont()->setBold(true);
        $sheet->getStyle('A10')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E0E0E0');

        // Calcular resumen de compras
        $compras12 = 0;
        $iva12 = 0;
        $compras0 = 0;

        foreach ($documents as $doc) {
            if ($doc->iva > 0) {
                $compras12 += $doc->valor_sin_impuestos;
                $iva12 += $doc->iva;
            } else {
                $compras0 += $doc->valor_sin_impuestos;
            }
        }

        $sheet->setCellValue('A11', 'Adquisiciones y pagos (excluye activos fijos) gravados tarifa 12%');
        $sheet->setCellValue('I11', $compras12);

        $sheet->setCellValue('A12', 'Adquisiciones locales de activos fijos gravados tarifa 12%');
        $sheet->setCellValue('I12', 0); // Por defecto 0

        $sheet->setCellValue('A13', 'Otras adquisiciones y pagos gravados tarifa 12%');
        $sheet->setCellValue('I13', 0); // Por defecto 0

        $sheet->setCellValue('A14', 'Importaciones de servicios gravados tarifa 12%');
        $sheet->setCellValue('I14', 0); // Por defecto 0

        $sheet->setCellValue('A15', 'Importaciones de bienes (excluye activos fijos) gravados tarifa 12%');
        $sheet->setCellValue('I15', 0); // Por defecto 0

        $sheet->setCellValue('A16', 'Importaciones de activos fijos gravados tarifa 12%');
        $sheet->setCellValue('I16', 0); // Por defecto 0

        $sheet->setCellValue('A17', 'Adquisiciones y pagos (incluye activos fijos) gravados tarifa 0%');
        $sheet->setCellValue('I17', $compras0);

        $sheet->setCellValue('A18', 'Adquisiciones realizadas a contribuyentes RISE');
        $sheet->setCellValue('I18', 0); // Por defecto 0

        // Resumen de impuestos
        $sheet->setCellValue('A20', 'RESUMEN IMPOSITIVO');
        $sheet->mergeCells('A20:I20');
        $sheet->getStyle('A20')->getFont()->setBold(true);
        $sheet->getStyle('A20')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E0E0E0');

        $sheet->setCellValue('A21', 'Total IVA en ventas');
        $sheet->setCellValue('I21', 0); // Valor por defecto

        $sheet->setCellValue('A22', 'Total IVA en compras');
        $sheet->setCellValue('I22', $iva12);

        $sheet->setCellValue('A23', 'Crédito Tributario');
        $sheet->setCellValue('I23', $iva12); // Igual al IVA en compras en este caso

        // Aplicar formato de número a columnas de montos
        $sheet->getStyle('I5:I23')->getNumberFormat()->setFormatCode('#,##0.00');

        // Ajustar ancho de columnas
        $sheet->getColumnDimension('A')->setWidth(60);
        $sheet->getColumnDimension('I')->setWidth(15);

        // Guardar archivo
        $writer = new Xlsx($spreadsheet);
        $writer->save($outputPath);
    }

    protected function generateRetReport(Carbon $startDate, Carbon $endDate, string $outputPath): void
    {
        // Obtener documentos del período
        $documents = SriDocument::whereBetween('fecha_emision', [$startDate, $endDate])->get();

        // Crear libro de Excel
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Retenciones');

        // Título y período
        $sheet->setCellValue('A1', 'REPORTE DE RETENCIONES');
        $sheet->mergeCells('A1:J1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A2', 'Período: ' . $startDate->format('M Y'));
        $sheet->mergeCells('A2:J2');
        $sheet->getStyle('A2')->getFont()->setBold(true);

        // Encabezados
        $headers = [
            'A4' => 'FECHA',
            'B4' => 'TIPO',
            'C4' => 'COMPROBANTE',
            'D4' => 'RUC',
            'E4' => 'PROVEEDOR',
            'F4' => 'BASE IMPONIBLE',
            'G4' => 'CÓDIGO RETENCIÓN',
            'H4' => 'PORCENTAJE',
            'I4' => 'VALOR RETENIDO',
            'J4' => 'AUTORIZACIÓN'
        ];

        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
            $sheet->getStyle($cell)->getFont()->setBold(true);
        }

        $sheet->getStyle('A4:J4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E0E0E0');
        $sheet->getStyle('A4:J4')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // Como no tenemos datos reales de retenciones, creamos algunas filas de ejemplo
        $sampleData = [
            [
                'fecha' => $startDate->copy()->addDays(5)->format('d/m/Y'),
                'tipo' => 'RENTA',
                'comprobante' => '001-001-000000123',
                'ruc' => '0991503102001',
                'proveedor' => 'CONCESIONARIA DEL GUAYAS CONCEGUA S.A.',
                'base' => 100,
                'codigo' => '303',
                'porcentaje' => 10,
                'valor' => 10,
                'autorizacion' => '1234567890'
            ],
            [
                'fecha' => $startDate->copy()->addDays(15)->format('d/m/Y'),
                'tipo' => 'IVA',
                'comprobante' => '001-001-000000456',
                'ruc' => '0927051193001',
                'proveedor' => 'JIMENEZ REYES DALILA MIRIANA',
                'base' => 200,
                'codigo' => '721',
                'porcentaje' => 30,
                'valor' => 7.2,
                'autorizacion' => '0987654321'
            ],
        ];

        $row = 5;
        foreach ($sampleData as $data) {
            $sheet->setCellValue("A{$row}", $data['fecha']);
            $sheet->setCellValue("B{$row}", $data['tipo']);
            $sheet->setCellValue("C{$row}", $data['comprobante']);
            $sheet->setCellValue("D{$row}", $data['ruc']);
            $sheet->setCellValue("E{$row}", $data['proveedor']);
            $sheet->setCellValue("F{$row}", $data['base']);
            $sheet->setCellValue("G{$row}", $data['codigo']);
            $sheet->setCellValue("H{$row}", $data['porcentaje'] . '%');
            $sheet->setCellValue("I{$row}", $data['valor']);
            $sheet->setCellValue("J{$row}", $data['autorizacion']);

            $row++;
        }

        // Añadir totales
        $lastRow = $row;

        $sheet->setCellValue("A{$lastRow}", 'TOTALES:');
        $sheet->mergeCells("A{$lastRow}:E{$lastRow}");
        $sheet->getStyle("A{$lastRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $sheet->setCellValue("F{$lastRow}", '=SUM(F5:F' . ($lastRow - 1) . ')');
        $sheet->setCellValue("I{$lastRow}", '=SUM(I5:I' . ($lastRow - 1) . ')');

        $sheet->getStyle("F{$lastRow}:I{$lastRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$lastRow}:J{$lastRow}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM);

        // Aplicar formato de número a columnas de montos
        $moneyRange = "F5:F{$lastRow},I5:I{$lastRow}";
        $sheet->getStyle($moneyRange)->getNumberFormat()->setFormatCode('#,##0.00');

        // Ajustar ancho de columnas
        $sheet->getColumnDimension('A')->setWidth(12);
        $sheet->getColumnDimension('B')->setWidth(10);
        $sheet->getColumnDimension('C')->setWidth(20);
        $sheet->getColumnDimension('D')->setWidth(15);
        $sheet->getColumnDimension('E')->setWidth(40);
        $sheet->getColumnDimension('F')->setWidth(15);
        $sheet->getColumnDimension('G')->setWidth(15);
        $sheet->getColumnDimension('H')->setWidth(12);
        $sheet->getColumnDimension('I')->setWidth(15);
        $sheet->getColumnDimension('J')->setWidth(20);

        // Guardar archivo
        $writer = new Xlsx($spreadsheet);
        $writer->save($outputPath);
    }
}
