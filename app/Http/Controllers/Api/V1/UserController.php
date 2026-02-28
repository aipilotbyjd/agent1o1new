<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\User\ChangePasswordRequest;
use App\Http\Requests\Api\V1\User\UpdateProfileRequest;
use App\Http\Requests\Api\V1\User\UploadAvatarRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\PassportTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    public function __construct(
        private PassportTokenService $tokenService,
    ) {}

    /**
     * Get the authenticated user's profile.
     */
    public function me(Request $request): JsonResponse
    {
        return $this->successResponse('User profile loaded.', new UserResource($request->user()));
    }

    /**
     * Update the authenticated user's profile.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->validated());

        return $this->successResponse('Profile updated.', new UserResource($user->fresh()));
    }

    /**
     * Change the authenticated user's password and revoke other tokens.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        /** @var \Laravel\Passport\Token $currentToken */
        $currentToken = $user->token();

        $user->update(['password' => $request->validated('password')]);

        $this->tokenService->revokeOtherTokens($user, $currentToken);

        return $this->successResponse('Password changed successfully.');
    }

    /**
     * Upload or replace the authenticated user's avatar.
     */
    public function uploadAvatar(UploadAvatarRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }

        $path = $request->file('avatar')->store('avatars', 'public');
        $user->update(['avatar' => $path]);

        return $this->successResponse('Avatar uploaded successfully.', new UserResource($user->fresh()));
    }

    /**
     * Delete the authenticated user's avatar.
     */
    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
            $user->update(['avatar' => null]);
        }

        return $this->successResponse('Avatar deleted successfully.', new UserResource($user->fresh()));
    }

    /**
     * Delete the authenticated user's account and revoke all tokens.
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }

        $user->tokens()->delete();
        $user->delete();

        return $this->successResponse('Account deleted successfully.');
    }
}
