<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Users;

use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Rivalex\Clearance\Models\UserRoleContext;
use Spatie\Permission\Models\Role;

/**
 * Contextual role assignment panel for users.
 * Optional module (modules.users). Server-side scoped for manager access (V4).
 * No direct Spatie write calls (V8).
 */
#[Layout('clearance::layouts.app')]
class UserRoleManager extends Component
{
    /** @var array<int, UserRoleContext> */
    public array $assignments = [];

    /** @var array<int, Role> */
    public array $availableRoles = [];

    /** Null = full admin view; set = manager scoped to own context (V4). */
    public ?string $scopeContextType = null;

    public mixed $scopeContextId = null;

    public bool $showAssignForm = false;

    public mixed $assignUserId = null;

    public ?int $assignRoleId = null;

    public string $assignContextType = '';

    public mixed $assignContextId = null;

    public ?string $errorMessage = null;

    /**
     * Resolve manager scope and load data on mount (V4).
     */
    public function mount(): void
    {
        $this->resolveManagerScope();
        $this->loadData();
    }

    /**
     * Assign a contextual role to a user (V4 scope check; V8 — writes Clearance table only).
     */
    public function assign(): void
    {
        $this->errorMessage = null;

        if (! $this->assignUserId || ! $this->assignRoleId || ! $this->assignContextType || ! $this->assignContextId) {
            $this->errorMessage = 'All fields are required.';

            return;
        }

        // V4: manager cannot assign outside own context
        if ($this->scopeContextType !== null) {
            if ($this->assignContextType !== $this->scopeContextType
                || (string) $this->assignContextId !== (string) $this->scopeContextId) {
                $this->errorMessage = 'Cannot assign outside your managed context.';

                return;
            }
        }

        UserRoleContext::firstOrCreate([
            'user_id' => $this->assignUserId,
            'context_type' => $this->assignContextType,
            'context_id' => $this->assignContextId,
            'role_id' => $this->assignRoleId,
        ]);

        $this->showAssignForm = false;
        $this->resetAssignForm();
        $this->loadData();
    }

    /**
     * Revoke a contextual role assignment (V4 scope check; V8).
     */
    public function revoke(int $id): void
    {
        $assignment = UserRoleContext::find($id);

        if ($assignment === null) {
            return;
        }

        // V4: manager cannot revoke outside own context
        if ($this->scopeContextType !== null) {
            if ($assignment->context_type !== $this->scopeContextType
                || (string) $assignment->context_id !== (string) $this->scopeContextId) {
                $this->errorMessage = 'Cannot revoke outside your managed context.';

                return;
            }
        }

        $assignment->delete();
        $this->loadData();
    }

    public function render(): View
    {
        return view('clearance::livewire.users.user-role-manager');
    }

    /**
     * Admins have no UserRoleContext entry → see all contexts.
     * Managers have a UserRoleContext entry → scoped to their context (V4).
     */
    private function resolveManagerScope(): void
    {
        $user = auth()->user();

        if ($user === null) {
            return;
        }

        $ownContext = UserRoleContext::where('user_id', $user->getAuthIdentifier())->first();

        if ($ownContext !== null) {
            $this->scopeContextType = $ownContext->context_type;
            $this->scopeContextId = $ownContext->context_id;
            $this->assignContextType = $ownContext->context_type;
            $this->assignContextId = $ownContext->context_id;
        }
    }

    private function loadData(): void
    {
        $query = UserRoleContext::query();

        // V4: server-side scope — managers only see their context
        if ($this->scopeContextType !== null) {
            $query->where('context_type', $this->scopeContextType)
                ->where('context_id', $this->scopeContextId);
        }

        $this->assignments = $query->orderBy('user_id')->get()->all();
        $this->availableRoles = Role::orderBy('name')->get()->all();
    }

    private function resetAssignForm(): void
    {
        $this->assignUserId = null;
        $this->assignRoleId = null;

        if ($this->scopeContextType === null) {
            $this->assignContextType = '';
            $this->assignContextId = null;
        }
    }
}
