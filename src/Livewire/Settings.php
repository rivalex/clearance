<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Models\ClearanceMeta;
use Rivalex\Clearance\Models\ClearanceSettings;
use Rivalex\Clearance\Services\GuardService;
use Rivalex\Clearance\Support\SvgSanitizer;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Models\Role;

/**
 * Settings panel for Clearance:
 * - Default role for new users + bulk sync
 * - Show/hide icons globally
 * - Icon, color, display_name, description for each role and guard
 */
#[Lazy]
class Settings extends Component
{
    // --- General settings ---
    public string $defaultRole = '';

    public bool $showIcons = true;

    // --- Meta editing state ---
    #[Locked]
    public string $metaSubjectType = '';

    #[Locked]
    public string $metaSubjectKey = '';

    public string $metaDisplayName = '';

    public string $metaDescription = '';

    public string $metaColor = '#3b82f6';

    public string $metaIconSvg = '';

    public bool $metaModalOpen = false;

    // --- Bulk sync feedback ---
    public ?string $bulkMessage = null;

    public bool $bulkSuccess = false;

    // --- General save feedback ---
    public ?string $saveMessage = null;

    public function mount(): void
    {
        $this->defaultRole = ClearanceSettings::get('default_role', '');
        $this->showIcons = filter_var(ClearanceSettings::get('show_icons', '1'), FILTER_VALIDATE_BOOLEAN);
    }

    public function placeholder(): View
    {
        return view('clearance::livewire.settings.placeholder');
    }

    /**
     * Save general settings (default role + show icons).
     */
    public function saveGeneral(): void
    {
        abort_unless(app(Clearance::class)->canPerform('settings'), 403);

        ClearanceSettings::set('default_role', $this->defaultRole ?: null);
        ClearanceSettings::set('show_icons', $this->showIcons ? '1' : '0');

        $this->saveMessage = __('clearance::ui.settings.saved');
    }

    /**
     * Bulk-assign the default role to all users who don't already have it.
     * Non-destructive: only adds, never removes existing roles.
     */
    public function bulkAssignDefaultRole(): void
    {
        abort_unless(app(Clearance::class)->canPerform('settings'), 403);

        $this->bulkMessage = null;

        if (! $this->defaultRole) {
            $this->bulkMessage = __('clearance::ui.settings.bulk_no_role');
            $this->bulkSuccess = false;

            return;
        }

        try {
            $role = Role::findByName($this->defaultRole);
        } catch (RoleDoesNotExist) {
            $this->bulkMessage = __('clearance::ui.settings.bulk_role_not_found');
            $this->bulkSuccess = false;

            return;
        }

        $userModelClass = config('clearance.user_model')
            ?? config('auth.providers.'.config('auth.defaults.guard').'.model')
            ?? User::class;

        if (! $userModelClass || ! class_exists($userModelClass)) {
            $this->bulkMessage = __('clearance::ui.settings.bulk_no_user_model');
            $this->bulkSuccess = false;

            return;
        }

        $modelHasRolesTable = config('permission.table_names.model_has_roles', 'model_has_roles');
        $usersWithRole = DB::table($modelHasRolesTable)
            ->where('role_id', $role->id)
            ->pluck('model_id')
            ->all();

        $assigned = 0;
        $userModelClass::query()
            ->whereNotIn('id', $usersWithRole)
            ->each(function ($user) use ($role, &$assigned) {
                if (method_exists($user, 'assignRole')) {
                    $user->assignRole($role);
                    $assigned++;
                }
            });

        $this->bulkMessage = __('clearance::ui.settings.bulk_done', ['count' => $assigned]);
        $this->bulkSuccess = true;
    }

    /**
     * Open the meta modal for a role or guard.
     */
    public function openMetaModal(string $type, string $key): void
    {
        $this->metaSubjectType = $type;
        $this->metaSubjectKey = $key;

        $meta = ClearanceMeta::forSubject($type, $key);

        $this->metaDisplayName = $meta->display_name ?? '';
        $this->metaDescription = $meta->description ?? '';
        $this->metaColor = $meta->color ?? '#3b82f6';
        $this->metaIconSvg = $meta->icon_svg ?? '';
        $this->metaModalOpen = true;
    }

    /**
     * Save meta for the current subject.
     */
    public function saveMeta(): void
    {
        abort_unless(app(Clearance::class)->canPerform('settings'), 403);
        abort_unless(in_array($this->metaSubjectType, ['role', 'guard'], true), 422);

        $this->validate([
            'metaDisplayName' => ['nullable', 'string', 'max:120'],
            'metaDescription' => ['nullable', 'string', 'max:500'],
            'metaColor' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'metaIconSvg' => ['nullable', 'string', 'max:8000'],
        ]);

        ClearanceMeta::updateOrCreate(
            ['subject_type' => $this->metaSubjectType, 'subject_key' => $this->metaSubjectKey],
            [
                'display_name' => $this->metaDisplayName ?: null,
                'description' => $this->metaDescription ?: null,
                'color' => $this->metaColor ?: null,
                'icon_svg' => $this->metaIconSvg ? SvgSanitizer::sanitize($this->metaIconSvg) : null,
            ]
        );

        $this->metaModalOpen = false;
        $this->dispatch('meta-saved');
    }

    /**
     * Close the meta modal without saving.
     */
    public function closeMetaModal(): void
    {
        $this->metaModalOpen = false;
    }

    public function render(): View
    {
        $roles = Role::orderBy('name')->get();
        $guards = app(GuardService::class)->all();

        $roleMetas = ClearanceMeta::where('subject_type', 'role')->get()->keyBy('subject_key');
        $guardMetas = ClearanceMeta::where('subject_type', 'guard')->get()->keyBy('subject_key');

        return view('clearance::livewire.settings', [
            'roles' => $roles,
            'guards' => $guards,
            'roleMetas' => $roleMetas,
            'guardMetas' => $guardMetas,
            'metaIconSvgPreview' => SvgSanitizer::sanitize($this->metaIconSvg),
        ]);
    }
}
