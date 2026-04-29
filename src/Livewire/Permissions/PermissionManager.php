<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Permissions;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Permission;

/**
 * List screen for permissions, grouped by prefix with search and pagination (V6, V8).
 * Create/edit/delete handled by child modal components (NewPermission, EditPermission, DeletePermission).
 */
class PermissionManager extends Component
{
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
     * Re-render after a child modal saves or deletes.
     */
    #[On('permission-saved')]
    #[On('permission-deleted')]
    public function refresh(): void {}

    /**
     * Deterministic Tailwind color class for a permission group prefix.
     */
    public function colorForGroup(string $group): string
    {
        $palette = ['red', 'amber', 'emerald', 'sky', 'violet', 'rose', 'orange', 'teal', 'cyan', 'indigo'];

        return $palette[abs(crc32($group)) % count($palette)];
    }

    /**
     * Returns badge type for an ability: 'crud' (green), 'destructive' (red), 'custom' (yellow).
     */
    public function badgeTypeForAbility(string $ability): string
    {
        if (in_array($ability, ['delete', 'destroy', 'remove', 'purge', 'force-delete'], true)) {
            return 'destructive';
        }

        if (in_array($ability, ['create', 'read', 'update', 'list', 'view', 'show', 'index', 'store', 'edit'], true)) {
            return 'crud';
        }

        return 'custom';
    }

    public function render(): View
    {
        $sep = config('clearance.naming_separator', '-');

        $grouped = Permission::orderBy('name')
            ->get()
            ->groupBy(fn (Permission $p) => explode($sep, $p->name)[0])
            ->sortKeys();

        if ($this->search !== '') {
            $term = strtolower($this->search);
            $grouped = $grouped->filter(
                function ($perms, string $prefix) use ($term): bool {
                    if (str_contains(strtolower($prefix), $term)) {
                        return true;
                    }

                    return $perms->contains(
                        fn (Permission $p) => str_contains(strtolower($p->name), $term)
                    );
                }
            );
        }

        $page = $this->getPage();
        $perPage = 10;
        $total = $grouped->count();
        $pageItems = $grouped->slice(($page - 1) * $perPage, $perPage)->all();

        $groupedPermissions = new LengthAwarePaginator(
            $pageItems,
            $total,
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()]
        );

        return view('clearance::livewire.permissions.permission-manager', [
            'groupedPermissions' => $groupedPermissions,
        ]);
    }
}
