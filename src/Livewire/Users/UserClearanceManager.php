<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Livewire\Users;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Concerns\HasClearanceLayout;
use Rivalex\Clearance\Support\Identity;

/**
 * Top-level lazy wrapper for the user clearance management panel.
 * Accepts a userId and resolves the full user model with roles and permissions.
 */
#[Lazy]
class UserClearanceManager extends Component
{
    use HasClearanceLayout;

    #[Locked]
    public int|string $userId;

    public function mount(int|string $userId): void
    {
        $this->userId = $userId;

        $clearance = app(Clearance::class);
        abort_unless((bool) config('clearance.modules.users', false), 404);
        abort_unless(ctype_digit((string) $this->userId), 403);
        abort_unless($clearance->canPerform('users') || $clearance->managesAnyContext(), 403);
        abort_unless($clearance->canManageUser($this->resolveUser()), 403);
    }

    public function placeholder(): View
    {
        return $this->withClearanceLayout(view('clearance::livewire.users.placeholder'));
    }

    public function render(): View
    {
        $clearance = app(Clearance::class);
        abort_unless($clearance->canPerform('users') || $clearance->managesAnyContext(), 403);

        $user = $this->resolveUser();
        abort_unless($clearance->canManageUser($user), 403);

        return $this->withClearanceLayout(view('clearance::livewire.users.manager', [
            'user' => $user,
            'contextualModels' => config('clearance.contextual_models', []),
            'modulesEnabled' => (bool) config('clearance.modules.users', false),
            'showGlobalPanel' => $clearance->canPerform('users'),
        ]));
    }

    /**
     * Fetch a fresh user instance with roles and direct permissions loaded. A missing user aborts with 403.
     *
     * @return Model&Authenticatable
     */
    private function resolveUser(): Model
    {
        /** @var class-string<Model>|null $userModel */
        $userModel = Identity::userModel();

        if ($userModel === null || ! class_exists($userModel)) {
            throw new \LogicException('No user model is configured: set clearance.user_model.');
        }

        // A missing user and an unmanaged one both answer 403, so the panel does not reveal which ids exist.
        try {
            $user = $userModel::with(['roles.permissions', 'permissions'])->findOrFail($this->userId);
        } catch (ModelNotFoundException) {
            abort(403);
        }

        if (! $user instanceof Authenticatable) {
            throw new \LogicException('Configured user model ['.$userModel.'] must implement Authenticatable.');
        }

        return $user;
    }
}
