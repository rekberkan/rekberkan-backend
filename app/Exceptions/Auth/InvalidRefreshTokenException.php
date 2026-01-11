<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use App\Exceptions\DomainException;

final class InvalidRefreshTokenException extends DomainException
{
    protected string $type = 'https://rekberkan.com/errors/invalid-refresh-token';
    protected string $title = 'Invalid Refresh Token';
    protected int $statusCode = 401;

    public function __construct()
    {
        parent::__construct('The refresh token is invalid or expired.');
    }
}
