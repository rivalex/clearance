<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Users;

use Illuminate\Database\Eloquent\Model;
use Illuminate\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Rivalex\Clearance\Clearance;

/**
 * Top-level lazy wrapper for the user clearance management panel.
 * Accepts a userId and resolves the full user model with roles and permissions.
 */
#[Lazy]
class UserClearanceManager extends Component
{
    #[Locked]
    public int|string $userId;

    public function mount(int|string $userId): void
    {
        abort_unless(app(Clearance::class)->canPerform('users'), 403);
        $this->userId = $userId;
    }

    public function placeholder(): View
    {
        return view('clearance::livewire.users.placeholder');
    }

    public function render(): View
    {
        $user = $this->resolveUser();

        return view('clearance::livewire.users.manager', [
            'user'             => $user,
            'contextualModels' => config('clearance.contextual_models', []),
            'modulesEnabled'   => (bool) config('clearance.modules.users', false),
        ]);
    }

    /**
     * Fetch a fresh user instance with roles and direct permissions loaded.
     *
     * @return Model&\Illuminate\Contracts\Auth\Authenticatable
     */
    private function resolveUser(): Model
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('clearance.user_model')
            ?? config('auth.providers.users.model', 'App\\Models\\User');

        return $userModel::with(['roles.permissions', 'permissions'])->findOrFail($this->userId);
    }
}
