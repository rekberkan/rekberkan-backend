<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Formatter\JsonFormatter as BaseJsonFormatter;
use Monolog\LogRecord;

class JsonFormatter extends BaseJsonFormatter
{
    /**
     * Format log record with PII-safe structured output.
     */
    public function format(LogRecord $record): string
    {
        $data = [
            'timestamp' => $record->datetime->format('Y-m-d\TH:i:s.uP'),
            'level' => $record->level->getName(),
            'message' => $record->message,
            'context' => $this->sanitizeContext($record->context),
            'extra' => $record->extra,
        ];

        return parent::toJson($data) . "\n";
    }

    /**
     * Sanitize context to prevent PII leakage.
     * Remove sensitive fields that should never appear in logs.
     */
    private function sanitizeContext(array $context): array
    {
        $sensitiveKeys = [
            'password',
            'password_confirmation',
            'token',
            'access_token',
            'refresh_token',
            'secret',
            'api_key',
            'authorization',
            'cookie',
            'pin',
            'otp',
        ];

        foreach ($sensitiveKeys as $key) {
            if (isset($context[$key])) {
                $context[$key] = '[REDACTED]';
            }
        }

        return $context;
    }
}
