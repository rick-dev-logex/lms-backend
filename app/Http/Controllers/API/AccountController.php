<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Models\Account;
use Illuminate\Http\JsonResponse;

class AccountController extends Controller
{
    public function index(): JsonResponse
    {
        $query = Account::orderBy('name', 'asc');

        if (request()->has('account_type')) {
            $query->where('account_type', request('account_type'));
        }

        if (request()->has('account_affects')) {
            $affects = request('account_affects');
            $query->whereIn('account_affects', match ($affects) {
                'expense' => ['expense', 'both'],
                'discount' => ['discount', 'both'],
                default => [$affects],
            });
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(StoreAccountRequest $request): JsonResponse
    {
        $account = Account::create($request->validated());
        return response()->json([
            'data' => $account,
            'message' => 'Account created successfully',
        ], 201);
    }

    public function show(Account $account): JsonResponse
    {
        return response()->json(['data' => $account]);
    }

    public function update(UpdateAccountRequest $request, Account $account): JsonResponse
    {
        $account->update($request->validated());
        return response()->json([
            'data' => $account,
            'message' => 'Account updated successfully',
        ]);
    }

    public function destroy(Account $account): JsonResponse
    {
        $account->delete();
        return response()->json(['message' => 'Account deleted successfully']);
    }
}
