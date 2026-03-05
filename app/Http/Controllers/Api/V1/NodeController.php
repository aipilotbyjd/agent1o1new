<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\NodeResource;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NodeController extends Controller
{
    private const SORTABLE_COLUMNS = ['name', 'node_kind', 'created_at'];

    private const MAX_PER_PAGE = 100;

    /**
     * List all active nodes with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Node::query()->where('is_active', true)->with('category');

        if ($request->filled('search')) {
            $search = str_replace(['%', '_'], ['\%', '\_'], $request->input('search'));
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        if ($request->filled('node_kind')) {
            $query->where('node_kind', $request->input('node_kind'));
        }

        if ($request->filled('is_premium')) {
            $query->where('is_premium', filter_var($request->input('is_premium'), FILTER_VALIDATE_BOOLEAN));
        }

        $sortBy = in_array($request->input('sort_by'), self::SORTABLE_COLUMNS)
            ? $request->input('sort_by')
            : 'name';

        $sortDirection = $request->input('sort_direction') === 'desc' ? 'desc' : 'asc';

        $query->orderBy($sortBy, $sortDirection);

        $perPage = min((int) $request->input('per_page', 50), self::MAX_PER_PAGE);
        $nodes = $query->paginate($perPage);

        return $this->paginatedResponse(
            'Nodes retrieved successfully.',
            NodeResource::collection($nodes),
        );
    }

    /**
     * Show a single node with full schema details.
     */
    public function show(Node $node): JsonResponse
    {
        $node->load('category');

        return $this->successResponse(
            'Node retrieved successfully.',
            new NodeResource($node),
        );
    }
}
