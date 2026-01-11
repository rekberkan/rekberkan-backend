<?php

declare(strict_types=1);

namespace App\Exceptions\Escrow;

use App\Exceptions\DomainException;

final class InsufficientBalanceException extends DomainException
{
    protected string $type = 'https://rekberkan.com/errors/insufficient-balance';
    protected string $title = 'Insufficient Balance';
    protected int $statusCode = 402;

    public function __construct()
    {
        parent::__construct('Wallet has insufficient available balance for this operation.');
    }
}
