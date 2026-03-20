<?php

namespace App\Services;

use App\Models\Credential;
use App\Models\CredentialType;
use App\Models\User;
use App\Models\Workspace;

class CredentialService
{
    public function __construct(
        private CredentialMaskingService $maskingService
    ) {}

    /**
     * Create a new credential in the workspace.
     *
     * @param  array{name: string, type: string, data: array, expires_at?: string}  $data
     */
    public function create(Workspace $workspace, User $creator, array $data): Credential
    {
        return $workspace->credentials()->create([
            ...$data,
            'data' => json_encode($data['data']),
            'created_by' => $creator->id,
        ]);
    }

    /**
     * Update an existing credential.
     *
     * @param  array{name?: string, data?: array, expires_at?: string|null}  $data
     */
    public function update(Credential $credential, array $data): Credential
    {
        if (isset($data['data'])) {
            $existingData = json_decode($credential->data, true) ?? [];
            $mergedData = $this->maskingService->mergeData($existingData, $data['data'], $credential->type);
            $data['data'] = json_encode($mergedData);
        }

        $credential->update($data);

        return $credential;
    }

    /**
     * Soft-delete a credential.
     */
    public function delete(Credential $credential): void
    {
        $credential->delete();
    }

    /**
     * Test a credential by validating its data against the type's fields_schema.
     *
     * @return array{success: bool, message: string}
     */
    public function test(Credential $credential): array
    {
        $credentialType = CredentialType::query()->where('type', $credential->type)->first();

        if (! $credentialType) {
            return [
                'success' => false,
                'message' => "Unknown credential type: {$credential->type}.",
            ];
        }

        $schema = $credentialType->fields_schema;
        $requiredFields = $schema['required'] ?? [];
        $data = json_decode($credential->data, true) ?? [];

        $missingFields = [];
        foreach ($requiredFields as $field) {
            if (! isset($data[$field]) || $data[$field] === '') {
                $missingFields[] = $field;
            }
        }

        if (! empty($missingFields)) {
            return [
                'success' => false,
                'message' => 'Missing required fields: '.implode(', ', $missingFields).'.',
            ];
        }

        return [
            'success' => true,
            'message' => 'Credential validation passed.',
        ];
    }
}
