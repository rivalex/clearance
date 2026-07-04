<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Rivalex\Clearance\Livewire\Users\UserClearanceManager;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Rivalex\Clearance\Tests\Support\FakeContext;

beforeEach(function (): void {
    $this->runMigrations();

    // UserClearanceManager is #[Lazy]; disable it so mount() runs on Livewire::test().
    Livewire::withoutLazyLoading();

    Schema::create('fake_users', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    Schema::create('fake_contexts', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    config()->set('clearance.user_model', FakeEloquentUser::class);

    $this->user = FakeEloquentUser::create(['name' => 'U', 'email' => 't66@example.com', 'password' => 'x']);
});

it('mount sets the userId property', function (): void {
    Livewire::test(UserClearanceManager::class, ['userId' => $this->user->id])
        ->assertSet('userId', $this->user->id);
});

it('renders the manager view with the resolved user and config flags', function (): void {
    config()->set('clearance.modules.users', true);
    config()->set('clearance.contextual_models', [FakeContext::class => ['label' => 'Store']]);

    Livewire::test(UserClearanceManager::class, ['userId' => $this->user->id])
        ->assertViewIs('clearance::livewire.users.manager')
        ->assertViewHas('user', fn ($user) => $user->id === $this->user->id)
        ->assertViewHas('modulesEnabled', true)
        ->assertViewHas('contextualModels', [FakeContext::class => ['label' => 'Store']]);
});

it('renders the placeholder view before hydration', function (): void {
    $component = new UserClearanceManager;

    expect($component->placeholder()->name())->toBe('clearance::livewire.users.placeholder');
});
