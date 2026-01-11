<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use App\Exceptions\DomainException;

final class DeviceMismatchException extends DomainException
{
    protected string $type = 'https://rekberkan.com/errors/device-mismatch';
    protected string $title = 'Device Mismatch';
    protected int $statusCode = 401;

    public function __construct()
    {
        parent::__construct('Token device fingerprint does not match. Please login again.');
    }
}
