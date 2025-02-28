<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function index(Request $request)
    {
        if ($request->has('account_type')) return $accounts = Account::where('account_type', $request->account_type)->orderBy('name', 'asc')->get();

        $accounts = Account::orderBy('name', 'asc')->get();

        return response()->json(["data" => $accounts]);
    }

    public function store(Request $request)
    {
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
