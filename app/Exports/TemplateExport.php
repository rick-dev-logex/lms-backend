<?php

namespace App\Exports;

use App\Models\Account;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class TemplateExport implements FromCollection, WithHeadings, WithEvents, WithTitle
{
    private const COLOR_PRIMARY = '941e1e';
    private const COLOR_SECONDARY = '1E2837';
    private const COLOR_LIGHT = 'F8FAFC';

    private $context;
    private $projectNames;

    public function __construct(string $context, array $projectNames = ['Sin proyectos asignados'])
    {
        $this->context = $context;
        $this->projectNames = $projectNames;
    }

    public function title(): string
    {
        // Log::info('Incoming context:' . $this->context);
        return match ($this->context) {
            'expenses' => "Plantilla de Gastos",
            "discounts" => "Plantilla de Descuentos",
            "income" => "Plantilla de Ingresos",
            default => `Plantilla de  . $this->context`,
        };
    }

    public function headings(): array
    {
        return [
            'Fecha',
            'Tipo',
            'No. Factura',
            'Cuenta',
            'Valor',
            'Proyecto',
            'Responsable',
            'Placa',
            'Cédula',
            'Observación'
        ];
    }

    public function collection()
    {
        return new Collection([
            [
                '2025-01-31',
                'Nómina',
                'F001',
                'Alimentación',
                '100.00',
                $this->projectNames[0],
                'ALARCON LARREA CARLOS JULIO',
                '',
                "1734567890",
                'Ejemplo - Puedes eliminar esta fila.'
            ]
        ]);
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet;
                $lastRow = 100;

                // Título y descripción
                $sheet->insertNewRowBefore(1, 2);
                $sheet->mergeCells('A1:J1');
                $sheet->mergeCells('A2:J2');
                $sheet->setCellValue('A1', $this->context === 'discounts' ? 'PLANTILLA DE DESCUENTOS' : ($this->context === "expenses" ? 'PLANTILLA DE GASTOS' : "PLANTILLA DE INGRESOS"));
                $sheet->setCellValue('A2', '📝 Completa una fila por cada ' . ($this->context === 'discounts' ? 'descuento' : ($this->context === "expenses" ? 'gasto' : "ingreso")) . ' que quieras registrar');

                // Estilo del título principal
                $sheet->getStyle('A1')->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'size' => 16,
                        'color' => ['rgb' => self::COLOR_LIGHT]
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER
                    ],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => self::COLOR_PRIMARY]
                    ]
                ]);
                $sheet->getRowDimension(1)->setRowHeight(38);

                // Estilo de la descripción
                $sheet->getStyle('A2')->applyFromArray([
                    'font' => [
                        'size' => 11,
                        'color' => ['rgb' => '64748B']
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER
                    ],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => self::COLOR_LIGHT]
                    ]
                ]);

                // Estilo de cabeceras
                $sheet->getStyle('A3:J3')->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'color' => ['rgb' => self::COLOR_LIGHT]
                    ],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => self::COLOR_SECONDARY]
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER
                    ]
                ]);

                // Validaciones
                $this->setupDataValidations($sheet, $lastRow);

                // Estilos de las celdas de datos
                $dataRange = "A4:I$lastRow";
                $sheet->getStyle($dataRange)->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => 'E2E8F0']
                        ]
                    ],
                    'alignment' => [
                        'vertical' => Alignment::VERTICAL_CENTER
                    ]
                ]);

                // Filas alternas
                for ($row = 4; $row <= $lastRow; $row += 2) {
                    $sheet->getStyle("A$row:I$row")->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->setStartColor(new Color(self::COLOR_LIGHT));
                }

                // Formatos específicos
                $sheet->getStyle("A4:A$lastRow")
                    ->getNumberFormat()
                    ->setFormatCode('yyyy-mm-dd');

                $sheet->getStyle("E4:E$lastRow")
                    ->getNumberFormat()
                    ->setFormatCode('#,##0.00');

                $sheet->getSheetView()
                    ->setZoomScale(100)
                    ->setZoomScaleNormal(100)
                    ->setView('pageLayout');

                $sheet->getStyle("I4:I$lastRow")
                    ->getNumberFormat()
                    ->setFormatCode('@');

                $sheet->setSelectedCell('A4');

                // Anchos de columna optimizados
                $columnWidths = [
                    'A' => 12,  // Fecha
                    'B' => 15,  // Tipo
                    'C' => 15,  // No. Factura
                    'D' => 35,  // Cuenta
                    'E' => 12,  // Valor
                    'F' => 20,  // Proyecto
                    'G' => 30,  // Responsable
                    'H' => 15,  // Placa
                    'I' => 15,   // Cédula
                    'J' => 40   // Observación
                ];

                foreach ($columnWidths as $col => $width) {
                    $sheet->getColumnDimension($col)->setWidth($width);
                }

                // Comentarios amigables
                $this->setupFriendlyComments($sheet);

                // Congelar paneles
                $sheet->freezePane('A4');
            }
        ];
    }

    private function getAccounts(): array
    {
        $query = Account::where('account_status', 'active');

        if ($this->context === 'discounts') {
            $query->where('account_affects', 'discount')->orWhere('account_affects', 'both');
        } elseif ($this->context === 'expenses') {
            $query->where('account_affects', 'expense')->orWhere('account_affects', 'both');
        } elseif ($this->context === 'income') {
            $query->where('account_affects', 'expense')->orWhere('account_affects', 'discount')->orWhere('account_affects', 'both');
        }

        return $query->pluck('name')->toArray();
    }

    private function setupDataValidations($sheet, $lastRow)
    {
        $tipoValidation = $sheet->getCell('B4')->getDataValidation();
        $tipoValidation->setType(DataValidation::TYPE_LIST);
        $tipoValidation->setAllowBlank(false);
        $tipoValidation->setShowDropDown(true);
        $tipoValidation->setFormula1('"Nómina,Transportista"');
        $tipoValidation->setShowErrorMessage(true);
        $tipoValidation->setErrorTitle('¡Ups! Tipo no válido');
        $tipoValidation->setError('Por favor, selecciona "Nómina" o "Transportista"');
        $sheet->setDataValidation("B4:B$lastRow", $tipoValidation);

        $dateValidation = $sheet->getCell('A4')->getDataValidation();
        $dateValidation->setType(DataValidation::TYPE_DATE);
        $dateValidation->setAllowBlank(false);
        $dateValidation->setShowErrorMessage(true);
        $dateValidation->setErrorTitle('Fecha incorrecta');
        $dateValidation->setError('Ingresa la fecha en formato YYYY-MM-DD');
        $sheet->setDataValidation("A4:A$lastRow", $dateValidation);

        $valorValidation = $sheet->getCell('E4')->getDataValidation();
        $valorValidation->setType(DataValidation::TYPE_DECIMAL);
        $valorValidation->setAllowBlank(false);
        $valorValidation->setShowErrorMessage(true);
        $valorValidation->setErrorTitle('Valor no válido');
        $valorValidation->setError('El valor debe ser mayor a 0');
        $valorValidation->setFormula1('0');
        $valorValidation->setOperator(DataValidation::OPERATOR_GREATERTHAN);
        $sheet->setDataValidation("E4:E$lastRow", $valorValidation);

        // Hoja auxiliar para cuentas
        $spreadsheet = $sheet->getDelegate()->getParent();
        $accountSheet = $spreadsheet->createSheet();
        $accountSheet->setTitle('Cuentas');
        $accounts = $this->getAccounts();
        foreach ($accounts as $index => $account) {
            $accountSheet->setCellValue("A" . ($index + 1), $account);
        }
        $accountCount = count($accounts) ?: 1;
        $accountValidation = $sheet->getCell('D4')->getDataValidation();
        $accountValidation->setType(DataValidation::TYPE_LIST);
        $accountValidation->setAllowBlank(false);
        $accountValidation->setShowDropDown(true);
        $accountValidation->setFormula1("Cuentas!\$A\$1:\$A\$$accountCount");
        $accountValidation->setShowErrorMessage(true);
        $accountValidation->setErrorTitle('Cuenta no válida');
        $accountValidation->setError('Por favor, selecciona una cuenta de la lista');
        $sheet->setDataValidation("D4:D$lastRow", $accountValidation);
        $accountSheet->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN);

        // Hoja auxiliar para proyectos
        $projectSheet = $spreadsheet->createSheet();
        $projectSheet->setTitle('Proyectos');
        foreach ($this->projectNames as $index => $project) {
            $projectSheet->setCellValue("A" . ($index + 1), $project);
        }
        $projectCount = count($this->projectNames) ?: 1;
        $projectValidation = $sheet->getCell('F4')->getDataValidation();
        $projectValidation->setType(DataValidation::TYPE_LIST);
        $projectValidation->setAllowBlank(false);
        $projectValidation->setShowDropDown(true);
        $projectValidation->setFormula1("Proyectos!\$A\$1:\$A\$$projectCount");
        $projectValidation->setShowErrorMessage(true);
        $projectValidation->setErrorTitle('Proyecto no válido');
        $projectValidation->setError('Por favor, selecciona un proyecto de la lista');
        $sheet->setDataValidation("F4:F$lastRow", $projectValidation);
        $projectSheet->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN);

        $responsableValidation = $sheet->getCell("G4")->getDataValidation();
        $responsableValidation->setType(DataValidation::TYPE_CUSTOM);
        $responsableValidation->setErrorTitle('Campo no aplicable');
        $responsableValidation->setError('Este campo solo aplica cuando el tipo es Nómina');
        $responsableValidation->setFormula1('INDIRECT("B4")="Nómina"');
        $responsableValidation->setShowErrorMessage(true);

        $placaValidation = $sheet->getCell("H4")->getDataValidation();
        $placaValidation->setType(DataValidation::TYPE_CUSTOM);
        $placaValidation->setErrorTitle('Campo no aplicable');
        $placaValidation->setError('Este campo solo aplica cuando el tipo es Transportista');
        $placaValidation->setFormula1('INDIRECT("B4")="Transportista"');
        $placaValidation->setShowErrorMessage(true);

        $conditionalStyleResponsable = new \PhpOffice\PhpSpreadsheet\Style\Conditional();
        $conditionalStyleResponsable->setConditionType(\PhpOffice\PhpSpreadsheet\Style\Conditional::CONDITION_EXPRESSION)
            ->setOperatorType(\PhpOffice\PhpSpreadsheet\Style\Conditional::OPERATOR_EQUAL)
            ->addCondition('=B4="Transportista"')
            ->getStyle()->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->setStartColor(new Color('EEEEEE'));

        $conditionalStylePlaca = new \PhpOffice\PhpSpreadsheet\Style\Conditional();
        $conditionalStylePlaca->setConditionType(\PhpOffice\PhpSpreadsheet\Style\Conditional::CONDITION_EXPRESSION)
            ->setOperatorType(\PhpOffice\PhpSpreadsheet\Style\Conditional::OPERATOR_EQUAL)
            ->addCondition('=B4="Nómina"')
            ->getStyle()->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->setStartColor(new Color('EEEEEE'));

        $sheet->getStyle("G4:G$lastRow")->setConditionalStyles([$conditionalStyleResponsable]);
        $sheet->getStyle("H4:H$lastRow")->setConditionalStyles([$conditionalStylePlaca]);
    }

    private function setupFriendlyComments($sheet)
    {
        $comments = [
            'A3' => [
                'título' => 'Fecha del ' . ($this->context === 'discounts' ? 'descuento' : 'gasto'),
                'texto' => "👉 Ingresa la fecha en formato YYYY-MM-DD\nPor ejemplo: 2025-01-31"
            ],
            'B3' => [
                'título' => 'Tipo de ' . ($this->context === 'discounts' ? 'descuento' : 'gasto'),
                'texto' => "👥 Elige el tipo:\n- Nómina: para empleados\n- Transportista: para conductores"
            ],
            'C3' => [
                'título' => 'Número de factura',
                'texto' => "📄 Ingresa el número de factura o documento de respaldo"
            ],
            'D3' => [
                'título' => 'Cuenta',
                'texto' => "💰 Selecciona la cuenta contable correspondiente de la lista desplegable"
            ],
            'E3' => [
                'título' => 'Valor',
                'texto' => "💵 Ingresa el monto usando punto para decimales\nEjemplo: 100.50"
            ],
            'F3' => [
                'título' => 'Proyecto',
                'texto' => "🏢 Selecciona el proyecto al que pertenece de la lista desplegable"
            ],
            'G3' => [
                'título' => 'Responsable',
                'texto' => "👤 Nombre completo de la persona"
            ],
            'H3' => [
                'título' => 'Placa',
                'texto' => "🚛 Solo si el tipo es Transportista\nDeja en blanco si es Nómina"
            ],
            'I3' => [
                'título' => 'Cédula',
                'texto' => "🪪 Número de identificación del responsable (aplica solo si se selecciona nómina, campo obligatorio para la validación)"
            ],
            'J3' => [
                'título' => 'Observación',
                'texto' => "✍️ Describe brevemente el motivo del " . ($this->context === 'discounts' ? 'descuento' : 'gasto')
            ]
        ];

        foreach ($comments as $cell => $commentData) {
            $comment = $sheet->getComment($cell);
            $comment->getText()->createTextRun($commentData['título'])->getFont()->setBold(true);
            $comment->getText()->createTextRun("\n\n" . $commentData['texto']);
            $comment->setWidth('200pt');
            $comment->setHeight('100pt');
        }
    }
}
