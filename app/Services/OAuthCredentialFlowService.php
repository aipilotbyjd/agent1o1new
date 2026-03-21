<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Credential;
use App\Models\CredentialType;
use App\Models\OAuthCredentialState;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OAuthCredentialFlowService
{
    /**
     * Initiate the OAuth2 authorization flow.
     *
     * @return array{authorization_url: string, state_token: string}
     */
    public function initiate(Workspace $workspace, User $user, string $credentialTypeName, ?int $credentialId = null): array
    {
        $credentialType = CredentialType::where('type', $credentialTypeName)->firstOrFail();

        $oauthConfig = $credentialType->oauth_config ?? [];

        if (empty($oauthConfig['authorization_url'])) {
            throw ApiException::unprocessable("Credential type '{$credentialTypeName}' does not support OAuth2.");
        }

        $stateToken = (string) Str::uuid();
        $codeVerifier = null;
        $redirectUri = config('app.url').'/api/v1/oauth/callback';

        $scopes = $oauthConfig['scopes'] ?? [];

        $params = [
            'client_id' => $oauthConfig['client_id'] ?? '',
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'state' => $stateToken,
            'scope' => implode(' ', $scopes),
        ];

        if (! empty($oauthConfig['use_pkce'])) {
            $codeVerifier = Str::random(128);
            $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
            $params['code_challenge'] = $codeChallenge;
            $params['code_challenge_method'] = 'S256';
        }

        if (! empty($oauthConfig['extra_params']) && is_array($oauthConfig['extra_params'])) {
            $params = array_merge($params, $oauthConfig['extra_params']);
        }

        $authorizationUrl = $oauthConfig['authorization_url'].'?'.http_build_query($params);

        OAuthCredentialState::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'credential_id' => $credentialId,
            'credential_type' => $credentialTypeName,
            'state_token' => $stateToken,
            'provider' => $oauthConfig['provider'] ?? $credentialTypeName,
            'authorization_url' => $authorizationUrl,
            'redirect_uri' => $redirectUri,
            'scopes' => $scopes,
            'code_verifier' => $codeVerifier,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(10),
        ]);

        return [
            'authorization_url' => $authorizationUrl,
            'state_token' => $stateToken,
        ];
    }

    /**
     * Handle the OAuth2 callback and exchange code for tokens.
     */
    public function handleCallback(string $stateToken, string $code): Credential
    {
        $state = OAuthCredentialState::where('state_token', $stateToken)
            ->where('status', 'pending')
            ->firstOrFail();

        if ($state->isExpired()) {
            $state->markFailed();
            throw ApiException::unprocessable('OAuth state has expired. Please try again.');
        }

        $credentialType = CredentialType::where('type', $state->credential_type)->firstOrFail();
        $oauthConfig = $credentialType->oauth_config ?? [];

        $tokenPayload = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $state->redirect_uri,
            'client_id' => $oauthConfig['client_id'] ?? '',
            'client_secret' => $oauthConfig['client_secret'] ?? '',
        ];

        if ($state->code_verifier) {
            $tokenPayload['code_verifier'] = $state->code_verifier;
        }

        $response = Http::asForm()
            ->acceptJson()
            ->post($oauthConfig['token_url'], $tokenPayload);

        if ($response->failed()) {
            $state->markFailed();
            throw ApiException::unprocessable('Failed to exchange OAuth code for tokens.');
        }

        $tokens = $response->json();

        $credentialData = json_encode([
            'access_token' => $tokens['access_token'] ?? '',
            'refresh_token' => $tokens['refresh_token'] ?? null,
            'token_type' => $tokens['token_type'] ?? 'Bearer',
            'expires_in' => $tokens['expires_in'] ?? null,
            'scope' => $tokens['scope'] ?? null,
            'obtained_at' => now()->toIso8601String(),
        ]);

        if ($state->credential_id) {
            $credential = Credential::findOrFail($state->credential_id);
            $credential->update([
                'data' => $credentialData,
                'expires_at' => isset($tokens['expires_in'])
                    ? now()->addSeconds($tokens['expires_in'])
                    : null,
            ]);
        } else {
            $credential = Credential::create([
                'workspace_id' => $state->workspace_id,
                'created_by' => $state->user_id,
                'name' => ucfirst($state->provider).' OAuth',
                'type' => $state->credential_type,
                'data' => $credentialData,
                'expires_at' => isset($tokens['expires_in'])
                    ? now()->addSeconds($tokens['expires_in'])
                    : null,
            ]);
        }

        $state->markCompleted();

        return $credential;
    }

    /**
     * Refresh an OAuth2 credential if it has a refresh token.
     */
    public function refreshToken(Credential $credential): ?Credential
    {
        $data = is_string($credential->data) ? json_decode($credential->data, true) : $credential->data;

        if (! is_array($data) || empty($data['refresh_token'])) {
            return null;
        }

        $credentialType = CredentialType::where('type', $credential->type)->first();
        if (! $credentialType) {
            return null;
        }

        $oauthConfig = $credentialType->oauth_config ?? [];
        if (empty($oauthConfig['token_url'])) {
            return null;
        }

        $tokenPayload = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $data['refresh_token'],
            'client_id' => $oauthConfig['client_id'] ?? '',
            'client_secret' => $oauthConfig['client_secret'] ?? '',
        ];

        $response = Http::asForm()
            ->acceptJson()
            ->post($oauthConfig['token_url'], $tokenPayload);

        if ($response->failed()) {
            \Illuminate\Support\Facades\Log::warning("Failed to refresh OAuth token for credential {$credential->id}", ['response' => $response->body()]);

            return null;
        }

        $tokens = $response->json();

        $data['access_token'] = $tokens['access_token'] ?? $data['access_token'];
        $data['refresh_token'] = $tokens['refresh_token'] ?? $data['refresh_token'];

        $expiresAt = $credential->expires_at;
        if (isset($tokens['expires_in'])) {
            $data['expires_in'] = $tokens['expires_in'];
            $expiresAt = now()->addSeconds($tokens['expires_in']);
        }

        $data['obtained_at'] = now()->toIso8601String();

        $encodedData = is_string($credential->data) ? json_encode($data) : $data;

        $credential->update([
            'data' => $encodedData,
            'expires_at' => $expiresAt,
        ]);

        return $credential;
    }
}
