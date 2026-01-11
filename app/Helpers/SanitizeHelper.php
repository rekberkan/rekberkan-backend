<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Input Sanitization Helper
 * 
 * SECURITY FIX: Bug #10 - Comprehensive input sanitization
 */
class SanitizeHelper
{
    /**
     * Sanitize string input - remove HTML tags and dangerous characters
     */
    public static function string(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Remove HTML tags
        $value = strip_tags($value);
        
        // Remove null bytes
        $value = str_replace(chr(0), '', $value);
        
        // Trim whitespace
        $value = trim($value);
        
        return $value;
    }

    /**
     * Sanitize email - strict email validation
     */
    public static function email(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = self::string($value);
        $value = filter_var($value, FILTER_SANITIZE_EMAIL);
        
        // Validate email format
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        
        return strtolower($value);
    }

    /**
     * Sanitize phone number - keep only digits and + sign
     */
    public static function phone(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Keep only digits, +, and hyphens
        $value = preg_replace('/[^0-9+\-]/', '', $value);
        
        return $value;
    }

    /**
     * Sanitize integer input
     */
    public static function integer($value): ?int
    {
        if ($value === null) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_INT) !== false 
            ? (int) $value 
            : null;
    }

    /**
     * Sanitize float/decimal input
     */
    public static function decimal($value): ?float
    {
        if ($value === null) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_FLOAT) !== false 
            ? (float) $value 
            : null;
    }

    /**
     * Sanitize boolean input
     */
    public static function boolean($value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * Sanitize URL
     */
    public static function url(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = filter_var($value, FILTER_SANITIZE_URL);
        
        // Validate URL format and allow only http/https
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }
        
        $parsed = parse_url($value);
        if (!in_array($parsed['scheme'] ?? '', ['http', 'https'], true)) {
            return null;
        }
        
        return $value;
    }

    /**
     * Sanitize textarea/description - allow basic formatting but remove scripts
     */
    public static function textarea(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Remove script tags and event handlers
        $value = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $value);
        $value = preg_replace('/on\w+\s*=\s*["\'][^"\']*["\']/i', '', $value);
        
        // Remove null bytes
        $value = str_replace(chr(0), '', $value);
        
        // Trim
        $value = trim($value);
        
        return $value;
    }

    /**
     * Sanitize array of strings
     */
    public static function stringArray(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return array_map([self::class, 'string'], $values);
    }

    /**
     * Sanitize filename - remove dangerous characters
     */
    public static function filename(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Remove path traversal attempts
        $value = basename($value);
        
        // Remove dangerous characters
        $value = preg_replace('/[^a-zA-Z0-9._\-]/', '', $value);
        
        // Prevent hidden files
        $value = ltrim($value, '.');
        
        return $value ?: null;
    }

    /**
     * Sanitize JSON input
     */
    public static function json(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException $e) {
            return null;
        }
    }

    /**
     * Sanitize SQL LIKE search term
     */
    public static function searchTerm(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Remove wildcards that user might try to inject
        $value = str_replace(['%', '_'], ['\\%', '\\_'], $value);
        
        // Basic sanitization
        $value = self::string($value);
        
        return $value;
    }

    /**
     * Sanitize multiple inputs at once
     */
    public static function sanitizeArray(array $data, array $rules): array
    {
        $sanitized = [];

        foreach ($rules as $key => $type) {
            if (!isset($data[$key])) {
                continue;
            }

            $sanitized[$key] = match($type) {
                'string' => self::string($data[$key]),
                'email' => self::email($data[$key]),
                'phone' => self::phone($data[$key]),
                'integer' => self::integer($data[$key]),
                'decimal' => self::decimal($data[$key]),
                'boolean' => self::boolean($data[$key]),
                'url' => self::url($data[$key]),
                'textarea' => self::textarea($data[$key]),
                'filename' => self::filename($data[$key]),
                'search' => self::searchTerm($data[$key]),
                default => $data[$key],
            };
        }

        return $sanitized;
    }
}
