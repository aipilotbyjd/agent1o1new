<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ActivityLogResource;
use App\Models\ActivityLog;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    private const MAX_PER_PAGE = 100;

    /**
     * List activity logs in a workspace.
     */
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->can(Permission::ActivityLogView);

        $query = $workspace->activityLogs()->with('user');

        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->input('subject_type'));
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->input('to'));
        }

        if ($request->filled('search')) {
            $search = str_replace(['%', '_'], ['\%', '\_'], $request->input('search'));
            $query->where('description', 'like', "%{$search}%");
        }

        $query->orderBy('created_at', 'desc');

        $perPage = min((int) $request->input('per_page', 15), self::MAX_PER_PAGE);
        $logs = $query->paginate($perPage);

        return $this->paginatedResponse(
            'Activity logs retrieved successfully.',
            ActivityLogResource::collection($logs),
        );
    }

    /**
     * Show an activity log entry.
     */
    public function show(Workspace $workspace, ActivityLog $activityLog): JsonResponse
    {
        $this->can(Permission::ActivityLogView);

        $activityLog->load('user');

        return $this->successResponse(
            'Activity log retrieved successfully.',
            new ActivityLogResource($activityLog),
        );
    }
}
