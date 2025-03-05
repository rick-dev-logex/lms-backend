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
        $query = Account::orderBy('name', 'asc');

        if ($request->has('account_type')) {
            $query->where('account_type', $request->account_type)->where('account_status', 'active');
        }

        if ($request->has('account_affects')) {
            $query->where('account_affects', $request->account_affects)->where('account_status', 'active');
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
            'account_affects' => 'required|string',
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
            'account_affects' => 'sometimes|string',
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
