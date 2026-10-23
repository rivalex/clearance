<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Rivalex\Clearance\Clearance;
use Rivalex\Clearance\Tests\Support\FakeEloquentUser;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    $this->runMigrations();

    Schema::create('fake_users', static function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    // The suite runs with APP_ENV=testing; the old shortcut keyed off exactly that.
    expect(app()->runningUnitTests())->toBeTrue();
});

it('refuses a guest on all three checks when the flag is false, even with APP_ENV=testing', function (): void {
    Clearance::$bypassGuestChecksForTests = false;

    $clearance = app(Clearance::class);

    expect(auth()->user())->toBeNull()
        ->and($clearance->canAccess())->toBeFalse()
        ->and($clearance->canPerform('users'))->toBeFalse()
        ->and($clearance->managesContext(new FakeEloquentUser))->toBeFalse();
});

it('lets a guest through on all three checks when the flag is true', function (): void {
    Clearance::$bypassGuestChecksForTests = true;

    $clearance = app(Clearance::class);

    expect($clearance->canAccess())->toBeTrue()
        ->and($clearance->canPerform('users'))->toBeTrue()
        ->and($clearance->managesContext(new FakeEloquentUser))->toBeTrue();
});

it('does not let the flag affect an authenticated user without permissions', function (): void {
    Permission::create(['name' => 'clearance-access', 'guard_name' => 'web']);

    $user = FakeEloquentUser::create(['name' => 'Plain', 'email' => 'plain@example.com', 'password' => 'x']);
    Auth::guard()->setUser($user);

    foreach ([true, false] as $flag) {
        Clearance::$bypassGuestChecksForTests = $flag;

        $clearance = app(Clearance::class);

        expect($clearance->canAccess())->toBeFalse()
            ->and($clearance->canPerform('users'))->toBeFalse()
            ->and($clearance->managesContext(new FakeEloquentUser))->toBeFalse();
    }
});
