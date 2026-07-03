<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->runMigrations();
});

it('creates core clearance tables', function (): void {
    expect(Schema::hasTable('clr_role_meta'))->toBeTrue();
    expect(Schema::hasTable('clr_role_ctx'))->toBeTrue();
});

it('clr_role_meta has expected columns', function (): void {
    expect(Schema::hasColumns('clr_role_meta', ['id', 'role_id', 'is_locked']))->toBeTrue();
});

it('clr_role_ctx has all context columns', function (): void {
    expect(Schema::hasColumns('clr_role_ctx', [
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
