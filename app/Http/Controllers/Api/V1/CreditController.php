<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CreditBalanceResource;
use App\Http\Resources\Api\V1\CreditTransactionResource;
use App\Models\Workspace;
use App\Services\CreditMeterService;
use Illuminate\Http\JsonResponse;

class CreditController extends Controller
{
    /**
     * Get current credit balance for the workspace.
     */
    public function balance(Workspace $workspace, CreditMeterService $creditMeter): JsonResponse
    {
        return response()->json([
            'data' => new CreditBalanceResource($workspace),
        ]);
    }

    /**
     * Get credit transaction history for the workspace.
     */
    public function transactions(Workspace $workspace): JsonResponse
    {
        $transactions = $workspace->creditTransactions()
            ->with('execution:id,workflow_id,status')
            ->latest('created_at')
            ->paginate(25);

        return CreditTransactionResource::collection($transactions)->response();
    }
}
