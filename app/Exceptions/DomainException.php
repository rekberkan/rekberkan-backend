<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

abstract class DomainException extends Exception
{
    protected string $type = 'https://rekberkan.com/errors/domain-error';
    protected string $title = 'Domain Error';
    protected int $statusCode = 400;
    protected array $context = [];

    public function __construct(string $message = '', array $context = [])
    {
        parent::__construct($message);
        $this->context = $context;
    }

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

    public function getContext(): array
    {
        return $this->context;
    }
}
