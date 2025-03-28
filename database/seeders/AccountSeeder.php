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
            // Cuentas existentes con posibles actualizaciones a "both" y generates_income
            [
                'name' => 'Gastos Bancarios',
                'account_number' => '5.1.1.02.23',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'discount',
                'generates_income' => false
            ],
            [
                'name' => 'Alimentación',
                'account_number' => '5.1.1.02.04',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'both', // Repetido
                'generates_income' => true // Tiene "X" en Ingreso
            ],
            [
                'name' => 'Anticipos de Quincena a Empleados',
                'account_number' => '1.1.20.2.03',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'discount',
                'generates_income' => false
            ],
            [
                'name' => 'Bono de Productividad',
                'account_number' => '5.1.1.01.19',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'discount',
                'generates_income' => false
            ],
            [
                'name' => 'Capacitación',
                'account_number' => '5.1.1.01.09',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'discount',
                'generates_income' => false
            ],
            [
                'name' => 'Cuentas por Cobrar Varios',
                'account_number' => '1.1.20.4.11',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'discount',
                'generates_income' => false
            ],
            [
                'name' => 'Equipo Celular',
                'account_number' => '4.2.1.01.07',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'discount',
                'generates_income' => false
            ],
            [
                'name' => 'Faltantes por Cobrar Empleados y Transportistas',
                'account_number' => '5.1.1.02.22',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'discount',
                'generates_income' => false
            ],
            [
                'name' => 'Gastos Médicos',
                'account_number' => '5.1.1.02.25',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'both', // Repetido
                'generates_income' => true // Tiene "X" en Ingreso
            ],
            [
                'name' => 'Liquidaciones de Haberes con Saldo en Negativo',
                'account_number' => '1.1.2.04.11',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'discount',
                'generates_income' => false
            ],
            [
                'name' => 'Mantenimiento',
                'account_number' => '4.2.1.01.07',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'discount',
                'generates_income' => false
            ],
            [
                'name' => 'Mantenimiento de Bodegas Compras',
                'account_number' => '5.1.1.02.82',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'both', // Repetido
                'generates_income' => false
            ],
            [
                'name' => 'Mantenimiento Vehículos',
                'account_number' => '5.1.1.02.35',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'both', // Repetido
                'generates_income' => false
            ],
            [
                'name' => 'Movilización',
                'account_number' => '5.1.1.02.37',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'both', // Repetido
                'generates_income' => true // Tiene "X" en Ingreso
            ],
            [
                'name' => 'Multas a Empleados',
                'account_number' => '2.1.5.01.08',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'discount',
                'generates_income' => false
            ],
            [
                'name' => 'Multas de Tránsito',
                'account_number' => '1.1.2.02.01',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'discount',
                'generates_income' => false
            ],
            [
                'name' => 'Préstamos Quirografarios',
                'account_number' => '2.1.5.01.06',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'discount',
                'generates_income' => false
            ],
            [
                'name' => 'Préstamos y Anticipos Empleados',
                'account_number' => '1.1.20.2.01',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'discount',
                'generates_income' => false
            ],
            [
                'name' => 'Recuperación Valores Comisión de Reparto',
                'account_number' => '4.2.1.01.10',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'discount',
                'generates_income' => false
            ],
            [
                'name' => 'Retención Judicial',
                'account_number' => '2.1.5.01.12',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'discount',
                'generates_income' => false
            ],
            [
                'name' => 'Retenciones del Trabajo',
                'account_number' => '2.1.6.01.01',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'discount',
                'generates_income' => false
            ],
            [
                'name' => 'Siniestros',
                'account_number' => '4.2.1.01.07',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'discount',
                'generates_income' => false
            ],
            [
                'name' => 'Subsidio IESS',
                'account_number' => '5.1.1.01.01',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'discount',
                'generates_income' => false
            ],
            [
                'name' => 'Sueldo en Contra Mes Anterior',
                'account_number' => '2.1.5.01.01',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'discount',
                'generates_income' => false
            ],
            [
                'name' => 'Telefonía Celular',
                'account_number' => '5.1.1.02.52',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'both', // Repetido
                'generates_income' => false
            ],
            [
                'name' => 'Uniformes Operativo',
                'account_number' => '5.1.1.02.76',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'discount',
                'generates_income' => false
            ],
            [
                'name' => 'Viáticos Proveedores',
                'account_number' => '5.1.1.02.85',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'both', // Repetido
                'generates_income' => true // Tiene "X" en Ingreso
            ],
            [
                'name' => 'Viáticos Rol',
                'account_number' => '5.1.1.02.58',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'both', // Repetido
                'generates_income' => true // Tiene "X" en Ingreso
            ],
            [
                'name' => 'Custodia Policial',
                'account_number' => '5.1.1.02.12',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'both', // Repetido
                'generates_income' => true // Tiene "X" en Ingreso
            ],
            [
                'name' => 'Transporte Varios (Otros)',
                'account_number' => '5.1.1.02.57',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'both', // Repetido
                'generates_income' => false
            ],
            // Nuevas cuentas con "expense" y generates_income
            [
                'name' => 'Combustible Diesel',
                'account_number' => '5.1.1.02.10',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'expense',
                'generates_income' => false
            ],
            [
                'name' => 'Courier',
                'account_number' => '5.1.1.02.11',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'expense',
                'generates_income' => false
            ],
            [
                'name' => 'Custodia Policial y Otros',
                'account_number' => '5.1.1.02.12',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'expense',
                'generates_income' => true // Tiene "X" en Ingreso
            ],
            [
                'name' => 'Estibaje',
                'account_number' => '5.1.1.02.19',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'expense',
                'generates_income' => true // Tiene "X" en Ingreso
            ],
            [
                'name' => 'Matriculación Vehículos',
                'account_number' => '5.1.1.02.36',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'expense',
                'generates_income' => false
            ],
            [
                'name' => 'Peajes',
                'account_number' => '5.1.1.02.39',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'expense',
                'generates_income' => false
            ],
            [
                'name' => 'Parqueo y Garage',
                'account_number' => '5.1.1.02.45',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'expense',
                'generates_income' => false
            ],
            [
                'name' => 'Seguridad Industrial',
                'account_number' => '5.1.1.02.46',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'expense',
                'generates_income' => false
            ],
            [
                'name' => 'Mantenimiento Montacargas',
                'account_number' => '5.1.1.02.70',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'expense',
                'generates_income' => false
            ],
            [
                'name' => 'Restricción Vehicular',
                'account_number' => '5.1.1.02.84',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'expense',
                'generates_income' => true // Tiene "X" en Ingreso
            ],
            [
                'name' => 'Agua Potable',
                'account_number' => '5.1.1.02.02',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'expense',
                'generates_income' => false
            ],
            [
                'name' => 'Cafetería',
                'account_number' => '5.1.1.02.17',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'expense',
                'generates_income' => false
            ],
            [
                'name' => 'Energía Eléctrica',
                'account_number' => '5.1.1.02.18',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'expense',
                'generates_income' => false
            ],
            [
                'name' => 'Faltantes de Inventarios (asume LogeX)',
                'account_number' => '5.1.1.02.20',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'expense',
                'generates_income' => false
            ],
            [
                'name' => 'Hospedaje',
                'account_number' => '5.1.1.02.28',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'expense',
                'generates_income' => true // Tiene "X" en Ingreso
            ],
            [
                'name' => 'Limpieza de Oficina',
                'account_number' => '5.1.1.02.32',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'expense',
                'generates_income' => false
            ],
            [
                'name' => 'Mantenimiento de Bodegas Servicios',
                'account_number' => '5.1.1.02.33',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'expense',
                'generates_income' => false
            ],
            [
                'name' => 'Servicio de Internet',
                'account_number' => '5.1.1.02.50',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'expense',
                'generates_income' => false
            ],
            [
                'name' => 'Suministros de Oficina',
                'account_number' => '5.1.1.02.51',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'expense',
                'generates_income' => false
            ],
            [
                'name' => 'Suministros de Limpieza',
                'account_number' => '5.1.1.02.75',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'expense',
                'generates_income' => false
            ],
            [
                'name' => 'Combustible Gasolina Super Extra',
                'account_number' => '5.1.1.02.81',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'expense',
                'generates_income' => false
            ],
            [
                'name' => 'Suministros de Bodega',
                'account_number' => '5.1.1.02.83',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'expense',
                'generates_income' => false
            ],
            [
                'name' => 'Custodia Policial Reembolso Cn',
                'account_number' => '5.1.1.02.87',
                'account_type' => 'nomina',
                'account_status' => 'active',
                'account_affects' => 'expense',
                'generates_income' => true // Tiene "X" en Ingreso
            ],
        ];

        foreach ($accounts as $account) {
            Account::create($account);
        }
    }
}
