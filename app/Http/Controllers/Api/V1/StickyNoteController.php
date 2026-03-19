<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StickyNote\StoreStickyNoteRequest;
use App\Http\Requests\Api\V1\StickyNote\UpdateStickyNoteRequest;
use App\Http\Resources\Api\V1\StickyNoteResource;
use App\Models\StickyNote;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Services\StickyNoteService;
use Illuminate\Http\JsonResponse;

class StickyNoteController extends Controller
{
    public function __construct(private StickyNoteService $stickyNoteService) {}

    /**
     * List sticky notes for a workflow.
     */
    public function index(Workspace $workspace, Workflow $workflow): JsonResponse
    {
        $this->can(Permission::WorkflowView);

        $notes = $workflow->stickyNotes()->with('creator')->get();

        return $this->successResponse(
            'Sticky notes retrieved successfully.',
            StickyNoteResource::collection($notes),
        );
    }

    /**
     * Create a sticky note on a workflow canvas.
     */
    public function store(StoreStickyNoteRequest $request, Workspace $workspace, Workflow $workflow): JsonResponse
    {
        $validated = $request->validated();

        $note = $this->stickyNoteService->create(
            $workspace,
            $workflow,
            $request->user(),
            $validated,
        );

        $note->load('creator');

        return $this->successResponse(
            'Sticky note created successfully.',
            new StickyNoteResource($note),
            201,
        );
    }

    /**
     * Update a sticky note.
     */
    public function update(UpdateStickyNoteRequest $request, Workspace $workspace, Workflow $workflow, StickyNote $stickyNote): JsonResponse
    {
        $validated = $request->validated();

        $note = $this->stickyNoteService->update($stickyNote, $validated);
        $note->load('creator');

        return $this->successResponse(
            'Sticky note updated successfully.',
            new StickyNoteResource($note),
        );
    }

    /**
     * Delete a sticky note.
     */
    public function destroy(Workspace $workspace, Workflow $workflow, StickyNote $stickyNote): JsonResponse
    {
        $this->can(Permission::WorkflowUpdate);

        $this->stickyNoteService->delete($stickyNote);

        return $this->successResponse('Sticky note deleted successfully.');
    }
}
