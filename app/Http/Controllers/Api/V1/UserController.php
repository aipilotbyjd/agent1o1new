<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\User\UpdateProfileRequest;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Get the authenticated user's profile.
     */
    public function me(Request $request): JsonResponse
    {
        return $this->successResponse('User profile loaded.', new UserResource($request->user('api')));
    }

    /**
     * Update the authenticated user's profile.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user('api');
        $user->update($request->validated());

        return $this->successResponse('Profile updated.', new UserResource($user->fresh()));
    }
}
