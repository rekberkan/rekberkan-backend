<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

abstract class DomainException extends Exception
{
    protected string $type = 'https://rekberkan.com/errors/domain-error';
    protected string $title = 'Domain Error';
    protected int $statusCode = 400;

    public function getType(): string
    {
        return $this->type;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Convert to RFC 7807 Problem Details
     */
    public function toProblemDetails(): array
    {
        return [
            'type' => $this->getType(),
            'title' => $this->getTitle(),
            'status' => $this->getStatusCode(),
            'detail' => $this->getMessage(),
        ];
    }
}
