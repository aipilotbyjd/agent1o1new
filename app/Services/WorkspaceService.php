<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

class WorkspaceService
{
    /**
     * Create a new workspace for the given user.
     *
     * @param  array{name: string}  $data
     */
    public function create(User $owner, array $data): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => $data['name'],
            'slug' => $this->generateUniqueSlug($data['name']),
            'owner_id' => $owner->id,
        ]);

        $workspace->members()->attach($owner->id, [
            'role' => Role::Owner->value,
            'joined_at' => now(),
        ]);

        return $workspace;
    }

    /**
     * Update the given workspace.
     *
     * @param  array{name?: string}  $data
     */
    public function update(Workspace $workspace, array $data): Workspace
    {
        if (isset($data['name'])) {
            $data['slug'] = $this->generateUniqueSlug($data['name'], $workspace->id);
        }

        $workspace->update($data);

        return $workspace;
    }

    /**
     * Delete the given workspace.
     */
    public function delete(Workspace $workspace): void
    {
        $workspace->delete();
    }

    /**
     * Generate a unique slug from the given name.
     */
    private function generateUniqueSlug(string $name, ?int $excludeId = null): string
    {
        $slug = Str::slug($name);
        $originalSlug = $slug;
        $counter = 1;

        $query = Workspace::query()->where('slug', $slug);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        while ($query->exists()) {
            $slug = $originalSlug.'-'.$counter;
            $counter++;

            $query = Workspace::query()->where('slug', $slug);

            if ($excludeId) {
                $query->where('id', '!=', $excludeId);
            }
        }

        return $slug;
    }
}
