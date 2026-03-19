<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CreditBalanceResource;
use App\Http\Resources\Api\V1\CreditTransactionResource;
use App\Models\Workspace;
use App\Services\CreditMeterService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class CreditController extends Controller
{
    use ApiResponse;

    /**
     * Get current credit balance for the workspace.
     */
    public function balance(Workspace $workspace, CreditMeterService $creditMeter): JsonResponse
    {
        $workspace->load([
            'subscriptions' => fn ($q) => $q->with('plan')->where('status', 'active')->latest(),
            'usagePeriods' => fn ($q) => $q->where('is_current', true),
        ]);

        return $this->successResponse(
            'Credit balance retrieved successfully.',
            new CreditBalanceResource($workspace)
        );
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

        return $this->paginatedResponse(
            'Credit transactions retrieved successfully.',
            CreditTransactionResource::collection($transactions)
        );
    }
}
