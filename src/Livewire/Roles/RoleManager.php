<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Roles;

use Illuminate\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Concerns\HasClearanceLayout;
use Rivalex\Clearance\Models\ClearanceMeta;
use Rivalex\Clearance\Models\ClearanceSettings;
use Rivalex\Clearance\Models\RoleMeta;
use Rivalex\Clearance\Support\LikePattern;
use Rivalex\Clearance\Support\SpatieSchema;
use Spatie\Permission\Models\Role;

/**
 * List screen for roles with search and pagination (V8).
 * Create/edit/delete handled by child modal components (NewRole, EditRole, DeleteRole).
 */
#[Lazy]
class RoleManager extends Component
{
    use HasClearanceLayout;
    use WithPagination;

    public string $search = '';

    /**
     * No-op mount kept for test/Livewire lifecycle compatibility.
     */
    public function mount(): void {}

    /**
     * Reset pagination when search changes.
     */
    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Cap the incoming search value at 100 characters.
     */
    public function updatedSearch(): void
    {
        $this->search = mb_substr($this->search, 0, 100);
    }

    /**
     * Re-render after a child modal saves or deletes.
     */
    #[On('role-saved')]
    #[On('role-deleted')]
    public function refresh(): void {}

    public function placeholder(): View
    {
        return $this->withClearanceLayout(view('clearance::livewire.roles.placeholder'));
    }

    public function render(): View
    {
        abort_unless(app(Clearance::class)->canAccess(), 403);

        $query = Role::withCount('permissions')->orderBy('name');

        if ($this->search !== '') {
            $grammar = (new Role)->getConnection()->getQueryGrammar();

            $query->whereRaw($grammar->wrap('name')." LIKE ? ESCAPE '!'", [LikePattern::contains($this->search)]);
        }

        $roles = $query->paginate(15);

        $metas = RoleMeta::whereIn('role_id', $roles->pluck('id')->all())
            ->get()
            ->keyBy('role_id');

        $clearanceMetas = ClearanceMeta::where('subject_type', 'role')
            ->whereIn('subject_key', $roles->pluck('name')->all())
            ->get()
            ->keyBy('subject_key');

        $showIcons = filter_var(ClearanceSettings::get('show_icons', '1'), FILTER_VALIDATE_BOOLEAN);

        $rolePivotKey = SpatieSchema::rolePivotKey();

        // One grouped query for the holders of every role on the page.
        $holderCounts = Role::query()->getConnection()
            ->table(SpatieSchema::table('model_has_roles'))
            ->whereIn($rolePivotKey, $roles->pluck('id')->all())
            ->select($rolePivotKey.' as role_key')
            ->selectRaw('count(*) as aggregate')
            ->groupBy($rolePivotKey)
            ->pluck('aggregate', 'role_key');

        $roleData = $roles->through(fn (Role $role) => [
            'role' => $role,
            'meta' => $metas->get($role->id),
            'clearance_meta' => $clearanceMetas->get($role->name),
            'permissions_count' => (int) $role->getAttribute('permissions_count'),
            'users_count' => (int) ($holderCounts->get($role->id) ?? 0),
        ]);

        return $this->withClearanceLayout(view('clearance::livewire.roles.role-manager', compact('roleData', 'showIcons')));
    }
}
