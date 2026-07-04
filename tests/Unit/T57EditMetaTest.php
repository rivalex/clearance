<?php

declare(strict_types=1);

use Livewire\Livewire;
use Rivalex\Clearance\Livewire\Settings\EditMeta;
use Rivalex\Clearance\Models\ClearanceMeta;

beforeEach(function (): void {
    $this->runMigrations();
});

it('mount builds a modal name scoped to subject type and key', function (): void {
    Livewire::test(EditMeta::class, ['subjectType' => 'role', 'subjectKey' => 'manager'])
        ->assertSet('modalName', 'edit-meta-role-manager');
});

it('mount defaults fields when no meta exists yet', function (): void {
    Livewire::test(EditMeta::class, ['subjectType' => 'role', 'subjectKey' => 'manager'])
        ->assertSet('displayName', '')
        ->assertSet('description', '')
        ->assertSet('color', '#3b82f6')
        ->assertSet('iconSvg', '');
});

it('mount hydrates fields from an existing meta record', function (): void {
    ClearanceMeta::create([
        'subject_type' => 'role',
        'subject_key'  => 'manager',
        'display_name' => 'Manager',
        'description'  => 'Runs the store',
        'color'        => '#ff0000',
        'icon_svg'     => '<svg></svg>',
    ]);

    Livewire::test(EditMeta::class, ['subjectType' => 'role', 'subjectKey' => 'manager'])
        ->assertSet('displayName', 'Manager')
        ->assertSet('description', 'Runs the store')
        ->assertSet('color', '#ff0000')
        ->assertSet('iconSvg', '<svg></svg>');
});

it('rejects an invalid color and does not persist', function (): void {
    Livewire::test(EditMeta::class, ['subjectType' => 'role', 'subjectKey' => 'manager'])
        ->set('color', 'not-a-color')
        ->call('save')
        ->assertHasErrors('color');

    expect(ClearanceMeta::where('subject_key', 'manager')->exists())->toBeFalse();
});

it('rejects a display name over the max length', function (): void {
    Livewire::test(EditMeta::class, ['subjectType' => 'role', 'subjectKey' => 'manager'])
        ->set('displayName', str_repeat('a', 121))
        ->call('save')
        ->assertHasErrors('displayName');
});

it('saves valid meta, sanitizes the icon, and dispatches meta-saved', function (): void {
    Livewire::test(EditMeta::class, ['subjectType' => 'role', 'subjectKey' => 'manager'])
        ->set('displayName', 'Manager')
        ->set('description', 'Runs the store')
        ->set('color', '#3b82f6')
        ->set('iconSvg', '<svg><script>alert(1)</script><path d="M0 0" /></svg>')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('meta-saved');

    $meta = ClearanceMeta::where('subject_type', 'role')->where('subject_key', 'manager')->first();

    expect($meta->display_name)->toBe('Manager')
        ->and($meta->icon_svg)->not->toContain('script');
});

it('exposes a sanitized icon preview in the view', function (): void {
    Livewire::test(EditMeta::class, ['subjectType' => 'role', 'subjectKey' => 'manager'])
        ->set('iconSvg', '<svg><script>alert(1)</script></svg>')
        ->assertViewHas('iconSvgPreview', fn (?string $preview) => $preview !== null && ! str_contains($preview, 'script'));
});
