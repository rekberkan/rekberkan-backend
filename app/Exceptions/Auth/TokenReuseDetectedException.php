<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use App\Exceptions\DomainException;

final class TokenReuseDetectedException extends DomainException
{
    protected string $type = 'https://rekberkan.com/errors/token-reuse-detected';
    protected string $title = 'Security Violation: Token Reuse Detected';
    protected int $statusCode = 401;

    public function __construct()
    {
        parent::__construct(
            'Token reuse detected. All tokens in this family have been revoked. Please login again.'
        );
    }
}
