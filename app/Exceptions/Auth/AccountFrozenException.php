<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use App\Exceptions\DomainException;

final class AccountFrozenException extends DomainException
{
    protected string $type = 'https://rekberkan.com/errors/account-frozen';
    protected string $title = 'Account Frozen';
    protected int $statusCode = 403;

    public function __construct()
    {
        parent::__construct('Your account has been frozen. Please contact support.');
    }
}
