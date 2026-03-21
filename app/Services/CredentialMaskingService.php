<?php

namespace App\Services;

use App\Models\CredentialType;

class CredentialMaskingService
{
    /**
     * Mask sensitive data fields in credential data.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function maskData(array $data, string $type): array
    {
        $credentialType = CredentialType::query()->where('type', $type)->first();
        if (! $credentialType) {
            // Unsafe to return anything if we don't know the schema
            return [];
        }

        $properties = $credentialType->fields_schema['properties'] ?? [];
        $maskedData = [];

        foreach ($data as $key => $value) {
            $isSecret = $properties[$key]['secret'] ?? false;
            if ($isSecret) {
                // If it's a secret and not empty, indicate it's set
                $maskedData[$key] = ! empty($value) ? '___MASKED___' : '';
            } else {
                $maskedData[$key] = $value;
            }
        }

        return $maskedData;
    }

    /**
     * Merge updated data with existing data, ignoring masked values for secrets.
     *
     * @param  array<string, mixed>  $existingData
     * @param  array<string, mixed>  $newData
     * @return array<string, mixed>
     */
    public function mergeData(array $existingData, array $newData, string $type): array
    {
        $credentialType = CredentialType::query()->where('type', $type)->first();
        if (! $credentialType) {
            return $newData;
        }

        $properties = $credentialType->fields_schema['properties'] ?? [];
        $mergedData = $existingData;

        foreach ($newData as $key => $value) {
            $isSecret = $properties[$key]['secret'] ?? false;

            // If it's a secret and the value is the mask, we keep the existing value.
            if ($isSecret && $value === '___MASKED___') {
                continue;
            }

            $mergedData[$key] = $value;
        }

        return $mergedData;
    }
}
