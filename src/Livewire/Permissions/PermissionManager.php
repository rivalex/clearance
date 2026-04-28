<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Permissions;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Rivalex\Clearance\Services\PermissionService;
use Spatie\Permission\Models\Permission;

/**
 * Full CRUD list screen for permissions, grouped by prefix with search and pagination (V6, V8).
 */
class PermissionManager extends Component
{
    use WithPagination;

    public bool $showForm = false;

    /** Prefix being edited; empty string = create mode. */
    public string $editingPrefix = '';

    public string $search = '';

    /** Prefix staged for deletion (T25). */
    public string $deletingPrefix = '';

    /** Typed confirmation text for delete (T25). */
    public string $deleteConfirmText = '';

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
     * Open form in create mode.
     */
    public function create(): void
    {
        $this->editingPrefix = '';
        $this->showForm = true;
        $this->deletingPrefix = '';
    }

    /**
     * Open form in edit mode for the given group prefix.
     */
    public function edit(string $prefix): void
    {
        $this->editingPrefix = $prefix;
        $this->showForm = true;
        $this->deletingPrefix = '';
    }

    /**
     * Stage a group prefix for typed-confirmation deletion (T25, V8).
     */
    public function delete(string $prefix): void
    {
        $this->deletingPrefix = $prefix;
        $this->deleteConfirmText = '';
        $this->showForm = false;
    }

    /**
     * Cancel the pending delete.
     */
    public function cancelDelete(): void
    {
        $this->deletingPrefix = '';
        $this->deleteConfirmText = '';
    }

    /**
     * Execute group delete after typed confirmation (T25, V8 — all writes via PermissionService).
     */
    public function confirmDeleteGroup(PermissionService $permissionService): void
    {
        if ($this->deletingPrefix === '') {
            return;
        }

        if ($this->deleteConfirmText !== 'DELETE '.$this->deletingPrefix) {
            return;
        }

        $sep = config('clearance.naming_separator', '-');

        Permission::where('name', 'like', $this->deletingPrefix.$sep.'%')->get()
            ->each(fn (Permission $p) => $permissionService->delete($p));

        $this->cancelDelete();
    }

    /**
     * Close form without saving.
     */
    public function closeForm(): void
    {
        $this->showForm = false;
        $this->editingPrefix = '';
    }

    /**
     * Refresh list when form reports a save.
     */
    #[On('permission-saved')]
    public function onPermissionSaved(): void
    {
        $this->showForm = false;
        $this->editingPrefix = '';
    }

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
