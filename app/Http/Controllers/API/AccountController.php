<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

class AccountController extends Controller
{
    public function index(Request $request)
    {
        // Filtrar únicamente cuentas activas
        $query = Account::orderBy('name', 'asc');

        // Filtro por tipo de cuenta, si se pasa el parámetro
        if ($request->has('account_type')) {
            // $query->where('account_type', $request->account_type);
            if ($request->account_type === "nomina") {
                $query->whereIn('account_type', ['nomina', 'ambos']);
            } else if ($request->account_type === "transportista") {
                $query->whereIn('account_type', ['transportista', 'ambos']);
            } else {
                $query->where('account_type', $request->account_type);
            }
        }

        // Filtro para account_affects: si se busca "expense" o "discount", se incluyen también las que tienen "both"
        if ($request->has('account_affects')) {
            $affects = $request->account_affects;
            if ($affects === 'expense') {
                // Retorna cuentas de gastos y que se repiten en descuentos
                $query->whereIn('account_affects', ['expense', 'both']);
            } elseif ($affects === 'discount' || $affects === 'income') {
                // Retorna cuentas de descuentos y que se repiten en gastos
                $query->whereIn('account_affects', ['discount', 'both']);
            } else {
                // En caso de que se envíe otro valor (por ejemplo "both"),
                // se filtra de manera exacta:
                // Solo gastos, solo descuentos o ambos
                $query->where('account_affects', $affects);
            }
        }

        return response()->json(["data" => $query->get()]);
    }

    public function store(Request $request)
    {
        // Validación estricta
        $validator = Validator::make($request->all(), [
            'name'            => 'required|string|max:255',
            'account_number'  => 'required|string|max:50|unique:accounts,account_number',
            'account_type'    => ['required', Rule::in(['nomina', 'transportista'])],
            'account_status'  => ['required', Rule::in(['active', 'inactive'])],
            'account_affects' => ['required', Rule::in(['discount', 'expense', 'both'])],
            'generates_income' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(["errors" => $validator->errors()], 422);
        }

        $account = Account::create($request->all());

        return response()->json($account, 201);
    }

    public function show($id)
    {
        $account = Account::findOrFail($id);
        return response()->json($account);
    }

    public function update(Request $request, $id)
    {
        $account = Account::findOrFail($id);

        // Validación flexible
        $validator = Validator::make($request->all(), [
            'name'            => 'sometimes|string|max:255',
            'account_number'  => "sometimes|string|max:50|unique:accounts,account_number,{$id}",
            'account_type'    => ['sometimes', Rule::in(['nomina', 'transportista'])],
            'account_status'  => ['sometimes', Rule::in(['active', 'inactive'])],
            'account_affects' => ['sometimes', Rule::in(['discount', 'expense', 'both'])],
            'generates_income' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(["errors" => $validator->errors()], 422);
        }

        $account->update($request->all());

        return response()->json($account);
    }

    public function destroy($id)
    {
        $account = Account::findOrFail($id);
        $account->delete();
        return response()->json(['message' => 'Account deleted successfully']);
    }
}
