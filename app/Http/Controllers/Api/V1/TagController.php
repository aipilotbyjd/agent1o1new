<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Tag\StoreTagRequest;
use App\Http\Requests\Api\V1\Tag\UpdateTagRequest;
use App\Http\Resources\Api\V1\TagResource;
use App\Models\Tag;
use App\Models\Workspace;
use App\Services\TagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TagController extends Controller
{
    private const MAX_PER_PAGE = 100;

    public function __construct(private TagService $tagService) {}

    /**
     * List tags in a workspace.
     */
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->can(Permission::TagView);

        $query = $workspace->tags()->withCount('workflows');

        if ($request->filled('search')) {
            $search = str_replace(['%', '_'], ['\%', '\_'], $request->input('search'));
            $query->where('name', 'like', "%{$search}%");
        }

        $query->orderBy('name');

        $perPage = min((int) $request->input('per_page', 15), self::MAX_PER_PAGE);
        $tags = $query->paginate($perPage);

        return $this->paginatedResponse(
            'Tags retrieved successfully.',
            TagResource::collection($tags),
        );
    }

    /**
     * Create a new tag.
     */
    public function store(StoreTagRequest $request, Workspace $workspace): JsonResponse
    {
        $tag = $this->tagService->create($workspace, $request->validated());

        return $this->successResponse(
            'Tag created successfully.',
            new TagResource($tag),
            201,
        );
    }

    /**
     * Show a tag.
     */
    public function show(Workspace $workspace, Tag $tag): JsonResponse
    {
        $this->can(Permission::TagView);

        $tag->loadCount('workflows');

        return $this->successResponse(
            'Tag retrieved successfully.',
            new TagResource($tag),
        );
    }

    /**
     * Update a tag.
     */
    public function update(UpdateTagRequest $request, Workspace $workspace, Tag $tag): JsonResponse
    {
        $tag = $this->tagService->update($tag, $request->validated());
        $tag->loadCount('workflows');

        return $this->successResponse(
            'Tag updated successfully.',
            new TagResource($tag),
        );
    }

    /**
     * Delete a tag.
     */
    public function destroy(Workspace $workspace, Tag $tag): JsonResponse
    {
        $this->can(Permission::TagDelete);

        $this->tagService->delete($tag);

        return $this->successResponse('Tag deleted successfully.');
    }

    /**
     * Attach workflows to a tag.
     */
    public function attachWorkflows(Request $request, Workspace $workspace, Tag $tag): JsonResponse
    {
        $this->can(Permission::TagUpdate);

        $validated = $request->validate([
            'workflow_ids' => ['required', 'array'],
            'workflow_ids.*' => ['integer', 'exists:workflows,id'],
        ]);

        $this->tagService->attachWorkflows($tag, $validated['workflow_ids']);

        $tag->loadCount('workflows');

        return $this->successResponse(
            'Workflows attached successfully.',
            new TagResource($tag),
        );
    }

    /**
     * Detach workflows from a tag.
     */
    public function detachWorkflows(Request $request, Workspace $workspace, Tag $tag): JsonResponse
    {
        $this->can(Permission::TagUpdate);

        $validated = $request->validate([
            'workflow_ids' => ['required', 'array'],
            'workflow_ids.*' => ['integer', 'exists:workflows,id'],
        ]);

        $this->tagService->detachWorkflows($tag, $validated['workflow_ids']);

        $tag->loadCount('workflows');

        return $this->successResponse(
            'Workflows detached successfully.',
            new TagResource($tag),
        );
    }
}
