<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;

class FakeUser implements Authenticatable
{
    public function __construct(public readonly int $id = 1) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}
