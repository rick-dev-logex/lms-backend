<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AccountSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            [
                'name' => 'Gastos Bancarios',
                'account_number' => '5.1.1.02.23',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
            [
                'name' => 'Alimentación',
                'account_number' => '5.1.1.02.04',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
            [
                'name' => 'Anticipos de Quincena a Empleados',
                'account_number' => '1.1.20.2.03',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
            [
                'name' => 'Bono de Productividad',
                'account_number' => '5.1.1.01.19',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
            [
                'name' => 'Capacitación',
                'account_number' => '5.1.1.01.09',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
            [
                'name' => 'Cuentas por Cobrar Varios Descuentos Empleados',
                'account_number' => '1.1.20.4.11',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
            [
                'name' => 'Equipo Celular',
                'account_number' => '4.2.1.01.07',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
            [
                'name' => 'Faltantes por Cobrar Empleados y Transportistas',
                'account_number' => '5.1.1.02.22',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
            [
                'name' => 'Gastos Médicos',
                'account_number' => '5.1.1.02.25',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
            [
                'name' => 'Liquidaciones de Haberes con Saldo en Negativo',
                'account_number' => '1.1.2.04.11',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
            [
                'name' => 'Mantenimiento',
                'account_number' => '4.2.1.01.07',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
            [
                'name' => 'Mantenimiento de Bodegas Compras',
                'account_number' => '5.1.1.02.82',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
            [
                'name' => 'Mantenimiento Vehículos',
                'account_number' => '5.1.1.02.35',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
            [
                'name' => 'Movilización',
                'account_number' => '5.1.1.02.37',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
            [
                'name' => 'Multas a Empleados',
                'account_number' => '2.1.5.01.08',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
            [
                'name' => 'Multas de Tránsito',
                'account_number' => '1.1.2.02.01',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
            [
                'name' => 'Préstamos Quirografarios',
                'account_number' => '2.1.5.01.06',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
            [
                'name' => 'Préstamos y Anticipos Empleados',
                'account_number' => '1.1.20.2.01',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
            [
                'name' => 'Recuperación Valores Comisión de Reparto',
                'account_number' => '4.2.1.01.10',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
            [
                'name' => 'Retención Judicial',
                'account_number' => '2.1.5.01.12',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
            [
                'name' => 'Retenciones del Trabajo',
                'account_number' => '2.1.6.01.01',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
            [
                'name' => 'Siniestros',
                'account_number' => '4.2.1.01.07',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
            [
                'name' => 'Subsidio IESS',
                'account_number' => '5.1.1.01.01',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
            [
                'name' => 'Sueldo en Contra Mes Anterior',
                'account_number' => '2.1.5.01.01',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
            [
                'name' => 'Telefonía Celular',
                'account_number' => '5.1.1.02.52',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
            [
                'name' => 'Uniformes Operativo',
                'account_number' => '5.1.1.02.76',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
            [
                'name' => 'Viáticos Proveedores',
                'account_number' => '5.1.1.02.85',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
            [
                'name' => 'Viáticos Rol',
                'account_number' => '5.1.1.02.58',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
            [
                'name' => 'Mantenimiento Vehículos',
                'account_number' => '5.1.1.02.35',
                'account_type' => 'transportista',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
            [
                'name' => 'Custodia Policial',
                'account_number' => '5.1.1.02.12',
                'account_type' => 'transportista',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
            [
                'name' => 'Transporte Varios (Otros)',
                'account_number' => '5.1.1.02.57',
                'account_type' => 'transportista',
                'account_status' => 'active',
                'account_affects' => 'descuentos'
            ],
        ];

        foreach ($accounts as $account) {
            Account::create($account);
        }
    }
}
