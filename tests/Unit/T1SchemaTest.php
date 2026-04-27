<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->runMigrations();
});

it('creates all 4 clearance tables', function (): void {
    expect(Schema::hasTable('clearance_role_meta'))->toBeTrue();
    expect(Schema::hasTable('clearance_role_hierarchy'))->toBeTrue();
    expect(Schema::hasTable('clearance_role_permission_overrides'))->toBeTrue();
    expect(Schema::hasTable('clearance_user_role_contexts'))->toBeTrue();
});

it('clearance_role_meta has expected columns', function (): void {
    expect(Schema::hasColumns('clearance_role_meta', ['id', 'role_id', 'is_system', 'is_protected']))->toBeTrue();
});

it('clearance_user_role_contexts has all context columns', function (): void {
    expect(Schema::hasColumns('clearance_user_role_contexts', [
        'id', 'user_id', 'role_id', 'context_type', 'context_id',
    ]))->toBeTrue();
});

it('clearance migrations do not alter spatie core tables', function (): void {
    $stubDir = realpath(__DIR__.'/../../database/migrations');
    $spatieTables = ['roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions'];

    foreach (glob($stubDir.'/*.php.stub') as $stub) {
        $content = file_get_contents($stub);
        foreach ($spatieTables as $table) {
            // Foreign key references are OK; Schema::create/alter on spatie tables is not
            expect($content)->not->toContain("Schema::create('{$table}'")
                ->not->toContain("Schema::table('{$table}'")
                ->not->toContain("Schema::drop('{$table}'");
        }
    }
});
