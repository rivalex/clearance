<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Permissions;

use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Models\Permission;

/**
 * Represents a single group-guard row in the PermissionManager.
 * Handles display and actions for all abilities within that group.
 */
class PermissionGroupRow extends Component
{
    #[Locked]
    public string $group;

    #[Locked]
    public string $guard;

    #[Locked]
    public ?string $groupKey = null;

    #[Locked]
    public array $groupData = [];

    #[Locked]
    public string $prefix = '';

    #[Locked]
    public string $sep = '';

    #[Locked]
    public array $abilities = [];

    #[Locked]
    public array $labels = [];

    public function mount(): void
    {
        $this->buildDefault();
    }

    protected function buildDefault(): void
    {
        $this->groupKey = $this->groupKey ?: md5($this->group.'|'.$this->guard);
        $this->prefix = $this->prefix ?: $this->group;
        $this->sep = $this->sep ?: config('clearance.naming_separator', '-');

        if (empty($this->groupData)) {
            $this->refreshGroupData();
        }
    }

    protected function refreshGroupData(): void
    {
        $this->groupData = Permission::abilities(group: $this->group, guard: $this->guard);
        $this->abilities = $this->groupData['abilities'] ?? [];
        $this->labels = $this->groupData['labels'] ?? [
            'group' => Str::headline($this->group),
            'guard' => Str::headline($this->guard),
        ];
    }

    public function placeholder(): View
    {
        return view('clearance::livewire.permissions.placeholder');
    }

    /**
     * Reload group data when a permission is saved (handles edit from child modal).
     */
    public function reloadGroup(): void
    {
        $this->refreshGroupData();
    }

    public function getListeners(): array
    {
        return [
            "permission-saved.{$this->groupKey}" => 'reloadGroup',
        ];
    }

    public function render(): View
    {
        abort_unless(app(Clearance::class)->canAccess(), 403);

        return view('clearance::livewire.permissions.permission-group-row');
    }
}
