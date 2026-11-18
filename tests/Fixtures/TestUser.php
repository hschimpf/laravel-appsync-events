<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Minimal authenticatable user for feature tests.
 */
final class TestUser implements Authenticatable
{
    /**
     * @param  mixed  $id  The value returned by getAuthIdentifier().
     * @param  string|null  $name  An extra attribute tests can resolve uids from.
     */
    public function __construct(
        public readonly mixed $id = 1,
        public readonly ?string $name = null,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    /**
     * {@inheritDoc}
     */
    public function getAuthIdentifier(): mixed
    {
        return $this->id;
    }

    /**
     * {@inheritDoc}
     */
    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    /**
     * {@inheritDoc}
     */
    public function getAuthPassword(): string
    {
        return '';
    }

    /**
     * {@inheritDoc}
     */
    public function getAuthPasswordConfirmationName(): string
    {
        return 'password_confirmation';
    }

    /**
     * {@inheritDoc}
     */
    public function getRememberToken(): ?string
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function setRememberToken($value): void
    {
        //
    }

    /**
     * {@inheritDoc}
     */
    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}
