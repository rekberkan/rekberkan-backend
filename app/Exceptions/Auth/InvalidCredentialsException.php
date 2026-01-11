<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use App\Exceptions\DomainException;

final class InvalidCredentialsException extends DomainException
{
    protected string $type = 'https://rekberkan.com/errors/invalid-credentials';
    protected string $title = 'Invalid Credentials';
    protected int $statusCode = 401;

    public function __construct()
    {
        parent::__construct('The provided credentials are invalid.');
    }
}
