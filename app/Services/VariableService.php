<?php

namespace App\Services;

use App\Models\User;
use App\Models\Variable;
use App\Models\Workspace;

class VariableService
{
    /**
     * Create a new variable in the workspace.
     *
     * @param  array{key: string, value: string, description?: string, is_secret?: bool}  $data
     */
    public function create(Workspace $workspace, User $creator, array $data): Variable
    {
        return $workspace->variables()->create([
            ...$data,
            'created_by' => $creator->id,
        ]);
    }

    /**
     * Update an existing variable.
     *
     * @param  array{key?: string, value?: string, description?: string, is_secret?: bool}  $data
     */
    public function update(Variable $variable, array $data): Variable
    {
        $variable->update($data);

        return $variable;
    }

    /**
     * Delete a variable.
     */
    public function delete(Variable $variable): void
    {
        $variable->delete();
    }
}
