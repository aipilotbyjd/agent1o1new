<?php

use App\Models\CredentialType;
use App\Models\OAuthCredentialState;
use App\Models\User;
use App\Models\Workspace;
use App\Services\OAuthCredentialFlowService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    $this->user = User::factory()->create();
    $this->service = app(OAuthCredentialFlowService::class);

    // Create credential type
    $this->credentialType = CredentialType::create([
        'name' => 'Google OAuth2',
        'type' => 'google_oauth2',
        'description' => 'Google authentication',
        'icon' => 'google',
        'color' => '#DB4437',
        'fields_schema' => [],
        'auth_schema' => [],
        'oauth_config' => [
            'provider' => 'google',
            'authorization_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'client_id' => 'test_client_id',
            'client_secret' => 'test_client_secret',
            'scopes' => ['email', 'profile'],
            'use_pkce' => true,
            'extra_params' => [
                'access_type' => 'offline',
                'prompt' => 'consent',
            ],
        ],
        'is_active' => true,
    ]);
});

it('initiates oauth flow', function () {
    $result = $this->service->initiate($this->workspace, $this->user, 'google_oauth2');

    expect($result)->toHaveKeys(['authorization_url', 'state_token']);

    $url = $result['authorization_url'];

    expect($url)->toContain('https://accounts.google.com/o/oauth2/v2/auth')
        ->toContain('client_id=test_client_id')
        ->toContain('response_type=code')
        ->toContain('scope=email+profile')
        ->toContain('access_type=offline')
        ->toContain('prompt=consent')
        ->toContain('code_challenge=');

    $state = OAuthCredentialState::where('state_token', $result['state_token'])->first();

    expect($state)->not->toBeNull()
        ->and($state->workspace_id)->toBe($this->workspace->id)
        ->and($state->user_id)->toBe($this->user->id)
        ->and($state->credential_type)->toBe('google_oauth2');
});

it('handles oauth callback', function () {
    $stateToken = (string) Str::uuid();
    OAuthCredentialState::create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'credential_type' => 'google_oauth2',
        'state_token' => $stateToken,
        'provider' => 'google',
        'authorization_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'redirect_uri' => 'http://localhost/callback',
        'scopes' => ['email', 'profile'],
        'code_verifier' => 'test_verifier',
        'status' => 'pending',
        'expires_at' => now()->addMinutes(10),
    ]);

    Http::fake([
        'oauth2.googleapis.com/token*' => Http::response([
            'access_token' => 'new_access_token',
            'refresh_token' => 'new_refresh_token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'scope' => 'email profile',
        ], 200),
    ]);

    $credential = $this->service->handleCallback($stateToken, 'test_code');

    expect($credential)->not->toBeNull()
        ->and($credential->type)->toBe('google_oauth2')
        ->and($credential->workspace_id)->toBe($this->workspace->id)
        ->and($credential->created_by)->toBe($this->user->id);

    $data = is_string($credential->data) ? json_decode($credential->data, true) : $credential->data;

    expect($data['access_token'])->toBe('new_access_token')
        ->and($data['refresh_token'])->toBe('new_refresh_token')
        ->and($data['token_type'])->toBe('Bearer');

    $state = OAuthCredentialState::where('state_token', $stateToken)->first();
    expect($state->status)->toBe('completed');
});

it('refreshes token', function () {
    $data = [
        'access_token' => 'old_access_token',
        'refresh_token' => 'existing_refresh_token',
        'token_type' => 'Bearer',
        'expires_in' => 3600,
        'scope' => 'email profile',
        'obtained_at' => now()->subHours(2)->toIso8601String(),
    ];

    $credential = App\Models\Credential::create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
        'name' => 'Google OAuth',
        'type' => 'google_oauth2',
        'data' => json_encode($data),
        'expires_at' => now()->subHour(),
    ]);

    Http::fake([
        'oauth2.googleapis.com/token*' => Http::response([
            'access_token' => 'refreshed_access_token',
            'expires_in' => 3600,
        ], 200),
    ]);

    $refreshedCredential = $this->service->refreshToken($credential);

    expect($refreshedCredential)->not->toBeNull();

    $refreshedData = is_string($refreshedCredential->data) ? json_decode($refreshedCredential->data, true) : $refreshedCredential->data;

    expect($refreshedData['access_token'])->toBe('refreshed_access_token')
        ->and($refreshedData['refresh_token'])->toBe('existing_refresh_token');
});
