<?php

use App\Enums\Permission;
use App\Enums\Role;

it('has 87 permissions', function () {
    expect(Permission::cases())->toHaveCount(87);
});

it('groups permissions by resource', function () {
    $groups = Permission::grouped();

    expect($groups)
        ->toHaveKeys(['workspace', 'member', 'workflow', 'version', 'template', 'approval', 'contract', 'credential', 'execution', 'webhook', 'polling-trigger', 'tag', 'variable', 'environment', 'ai', 'activity-log', 'audit-log', 'credit', 'connector', 'setting', 'log-stream', 'git-sync', 'sticky-note', 'pinned-data'])
        ->not->toHaveKey('role');
});

it('gives owner all permissions', function () {
    expect(Role::Owner->permissions())->toEqual(Permission::cases());
});

it('gives viewer only view permissions and activity log and credit view', function () {
    $values = Role::Viewer->permissionValues();

    expect($values)->each->toMatch('/\.(view|view-metrics)$/');
});

it('does not give member delete permissions', function () {
    $values = Role::Member->permissionValues();

    expect($values)
        ->not->toContain('workflow.delete')
        ->not->toContain('template.delete')
        ->not->toContain('credential.delete')
        ->not->toContain('execution.delete')
        ->not->toContain('webhook.delete')
        ->not->toContain('tag.delete')
        ->not->toContain('variable.delete');
});

it('only allows non-owner roles to be assignable', function () {
    expect(Role::assignable())->toEqual([Role::Viewer, Role::Member, Role::Admin]);
});
