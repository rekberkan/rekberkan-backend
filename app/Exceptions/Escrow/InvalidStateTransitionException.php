<?php

declare(strict_types=1);

namespace App\Exceptions\Escrow;

use App\Exceptions\DomainException;

final class InvalidStateTransitionException extends DomainException
{
    protected string $type = 'https://rekberkan.com/errors/invalid-state-transition';
    protected string $title = 'Invalid State Transition';
    protected int $statusCode = 400;
}
