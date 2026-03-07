<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\AccessToken;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

/**
 * Handles all Passport OAuth token operations (issue, refresh, revoke).
 */
class PassportTokenService
{
    /**
     * Issue tokens via the password grant.
     *
     * @return array{token_type: string, expires_in: int, access_token: string, refresh_token: string}
     */
    public function issueTokens(string $email, string $password): array
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

        return $this->filterResponse($response->json());
    }

    /**
     * Refresh an access token using a refresh token.
     *
     * @return array{token_type: string, expires_in: int, access_token: string, refresh_token: string}
     */
    public function refreshToken(string $refreshToken): array
    {
        $response = Http::asForm()->post(config('app.url').'/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => config('passport.password_client_id'),
            'client_secret' => config('passport.password_client_secret'),
            'scope' => '',
        ]);

        if ($response->failed()) {
            throw ApiException::unauthorized('Invalid or expired refresh token.');
        }

        return $this->filterResponse($response->json());
    }

    /**
     * Revoke the given access token and its associated refresh tokens.
     */
    public function revokeToken(Token|AccessToken $token): void
    {
        $token->revoke();

        if ($token instanceof Token) {
            RefreshToken::query()
                ->where('access_token_id', $token->id)
                ->update(['revoked' => true]);
        }
    }

    /**
     * Revoke all tokens for a user.
     */
    public function revokeAllTokens(User $user): void
    {
        $tokenIds = $user->tokens()->pluck('id');

        $user->tokens()->update(['revoked' => true]);

        RefreshToken::query()
            ->whereIn('access_token_id', $tokenIds)
            ->update(['revoked' => true]);
    }

    /**
     * Revoke all tokens except the given one (for password change).
     */
    public function revokeOtherTokens(User $user, Token|AccessToken $currentToken): void
    {
        $tokenId = $currentToken->getKey();

        $otherTokenIds = $user->tokens()
            ->where('id', '!=', $tokenId)
            ->pluck('id');

        if ($otherTokenIds->isEmpty()) {
            return;
        }

        Token::query()->whereIn('id', $otherTokenIds)->update(['revoked' => true]);

        RefreshToken::query()
            ->whereIn('access_token_id', $otherTokenIds)
            ->update(['revoked' => true]);
    }

    /**
     * Filter the Passport response to only expose safe fields.
     *
     * @param  array<string, mixed>  $data
     * @return array{token_type: string, expires_in: int, access_token: string, refresh_token: string}
     */
    private function filterResponse(array $data): array
    {
        return [
            'token_type' => $data['token_type'],
            'expires_in' => $data['expires_in'],
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
        ];
    }
}
