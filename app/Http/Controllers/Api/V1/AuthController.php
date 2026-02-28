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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

class AuthController extends Controller
{
    /**
     * Register a new user and issue Passport tokens.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request): User {
            return User::query()->create($request->safe()->only(['name', 'email', 'password']));
        });

        $tokens = $this->issueTokens($request->validated('email'), $request->validated('password'));

        return $this->successResponse('Registration successful.', [
            'user' => new UserResource($user),
            'token' => $this->filterTokenData($tokens),
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

        $tokens = $this->issueTokens($request->validated('email'), $request->validated('password'));

        return $this->successResponse('Login successful.', [
            'user' => new UserResource($user),
            'token' => $this->filterTokenData($tokens),
        ]);
    }

    /**
     * Refresh an expired access token using a refresh token.
     */
    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        $response = Http::asForm()->post(config('app.url').'/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $request->validated('refresh_token'),
            'client_id' => config('passport.password_client_id'),
            'client_secret' => config('passport.password_client_secret'),
            'scope' => '',
        ]);

        if ($response->failed()) {
            throw ApiException::unauthorized('Invalid or expired refresh token.');
        }

        return $this->successResponse('Token refreshed.', $this->filterTokenData($response->json()));
    }

    /**
     * Revoke the current user's access token and its refresh tokens (logout).
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var Token $token */
        $token = $request->user('api')->token();
        $token->revoke();

        RefreshToken::query()->where('access_token_id', $token->id)->update(['revoked' => true]);

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

                // Revoke all existing tokens after password reset.
                Token::query()->where('user_id', $user->id)->update(['revoked' => true]);
                RefreshToken::query()
                    ->whereIn('access_token_id', Token::query()->where('user_id', $user->id)->pluck('id'))
                    ->update(['revoked' => true]);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ApiException::badRequest(__($status));
        }

        return $this->successResponse('Password has been reset.');
    }

    /**
     * Issue Passport tokens via the password grant.
     *
     * @return array<string, mixed>
     */
    private function issueTokens(string $email, string $password): array
    {
        $response = Http::asForm()->post(config('app.url').'/oauth/token', [
            'grant_type' => 'password',
            'client_id' => config('passport.password_client_id'),
            'client_secret' => config('passport.password_client_secret'),
            'username' => $email,
            'password' => $password,
            'scope' => '',
        ]);

        if ($response->failed()) {
            throw ApiException::serverError('Unable to issue authentication tokens.');
        }

        return $response->json();
    }

    /**
     * Filter the Passport token response to only expose safe fields.
     *
     * @param  array<string, mixed>  $tokens
     * @return array{token_type: string, expires_in: int, access_token: string, refresh_token: string}
     */
    private function filterTokenData(array $tokens): array
    {
        return [
            'token_type' => $tokens['token_type'],
            'expires_in' => $tokens['expires_in'],
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
        ];
    }
}
