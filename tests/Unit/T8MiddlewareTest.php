<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Rivalex\Clearance\Http\Middleware\RequireClearanceAccess;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('allows request when user has access permission (V1)', function (): void {
    $user = Mockery::mock(Authenticatable::class);
    $user->shouldReceive('can')->with('clearance-access')->andReturn(true);

    $request = Request::create('/clearance', 'GET');
    $request->setUserResolver(fn () => $user);

    $response = (new RequireClearanceAccess)->handle($request, fn ($req) => new Response('OK'));

    expect($response->getContent())->toBe('OK');
});

it('returns 403 when user lacks access permission (V1)', function (): void {
    $user = Mockery::mock(Authenticatable::class);
    $user->shouldReceive('can')->with('clearance-access')->andReturn(false);

    $request = Request::create('/clearance', 'GET');
    $request->setUserResolver(fn () => $user);

    expect(fn () => (new RequireClearanceAccess)->handle($request, fn ($req) => new Response('OK')))
        ->toThrow(HttpException::class);
});

it('returns 403 when no user authenticated (V1)', function (): void {
    $request = Request::create('/clearance', 'GET');
    $request->setUserResolver(fn () => null);

    expect(fn () => (new RequireClearanceAccess)->handle($request, fn ($req) => new Response('OK')))
        ->toThrow(HttpException::class);
});

it('uses can() not hasRole() in source (V1)', function (): void {
    $source = file_get_contents(
        realpath(__DIR__.'/../../src/Http/Middleware/RequireClearanceAccess.php')
    );

    expect($source)->toContain('->can(')
        ->not->toContain('hasRole(');
});
