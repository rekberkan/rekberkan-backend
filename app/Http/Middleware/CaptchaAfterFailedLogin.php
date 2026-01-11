<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class CaptchaAfterFailedLogin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $identifier = $this->getIdentifier($request);
        $attempts = Cache::get("login_attempts:{$identifier}", 0);

        // Jika sudah 3x atau lebih gagal login, require CAPTCHA
        if ($attempts >= 3) {
            $this->validateCaptcha($request);
        }

        $response = $next($request);

        // Track failed login attempts
        if ($request->is('api/auth/login') && $response->status() === 401) {
            $this->incrementAttempts($identifier);
        }

        // Reset attempts on successful login
        if ($request->is('api/auth/login') && $response->status() === 200) {
            $this->resetAttempts($identifier);
        }

        return $response;
    }

    /**
     * Get unique identifier for rate limiting
     */
    private function getIdentifier(Request $request): string
    {
        $email = $request->input('email', '');
        $ip = $request->ip();
        
        return hash('sha256', $email . '|' . $ip);
    }

    /**
     * Increment failed login attempts
     */
    private function incrementAttempts(string $identifier): void
    {
        $attempts = Cache::get("login_attempts:{$identifier}", 0);
        Cache::put("login_attempts:{$identifier}", $attempts + 1, now()->addHour());
    }

    /**
     * Reset failed login attempts
     */
    private function resetAttempts(string $identifier): void
    {
        Cache::forget("login_attempts:{$identifier}");
    }

    /**
     * Validate CAPTCHA response
     */
    private function validateCaptcha(Request $request): void
    {
        $captchaToken = $request->input('captcha_token');
        
        if (empty($captchaToken)) {
            abort(403, 'CAPTCHA required after multiple failed login attempts');
        }

        // Validate with Google reCAPTCHA v3
        $secretKey = config('services.recaptcha.secret_key');
        $response = \Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => $secretKey,
            'response' => $captchaToken,
            'remoteip' => $request->ip(),
        ]);

        $result = $response->json();

        if (!$result['success'] || $result['score'] < 0.5) {
            abort(403, 'CAPTCHA validation failed');
        }
    }
}
