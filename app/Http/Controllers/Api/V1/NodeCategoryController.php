<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\NodeCategoryResource;
use App\Models\NodeCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NodeCategoryController extends Controller
{
    /**
     * List all node categories with active node counts.
     */
    public function index(Request $request): JsonResponse
    {
        $query = NodeCategory::query()
            ->withCount(['nodes' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort_order');

        if ($request->boolean('include_nodes')) {
            $query->with(['nodes' => fn ($q) => $q->where('is_active', true)->orderBy('name')]);
        }

        $categories = $query->get();

        return $this->successResponse(
            'Node categories retrieved successfully.',
            NodeCategoryResource::collection($categories),
        );
    }

    /**
     * Show a single category with its active nodes.
     */
    public function show(NodeCategory $nodeCategory): JsonResponse
    {
        $nodeCategory->load(['nodes' => fn ($q) => $q->where('is_active', true)->orderBy('name')]);
        $nodeCategory->loadCount(['nodes' => fn ($q) => $q->where('is_active', true)]);

        return $this->successResponse(
            'Node category retrieved successfully.',
            new NodeCategoryResource($nodeCategory),
        );
    }
}
