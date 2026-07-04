<?php

declare(strict_types=1);

use Rivalex\Clearance\Models\ClearanceMeta;
use Rivalex\Clearance\Services\MetaService;

beforeEach(function (): void {
    $this->runMigrations();

    $this->service = new MetaService;
});

it('creates meta with all fields populated', function (): void {
    $meta = $this->service->update('role', 'manager', [
        'display_name' => 'Manager',
        'description' => 'Manages the store',
        'color' => '#ff0000',
        'icon_svg' => '<svg><path d="M0 0" /></svg>',
    ]);

    expect($meta->exists)->toBeTrue()
        ->and($meta->subject_type)->toBe('role')
        ->and($meta->subject_key)->toBe('manager')
        ->and($meta->display_name)->toBe('Manager')
        ->and($meta->description)->toBe('Manages the store')
        ->and($meta->color)->toBe('#ff0000')
        ->and($meta->icon_svg)->toContain('<svg');
});

it('sanitizes the icon_svg before storage', function (): void {
    $meta = $this->service->update('role', 'manager', [
        'display_name' => 'Manager',
        'description' => null,
        'color' => null,
        'icon_svg' => '<svg><script>alert(1)</script><path d="M0 0" onclick="x()" /></svg>',
    ]);

    expect($meta->icon_svg)
        ->not->toContain('script')
        ->not->toContain('onclick')
        ->toContain('<path');
});

it('stores null for empty or falsy optional fields', function (): void {
    $meta = $this->service->update('guard', 'api', [
        'display_name' => '',
        'description' => '',
        'color' => '',
        'icon_svg' => '',
    ]);

    expect($meta->display_name)->toBeNull()
        ->and($meta->description)->toBeNull()
        ->and($meta->color)->toBeNull()
        ->and($meta->icon_svg)->toBeNull();
});

it('updates the existing record for the same subject instead of creating a duplicate', function (): void {
    $this->service->update('role', 'manager', [
        'display_name' => 'Manager',
        'description' => null,
        'color' => null,
        'icon_svg' => null,
    ]);

    $this->service->update('role', 'manager', [
        'display_name' => 'Store Manager',
        'description' => null,
        'color' => null,
        'icon_svg' => null,
    ]);

    expect(ClearanceMeta::where('subject_type', 'role')->where('subject_key', 'manager')->count())->toBe(1)
        ->and(ClearanceMeta::forSubject('role', 'manager')->display_name)->toBe('Store Manager');
});
