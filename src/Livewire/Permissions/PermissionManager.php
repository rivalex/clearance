<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Permissions;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Concerns\HasClearanceLayout;
use Rivalex\Clearance\Models\Permission;
use Rivalex\Clearance\Support\LikePattern;

/**
 * List screen for permissions, grouped by prefix with search and pagination (V6, V8).
 * Create/edit/delete handled by child modal components (NewPermission, EditPermission, DeletePermission).
 */
class PermissionManager extends Component
{
    use HasClearanceLayout;
    use WithPagination;

    public string $search = '';

    /**
     * No-op mount kept for test/Livewire lifecycle compatibility.
     */
    public function mount(): void {}

    /**
     * Cap the incoming search value and reset pagination when search changes.
     */
    public function updatedSearch(): void
    {
        $this->search = mb_substr($this->search, 0, 100);
        $this->resetPage();
    }

    /**
     * Re-render after a child modal saves or deletes.
     */
    #[On('permission-saved')]
    #[On('permission-deleted')]
    public function refresh(): void
    {
        $this->resetPage();
    }

    /**
     * The naming separator from config, limited to a hyphen or an underscore so it is safe inside SQL.
     */
    private static function separator(): string
    {
        $sep = (string) config('clearance.naming_separator', '-');

        return preg_match('/^[\-_]$/', $sep) === 1 ? $sep : '-';
    }

    /**
     * SQL expression that extracts the group (the part of `name` before the first separator) for a database driver.
     *
     * `sqlsrv` uses CHARINDEX and SUBSTRING, `pgsql` SPLIT_PART, `mysql` and `mariadb` SUBSTRING_INDEX. Any other driver
     * (SQLite included) takes the INSTR form.
     */
    public static function groupExpression(string $driver): string
    {
        $sep = self::separator();

        return match ($driver) {
            'mysql', 'mariadb' => "SUBSTRING_INDEX(name, '{$sep}', 1)",
            'pgsql' => "SPLIT_PART(name, '{$sep}', 1)",
            'sqlsrv' => "CASE WHEN CHARINDEX('{$sep}', name) > 0 THEN SUBSTRING(name, 1, CHARINDEX('{$sep}', name) - 1) ELSE name END",
            default => "CASE WHEN INSTR(name, '{$sep}') > 0 THEN SUBSTR(name, 1, INSTR(name, '{$sep}') - 1) ELSE name END",
        };
    }

    /**
     * Fetches and groups permissions by prefix and guard.
     */
    protected function getGroupedPermissions(): LengthAwarePaginator
    {
        $sep = self::separator();
        $connection = (new Permission)->getConnection();
        $groupSql = self::groupExpression($connection->getDriverName());
        $grammar = $connection->getQueryGrammar();

        $query = Permission::query()
            ->selectRaw("{$groupSql} as permission_group, guard_name")
            ->when(! empty($this->search), function ($q) use ($grammar) {
                $pattern = LikePattern::contains($this->search);

                $q->where(function ($sub) use ($grammar, $pattern) {
                    $sub->whereRaw($grammar->wrap('name')." LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw($grammar->wrap('guard_name')." LIKE ? ESCAPE '!'", [$pattern]);
                });
            })
            ->groupByRaw($groupSql)
            ->groupBy('guard_name')
            ->orderBy('permission_group');

        $paginated = $query->paginate(10);

        $paginated->getCollection()->transform(function ($item) use ($sep) {
            $group = $item->permission_group;
            $guard = $item->guard_name;
            $key = $group.$sep.$guard;

            return [
                'group' => $group,
                'guard' => $guard,
                'groupKey' => md5($key),
            ];
        });

        return $paginated;
    }

    public function render(): View
    {
        abort_unless(app(Clearance::class)->canAccess(), 403);

        return $this->withClearanceLayout(view('clearance::livewire.permissions.permission-manager', [
            'groupedPermissions' => $this->getGroupedPermissions(),
        ]));
    }
}
