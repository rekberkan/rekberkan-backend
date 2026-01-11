<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * File Upload Validation Middleware
 * 
 * SECURITY FIX: Bug #11 - Comprehensive file upload security
 */
class ValidateFileUpload
{
    /**
     * Allowed MIME types for different file categories
     */
    private const ALLOWED_MIME_TYPES = [
        // Images (for KYC, profile photos)
        'image' => [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/webp',
        ],
        // Documents (for KYC verification)
        'document' => [
            'application/pdf',
            'image/jpeg',
            'image/jpg',
            'image/png',
        ],
        // General files (with strict limitations)
        'general' => [
            'application/pdf',
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/webp',
        ],
    ];

    /**
     * Dangerous file extensions that should never be uploaded
     */
    private const BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'phtml',
        'exe', 'bat', 'cmd', 'com',
        'js', 'jar', 'vbs',
        'sh', 'bash', 'zsh',
        'dll', 'so', 'dylib',
        'asp', 'aspx', 'jsp',
        'cgi', 'pl', 'py', 'rb',
        'htaccess', 'htpasswd',
    ];

    /**
     * Maximum file sizes (in bytes)
     */
    private const MAX_FILE_SIZES = [
        'image' => 5 * 1024 * 1024,      // 5 MB
        'document' => 10 * 1024 * 1024,  // 10 MB
        'general' => 5 * 1024 * 1024,    // 5 MB
    ];

    public function handle(Request $request, Closure $next, string $category = 'general'): Response
    {
        // Check if request has files
        if (!$request->hasFile('file') && !$request->hasFile('files')) {
            return $next($request);
        }

        $files = $request->hasFile('files') 
            ? $request->file('files') 
            : [$request->file('file')];

        // Validate each file
        foreach ($files as $file) {
            if (!$file || !$file->isValid()) {
                return $this->error('Invalid file upload');
            }

            // 1. Check file extension
            $extension = strtolower($file->getClientOriginalExtension());
            if (in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
                Log::warning('Blocked file extension upload attempt', [
                    'extension' => $extension,
                    'ip' => $request->ip(),
                    'user_id' => $request->user()?->id,
                ]);
                return $this->error('File type not allowed');
            }

            // 2. Check MIME type
            $mimeType = $file->getMimeType();
            $allowedMimes = self::ALLOWED_MIME_TYPES[$category] ?? self::ALLOWED_MIME_TYPES['general'];
            
            if (!in_array($mimeType, $allowedMimes, true)) {
                Log::warning('Invalid MIME type upload attempt', [
                    'mime_type' => $mimeType,
                    'extension' => $extension,
                    'ip' => $request->ip(),
                    'user_id' => $request->user()?->id,
                ]);
                return $this->error('File type not supported');
            }

            // 3. Check file size
            $maxSize = self::MAX_FILE_SIZES[$category] ?? self::MAX_FILE_SIZES['general'];
            if ($file->getSize() > $maxSize) {
                return $this->error(
                    'File too large. Maximum size: ' . ($maxSize / 1024 / 1024) . ' MB'
                );
            }

            // 4. Validate actual file content matches MIME type
            if (!$this->validateFileContent($file, $mimeType)) {
                Log::warning('File content mismatch upload attempt', [
                    'mime_type' => $mimeType,
                    'extension' => $extension,
                    'ip' => $request->ip(),
                    'user_id' => $request->user()?->id,
                ]);
                return $this->error('File content validation failed');
            }

            // 5. Scan for malicious patterns (basic check)
            if ($this->containsMaliciousPatterns($file)) {
                Log::critical('Malicious file upload attempt blocked', [
                    'filename' => $file->getClientOriginalName(),
                    'mime_type' => $mimeType,
                    'ip' => $request->ip(),
                    'user_id' => $request->user()?->id,
                ]);
                return $this->error('File rejected by security scanner');
            }
        }

        return $next($request);
    }

    /**
     * Validate file content matches declared MIME type
     */
    private function validateFileContent($file, string $mimeType): bool
    {
        try {
            // Read first few bytes to check file signature
            $handle = fopen($file->getRealPath(), 'rb');
            if (!$handle) {
                return false;
            }

            $bytes = fread($handle, 8);
            fclose($handle);

            // Check magic numbers for common file types
            return match($mimeType) {
                'image/jpeg', 'image/jpg' => str_starts_with($bytes, "\xFF\xD8\xFF"),
                'image/png' => str_starts_with($bytes, "\x89PNG\r\n\x1A\n"),
                'image/webp' => str_contains($bytes, 'WEBP'),
                'application/pdf' => str_starts_with($bytes, '%PDF'),
                default => true, // Allow if not checked
            };
        } catch (\Exception $e) {
            Log::error('File content validation error', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Scan file for malicious patterns
     */
    private function containsMaliciousPatterns($file): bool
    {
        try {
            $content = file_get_contents($file->getRealPath());
            
            // Check for common malicious patterns
            $patterns = [
                '/<\?php/i',
                '/eval\s*\(/i',
                '/base64_decode\s*\(/i',
                '/system\s*\(/i',
                '/exec\s*\(/i',
                '/shell_exec\s*\(/i',
                '/passthru\s*\(/i',
                '/<script[^>]*>/i',
                '/javascript:/i',
                '/on\w+\s*=/i', // event handlers
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $content)) {
                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            Log::error('Malicious pattern scan error', [
                'error' => $e->getMessage(),
            ]);
            // Fail-safe: reject file if scan fails
            return true;
        }
    }

    /**
     * Return error response
     */
    private function error(string $message): Response
    {
        return response()->json([
            'success' => false,
            'type' => 'https://rekberkan.com/errors/file-validation-failed',
            'title' => 'File Upload Error',
            'status' => 422,
            'detail' => $message,
        ], 422);
    }
}
