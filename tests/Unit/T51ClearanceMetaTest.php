<?php

declare(strict_types=1);

use Rivalex\Clearance\Models\ClearanceMeta;

beforeEach(function (): void {
    $this->runMigrations();
});

it('forSubject creates a new unsaved instance when none exists', function (): void {
    $meta = ClearanceMeta::forSubject('role', 'manager');

    expect($meta)->toBeInstanceOf(ClearanceMeta::class)
        ->and($meta->exists)->toBeFalse()
        ->and($meta->subject_type)->toBe('role')
        ->and($meta->subject_key)->toBe('manager');
});

it('forSubject retrieves an existing record for the same subject', function (): void {
    ClearanceMeta::create([
        'subject_type' => 'role',
        'subject_key' => 'manager',
        'display_name' => 'Manager',
    ]);

    $meta = ClearanceMeta::forSubject('role', 'manager');

    expect($meta->exists)->toBeTrue()
        ->and($meta->display_name)->toBe('Manager');
});

it('forSubject distinguishes subjects by type', function (): void {
    ClearanceMeta::create(['subject_type' => 'role', 'subject_key' => 'web', 'display_name' => 'Web Role']);

    $meta = ClearanceMeta::forSubject('guard', 'web');

    expect($meta->exists)->toBeFalse();
});

it('is mass-assignable for all documented fillable fields', function (): void {
    $meta = ClearanceMeta::create([
        'subject_type' => 'guard',
        'subject_key' => 'api',
        'display_name' => 'API',
        'description' => 'API guard',
        'icon_svg' => '<svg></svg>',
        'color' => '#3b82f6',
    ]);

    expect($meta->fresh())
        ->subject_type->toBe('guard')
        ->subject_key->toBe('api')
        ->display_name->toBe('API')
        ->description->toBe('API guard')
        ->icon_svg->toBe('<svg></svg>')
        ->color->toBe('#3b82f6');
});
