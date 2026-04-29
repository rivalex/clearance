<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Permissions;

use Illuminate\View\View;
use Livewire\Component;
use Rivalex\Clearance\Exceptions\ClearanceNamingException;
use Rivalex\Clearance\Services\GuardService;
use Rivalex\Clearance\Services\PermissionService;
use Spatie\Permission\Models\Permission;

/**
 * Create / edit form for a permission group (prefix + abilities) (V6, V8).
 * All writes go through PermissionService.
 */
class PermissionForm extends Component
{
    /** Standard CRUD-like abilities shown as checkboxes. */
    private const STANDARD_ABILITIES = ['create', 'read', 'update', 'delete'];

    /** Prefix being edited; empty = create mode. */
    public string $editingPrefix = '';

    public string $prefix = '';

    public string $guardName = '';

    /** @var array<int, string> Checked standard abilities. */
    public array $crudAbilities = [];

    /** @var array<int, string> Custom ability pill list. */
    public array $customAbilities = [];

    public string $newCustomAbility = '';

    /** @var array<int, string> */
    public array $availableGuards = [];

    public ?string $errorMessage = null;

    /**
     * Load existing group data or set defaults.
     */
    public function mount(GuardService $guardService, string $editingPrefix = ''): void
    {
        $this->availableGuards = array_keys($guardService->all());
        $this->guardName = config('auth.defaults.guard', 'web');
        $this->editingPrefix = $editingPrefix;

        if ($editingPrefix !== '') {
            $this->prefix = $editingPrefix;
            $sep = config('clearance.naming_separator', '-');

            $permissions = Permission::where('name', 'like', $editingPrefix.$sep.'%')
                ->orderBy('name')
                ->get();

            if ($permissions->isNotEmpty()) {
                $this->guardName = $permissions->first()->guard_name;
            }

            $prefixLen = strlen($editingPrefix) + strlen($sep);

            foreach ($permissions as $perm) {
                $ability = substr($perm->name, $prefixLen);

                if (in_array($ability, self::STANDARD_ABILITIES, true)) {
                    $this->crudAbilities[] = $ability;
                } else {
                    $this->customAbilities[] = $ability;
                }
            }
        }
    }

    /**
     * Append a custom ability to the pill list.
     */
    public function addCustomAbility(): void
    {
        $ability = trim($this->newCustomAbility);

        if ($ability !== '' && ! in_array($ability, $this->customAbilities, true)) {
            $this->customAbilities[] = $ability;
        }

        $this->newCustomAbility = '';
    }

    /**
     * Remove a custom ability by index.
     */
    public function removeCustomAbility(int $index): void
    {
        array_splice($this->customAbilities, $index, 1);
    }

    /**
     * Save (create group or update group) via PermissionService (V6, V8).
     */
    public function save(PermissionService $permissionService): void
    {
        $this->errorMessage = null;

        $allAbilities = array_merge($this->crudAbilities, $this->customAbilities);

        if (empty($allAbilities)) {
            $this->errorMessage = 'Select at least one ability.';

            return;
        }

        try {
            if ($this->editingPrefix === '') {
                $permissionService->createGroup($this->prefix, $this->guardName, $allAbilities);
            } else {
                $sep = config('clearance.naming_separator', '-');
                $prefixLen = strlen($this->editingPrefix) + strlen($sep);

                $oldAbilities = Permission::where('name', 'like', $this->editingPrefix.$sep.'%')
                    ->get()
                    ->map(fn (Permission $p) => substr($p->name, $prefixLen))
                    ->values()
                    ->all();

                $permissionService->updateGroup(
                    $this->editingPrefix,
                    $this->guardName,
                    $oldAbilities,
                    $allAbilities,
                );
            }
        } catch (ClearanceNamingException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->dispatch('permission-saved');
    }

    /**
     * Cancel without saving.
     */
    public function cancel(): void
    {
        $this->dispatch('permission-saved');
    }

    public function render(): View
    {
        return view('clearance::livewire.permissions.permission-form', [
            'standardAbilities' => self::STANDARD_ABILITIES,
        ]);
    }
}
