<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Rivalex\Clearance\Models\UserContextPermissionOverride;
use Rivalex\Clearance\Models\UserRoleContext;
use Rivalex\Clearance\Services\ContextService;
use Rivalex\Clearance\Support\Identity;

/**
 * Removes contextual role bindings and permission overrides whose user or context no longer exists.
 */
class ClearancePruneCommand extends Command
{
    /** @var string */
    protected $signature = 'clearance:prune {--dry-run : Report without deleting} {--force : Delete without asking for confirmation}';

    /** @var string */
    protected $description = 'Remove contextual bindings and overrides whose user or context no longer exists.';

    /**
     * Prints the trusted user model and the orphan totals, then (unless --dry-run) deletes after confirmation.
     *
     * A non-interactive run deletes only with --force. The user model is printed first because an orphan is a
     * row whose user is missing from that model's table: after an identity change the totals can be wrong.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $userModel = Identity::userModel();
        $checkUsers = is_string($userModel) && class_exists($userModel) && is_subclass_of($userModel, Model::class);

        $this->line('User model: '.($userModel ?? 'not resolved'));

        if (! $checkUsers) {
            $this->warn('User model not resolved: user check skipped.');
        }

        $bindings = $this->prune(UserRoleContext::class, $userModel, $checkUsers, true, true);
        $overrides = $this->prune(UserContextPermissionOverride::class, $userModel, $checkUsers, true, true);

        $this->info('Orphan bindings: '.$bindings);
        $this->info('Orphan overrides: '.$overrides);

        if ($dryRun) {
            $this->info('Dry run: nothing deleted.');

            return self::SUCCESS;
        }

        if ($bindings + $overrides === 0) {
            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->warn('Nothing deleted: run clearance:prune with --force to delete without confirmation.');

                return self::FAILURE;
            }

            if (! $this->confirm("Delete {$bindings} orphan bindings and {$overrides} orphan overrides?", false)) {
                $this->info('Nothing deleted.');

                return self::SUCCESS;
            }
        }

        $this->prune(UserRoleContext::class, $userModel, $checkUsers, false, false);
        $this->prune(UserContextPermissionOverride::class, $userModel, $checkUsers, false, false);

        // Query deletes fire no model events: drop the resolution memo once at the end.
        app(ContextService::class)->flush();

        return self::SUCCESS;
    }

    /**
     * Finds (and unless $dryRun deletes) the orphan rows of one Clearance model, returning how many there are.
     * The unknown context type warnings are printed only when $report is true.
     *
     * @param  class-string<Model>  $rowModel
     * @param  class-string<Model>|null  $userModel
     */
    private function prune(string $rowModel, ?string $userModel, bool $checkUsers, bool $dryRun, bool $report): int
    {
        $count = 0;

        // Context types the host can no longer resolve are reported once and never pruned.
        $unknownTypes = [];
        $knownTypes = [];

        foreach ($rowModel::query()->distinct()->pluck('context_type') as $type) {
            $type = (string) $type;

            if (class_exists($type) && is_subclass_of($type, Model::class)) {
                $knownTypes[] = $type;
            } else {
                $unknownTypes[] = $type;
            }
        }

        if ($report) {
            foreach ($unknownTypes as $type) {
                $this->warn("Unknown context type [{$type}]: not pruned.");
            }
        }

        $rowModel::query()->chunkById(500, function ($rows) use (&$count, $rowModel, $userModel, $checkUsers, $knownTypes, $dryRun): void {
            $orphanIds = [];

            $missingUsers = $checkUsers && $userModel !== null
                ? $this->missingKeys($userModel, $rows->pluck('user_id')->unique()->all())
                : [];

            $missingContexts = [];

            foreach ($rows->groupBy('context_type') as $type => $group) {
                $type = (string) $type;

                if (in_array($type, $knownTypes, true)) {
                    /** @var class-string<Model> $type */
                    $missingContexts[$type] = $this->missingKeys($type, $group->pluck('context_id')->unique()->all());
                }
            }

            foreach ($rows as $row) {
                // A type the host cannot resolve is never pruned, whatever the state of its user.
                if (! in_array($row->getAttribute('context_type'), $knownTypes, true)) {
                    continue;
                }

                $userGone = $checkUsers && in_array($row->getAttribute('user_id'), $missingUsers, false);
                $contextGone = in_array($row->getAttribute('context_id'), $missingContexts[$row->getAttribute('context_type')] ?? [], false);

                if ($userGone || $contextGone) {
                    $orphanIds[] = $row->getKey();
                }
            }

            $count += count($orphanIds);

            if ($orphanIds !== [] && ! $dryRun) {
                $rowModel::query()->whereKey($orphanIds)->delete();
            }
        });

        return $count;
    }

    /**
     * The keys among $ids that have no row in the model's table, ignoring every global scope.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<int, mixed>  $ids
     * @return array<int, mixed>
     */
    private function missingKeys(string $modelClass, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $keyName = (new $modelClass)->getKeyName();

        $existing = $modelClass::query()->withoutGlobalScopes()->whereKey($ids)->pluck($keyName)->all();

        return array_values(array_filter(
            $ids,
            static fn ($id): bool => ! in_array($id, $existing, false),
        ));
    }
}
