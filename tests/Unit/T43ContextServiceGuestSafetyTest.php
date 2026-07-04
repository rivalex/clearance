<?php

declare(strict_types=1);

use Rivalex\Clearance\Services\ContextService;
use Rivalex\Clearance\Tests\Support\FakeContext;

// Regression test for F7 (rivalex/clearance security audit,
// docs/plans/security-audit/plan.md): @canin() / @hasrolein() compile to
// ->canIn(auth()->user(), ...) / ->hasRoleIn(auth()->user(), ...). For a guest,
// auth()->user() is null; canIn()/hasRoleIn() previously type-hinted a non-null
// Authenticatable, so a guest triggered a TypeError (500) instead of a clean deny.

beforeEach(function (): void {
    $this->runMigrations();
    $this->service = new ContextService;
    $this->context = tap(new FakeContext, fn ($c) => $c->setAttribute('id', 1));
});

it('canIn returns false (not a TypeError) for a guest user', function (): void {
    expect($this->service->canIn(null, 'posts-view', $this->context))->toBeFalse();
});

it('hasRoleIn returns false (not a TypeError) for a guest user', function (): void {
    expect($this->service->hasRoleIn(null, 'editor', $this->context))->toBeFalse();
});
