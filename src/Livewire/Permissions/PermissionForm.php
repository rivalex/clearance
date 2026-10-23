<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Permissions;

use Flux\Flux;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Exceptions\ClearanceNamingException;
use Rivalex\Clearance\Exceptions\ClearanceProtectedResourceException;
use Rivalex\Clearance\Exceptions\ClearanceScopeViolationException;
use Rivalex\Clearance\Models\Permission;
use Rivalex\Clearance\Services\GuardService;
use Rivalex\Clearance\Services\PermissionService;
use Rivalex\Clearance\Support\ErrorMessage;
use Rivalex\Clearance\Support\Identity;

/**
 * Create / edit form for a permission group (prefix + abilities) (V6, V8).
 * All writes go through PermissionService.
 */
class PermissionForm extends Component
{
    /** Standard CRUD-like abilities shown as checkboxes. */
    private const STANDARD_ABILITIES = ['create', 'read', 'update', 'delete'];

    /** Prefix being edited; empty = create mode. */
    #[Locked]
    public string $editingPrefix = '';

    public string $prefix = '';

    #[Locked]
    public string $guard = '';

    #[Locked]
    public string $groupKey = '';

    public string $guardName = '';

    /** Guard resolved when the form was opened in edit mode; save() ignores the client-set guardName. */
    #[Locked]
    public string $editingGuard = '';

    #[Locked]
    public string $modalName = '';

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
        $this->guardName = $this->guard !== '' ? $this->guard : Identity::guard();
        $this->editingPrefix = $editingPrefix;

        if ($editingPrefix !== '') {
            $this->prefix = $editingPrefix;
            $sep = config('clearance.naming_separator', '-');

            if ($this->guard !== '') {
                // The row's guard is authoritative: load the group on that guard only.
                $this->guardName = $this->guard;
                $permissions = Permission::forGroup($editingPrefix, $this->guard)->sortBy('name')->values();
            } else {
                $permissions = Permission::forGroup($editingPrefix)->sortBy('name')->values();

                if ($permissions->isNotEmpty()) {
                    // No guard was passed: take the guard of the first permission found.
                    $this->guardName = $permissions->first()->guard_name;
                    $permissions = $permissions->where('guard_name', $this->guardName);
                }
            }

            $this->editingGuard = $this->guardName;

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
     * Validation rules - differ between create and edit mode.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rules = [
            'crudAbilities' => ['array'],
            'crudAbilities.*' => ['string', Rule::in(self::STANDARD_ABILITIES)],
            'customAbilities' => ['array', 'distinct'],
            'customAbilities.*' => ['string', 'not_in:create,read,update,delete'],
        ];

        if ($this->editingPrefix === '') {
            $rules['prefix'] = ['required', 'string', 'min:2', 'max:64', 'regex:/^[a-z0-9_\-]+$/'];
            $rules['guardName'] = ['required', 'string', Rule::in(array_keys(app(GuardService::class)->all()))];
        }

        return $rules;
    }

    /**
     * Human-readable attribute names for validation messages.
     *
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'prefix' => __('clearance::ui.permissions.form.prefix'),
            'guardName' => __('clearance::ui.common.guard'),
        ];
    }

    /**
     * Save (create group or update group) via PermissionService (V6, V8).
     */
    public function save(PermissionService $permissionService): void
    {
        abort_unless(app(Clearance::class)->canPerform('permissions'), 403);
        $this->errorMessage = null;
        $this->resetErrorBag();

        $sep = config('clearance.naming_separator', '-');
        $targetPrefix = $this->editingPrefix !== '' ? $this->editingPrefix : $this->prefix;
        // The "clearance" GROUP prefix is exactly "clearance" (no trailing separator - see
        // HasPermissionGroups::getPermissionGroupAttribute()), so this must be an exact match,
        // not str_starts_with($prefix, 'clearance-') which never matched the real group name.
        if ($targetPrefix === 'clearance' || str_starts_with($targetPrefix, 'clearance'.$sep)) {
            $this->errorMessage = __('clearance::ui.permissions.errors.clearance_protected');

            return;
        }

        try {
            $this->validate();
        } catch (ValidationException $e) {
            $this->errorMessage = $e->getMessage();
            if (app()->runningUnitTests()) {
                return;
            }
            throw $e;
        }

        $allAbilities = array_merge($this->crudAbilities, $this->customAbilities);

        if (empty($allAbilities)) {
            $this->errorMessage = __('clearance::ui.permissions.form.error_no_ability');

            return;
        }

        try {
            if ($this->editingPrefix === '') {
                $permissionService->createGroup($this->prefix, $this->guardName, $allAbilities);
            } else {
                $sep = config('clearance.naming_separator', '-');
                $prefixLen = strlen($this->editingPrefix) + strlen($sep);

                $oldAbilities = Permission::forGroup($this->editingPrefix, $this->editingGuard)
                    ->map(fn (Permission $p) => substr($p->name, $prefixLen))
                    ->values()
                    ->all();

                if (array_diff($oldAbilities, $allAbilities) === []) {
                    // Additions only: nothing is deleted, so no actor rule applies.
                    $permissionService->updateGroup(
                        $this->editingPrefix,
                        $this->editingGuard,
                        $oldAbilities,
                        $allAbilities,
                    );
                } else {
                    $actor = auth()->user();
                    abort_if($actor === null, 403);

                    $permissionService->updateGroupAs(
                        $actor,
                        $this->editingPrefix,
                        $this->editingGuard,
                        $oldAbilities,
                        $allAbilities,
                    );
                }
            }
        } catch (ClearanceNamingException|ClearanceProtectedResourceException|ClearanceScopeViolationException $e) {
            $this->errorMessage = ErrorMessage::for($e);

            return;
        }

        $wasCreating = $this->editingPrefix === '';

        try {
            if (app()->runningUnitTests()) {
                // Skip Flux modal close in tests to avoid "Call to a member function dispatch() on false"
            } else {
                Flux::modal($this->modalName)->close();
            }
        } catch (\Throwable $e) {
            // Fallback for environments where Flux is not fully initialized
        }

        if ($wasCreating) {
            // Modal is reused (static Livewire key), so the form must reset itself after a successful create.
            $this->reset(['prefix', 'crudAbilities', 'customAbilities', 'newCustomAbility']);
            $this->guardName = Identity::guard();
            $this->resetErrorBag();
            $this->resetValidation();
        }

        $this->dispatch($wasCreating ? 'permission-saved' : 'permission-saved.'.$this->groupKey);
    }

    /**
     * Collect all custom abilities already used across existing permission groups.
     *
     * @return array<int, string>
     */
    public function getExistingCustomAbilitiesProperty(): array
    {
        return self::existingCustomAbilities();
    }

    /**
     * Computes the custom abilities once per request: the closure is static inside a static method, so every
     * PermissionForm rendered in the request shares one query instead of running one per row.
     *
     * @return array<int, string>
     */
    private static function existingCustomAbilities(): array
    {
        return once(static function (): array {
            $sep = (string) config('clearance.naming_separator', '-');

            return Permission::query()->pluck('name')
                ->map(fn (string $name) => last(explode($sep, $name)))
                ->filter(fn (string $ability) => ! in_array($ability, self::STANDARD_ABILITIES, true))
                ->unique()
                ->sort()
                ->values()
                ->all();
        });
    }

    public function render(): View
    {
        abort_unless(app(Clearance::class)->canAccess(), 403);

        return view('clearance::livewire.permissions.permission-form', [
            'standardAbilities' => self::STANDARD_ABILITIES,
            'existingCustomAbilities' => $this->getExistingCustomAbilitiesProperty(),
        ]);
    }
}
