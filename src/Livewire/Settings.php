<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire;

use Illuminate\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Models\ClearanceMeta;
use Rivalex\Clearance\Models\ClearanceSettings;
use Rivalex\Clearance\Services\GuardService;
use Rivalex\Clearance\Services\PermissionService;
use Rivalex\Clearance\Services\UserClearanceService;
use Rivalex\Clearance\Support\ErrorMessage;
use Rivalex\Clearance\Support\Identity;
use Rivalex\Clearance\Support\SpatieSchema;
use Rivalex\Clearance\Support\SvgSanitizer;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Settings panel for Clearance:
 * - Default role for new users + bulk sync
 * - Show/hide icons globally
 * - Icon, color, display_name, description for each role and guard
 *
 * @deprecated 1.1.1 Use SettingsManager and its child components; removed in 2.0.
 */
#[Lazy]
class Settings extends Component
{
    // --- General settings ---
    /** The default role primary key as a string, '' for none. */
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
        $stored = ClearanceSettings::get('default_role_id');
        $this->defaultRole = is_string($stored) && ctype_digit($stored) ? $stored : '';
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

        $role = null;

        if ($this->defaultRole !== '') {
            // The value is a role id; a role of another guard would make the bulk assignment throw GuardDoesNotMatch.
            if (! ctype_digit($this->defaultRole)) {
                $this->addError('defaultRole', __('clearance::ui.settings.bulk_role_not_found'));

                return;
            }

            /** @var Role|null $role */
            $role = app(PermissionRegistrar::class)->getRoleClass()::query()->whereKey((int) $this->defaultRole)->first();

            if ($role === null || $role->guard_name !== Identity::guard()) {
                $this->addError('defaultRole', __('clearance::ui.settings.bulk_role_not_found'));

                return;
            }

            $actor = auth()->user();
            abort_if($actor === null, 403);

            try {
                $service = app(UserClearanceService::class);
                $service->assertCanBeDefaultRole($role);
                $service->assertCanAssignGlobalRole($actor, $role);
            } catch (ClearanceScopeViolationException|ClearanceProtectedResourceException $e) {
                $this->addError('defaultRole', ErrorMessage::for($e));

                return;
            }
        }

        ClearanceSettings::set('default_role_id', $role !== null ? (string) $role->getKey() : null);
        ClearanceSettings::set('show_icons', $this->showIcons ? '1' : '0');

        $this->saveMessage = __('clearance::ui.settings.saved');
    }

    /**
     * Bulk-assign the saved default role (stored by id) to users who don't already have it, in chunks of 500 with one transaction per chunk; users the actor may not manage are skipped and the first refusal stops the run.
     * Non-destructive: only adds, never removes existing roles.
     */
    public function bulkAssignDefaultRole(): void
    {
        abort_unless(app(Clearance::class)->canPerform('settings') && app(Clearance::class)->canPerform('users'), 403);

        $this->bulkMessage = null;

        // The stored setting is authoritative: the public $defaultRole property is client-mutable.
        $defaultRoleId = ClearanceSettings::get('default_role_id');

        if (! is_string($defaultRoleId) || ! ctype_digit($defaultRoleId)) {
            $this->bulkMessage = __('clearance::ui.settings.bulk_no_role');
            $this->bulkSuccess = false;

            return;
        }

        /** @var Role|null $role */
        $role = app(PermissionRegistrar::class)->getRoleClass()::query()->whereKey((int) $defaultRoleId)->first();

        if ($role === null || $role->guard_name !== Identity::guard()) {
            $this->bulkMessage = __('clearance::ui.settings.bulk_role_not_found');
            $this->bulkSuccess = false;

            return;
        }

        if (app(PermissionService::class)->isSystemAssignedOnly($role)) {
            $this->bulkMessage = __('clearance::ui.settings.bulk_role_system_only');
            $this->bulkSuccess = false;

            return;
        }

        $userModelClass = Identity::userModel();

        if (! $userModelClass || ! class_exists($userModelClass)) {
            $this->bulkMessage = __('clearance::ui.settings.bulk_no_user_model');
            $this->bulkSuccess = false;

            return;
        }

        $actor = auth()->user();
        abort_if($actor === null, 403);

        $model = new $userModelClass;
        $table = SpatieSchema::table('model_has_roles');
        $query = $userModelClass::query()->whereNotExists(function ($sub) use ($table, $model, $role): void {
            $sub->selectRaw('1')->from($table)
                ->whereColumn($table.'.'.SpatieSchema::modelMorphKey(), $model->getTable().'.'.$model->getKeyName())
                ->where($table.'.'.SpatieSchema::rolePivotKey(), $role->getKey())
                ->where($table.'.model_type', $model->getMorphClass());
        });

        try {
            $assigned = 0;
            $clearance = app(Clearance::class);

            // One transaction per chunk (inside bulkAssignGlobalRoleAs); the first refusal stops the run.
            $query->chunkById(500, function ($users) use (&$assigned, $actor, $role, $clearance): void {
                $assigned += app(UserClearanceService::class)->bulkAssignGlobalRoleAs(
                    $actor,
                    $role,
                    $users->filter(fn ($u) => $clearance->canManageUser($u))->values()->all(),
                );
            }, $model->getQualifiedKeyName(), $model->getKeyName());
        } catch (ClearanceScopeViolationException|ClearanceProtectedResourceException $e) {
            $this->bulkMessage = ErrorMessage::for($e);
            $this->bulkSuccess = false;

            return;
        }

        $this->bulkMessage = __('clearance::ui.settings.bulk_done', ['count' => $assigned]);
        $this->bulkSuccess = true;
    }

    /**
     * Open the meta modal for a role or guard.
     */
    public function openMetaModal(string $type, string $key): void
    {
        abort_unless(app(Clearance::class)->canPerform('settings'), 403);
        abort_unless(in_array($type, ['role', 'guard'], true), 422);

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
        abort_unless(app(Clearance::class)->canAccess(), 403);

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
