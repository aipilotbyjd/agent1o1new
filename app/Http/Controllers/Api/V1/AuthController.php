<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RefreshTokenRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\PassportTokenService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    public function __construct(
        private PassportTokenService $tokenService,
    ) {}

    /**
     * Register a new user and issue Passport tokens.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request): User {
            $user = User::query()->create($request->safe()->only(['name', 'email', 'password']));
            $user->sendEmailVerificationNotification();

            return $user;
        });

        $tokens = $this->tokenService->issueTokens($request->validated('email'), $request->validated('password'));

        return $this->successResponse('Registration successful.', [
            'user' => new UserResource($user),
            'token' => $tokens,
        ], 201);
    }

    /**
     * Authenticate a user and issue Passport tokens.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            throw ApiException::unauthorized('Invalid email or password.');
        }

        $tokens = $this->tokenService->issueTokens($request->validated('email'), $request->validated('password'));

        return $this->successResponse('Login successful.', [
            'user' => new UserResource($user),
            'token' => $tokens,
        ]);
    }

    /**
     * Refresh an expired access token using a refresh token.
     */
    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        $tokens = $this->tokenService->refreshToken($request->validated('refresh_token'));

        return $this->successResponse('Token refreshed.', $tokens);
    }

    /**
     * Revoke the current user's access token and its refresh tokens (logout).
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var \Laravel\Passport\Token $token */
        $token = $request->user()->token();
        $this->tokenService->revokeToken($token);

        return $this->successResponse('Logged out successfully.');
    }

    /**
     * Send a password reset link to the given email.
     *
     * Always returns success to prevent email enumeration.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->validated());

        return $this->successResponse('If this email exists, a password reset link has been sent.');
    }

    /**
     * Reset the user's password using a valid reset token.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->safe()->only(['email', 'password', 'password_confirmation', 'token']),
            function (User $user, string $password): void {
                $user->forceFill(['password' => $password])->save();
                $this->tokenService->revokeAllTokens($user);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ApiException::badRequest(__($status));
        }

        return $this->successResponse('Password has been reset.');
    }

    /**
     * Verify email address via signed URL.
     */
    public function verifyEmail(Request $request, string $id, string $hash): JsonResponse
    {
        $user = User::query()->findOrFail($id);

        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            throw ApiException::forbidden('Invalid verification link.');
        }

        if ($user->hasVerifiedEmail()) {
            return $this->successResponse('Email already verified.');
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return $this->successResponse('Email has been verified successfully.');
    }

    /**
     * Resend email verification notification.
     */
    public function resendVerificationEmail(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return $this->successResponse('Email already verified.');
        }

        $user->sendEmailVerificationNotification();

        return $this->successResponse('Verification email has been resent.');
    }
}
