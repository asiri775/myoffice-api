<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Users;
use App\Models\UserMeta;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;

final class SocialAuthController extends Controller
{
    use ApiResponse;

    private array $allowed = ['google','facebook'];

    /** GET /api/auth/{provider}/redirect */
    public function redirect(string $provider): JsonResponse
    {
        if (!$this->isAllowed($provider)) {
            return $this->fail(['provider' => ['Provider not supported']], 'Validation error', 422);
        }

        try {
            $socialite = Socialite::driver($provider)->stateless();
            
            // Set Facebook scopes explicitly (public_profile and email are required)
            if ($provider === 'facebook') {
                $scopes = config('services.facebook.scopes', ['public_profile', 'email']);
                $socialite->scopes($scopes);
            }
            
            $url = $socialite->redirect()->getTargetUrl();
            return $this->ok(['url' => $url], 'Auth URL');
        } catch (\Throwable $e) {
            $msg = app()->environment('production') ? 'Unable to start social login' : $e->getMessage();
            return $this->serverError($msg);
        }
    }

    /** GET /api/auth/{provider}/callback */
    public function callback(Request $request, string $provider): JsonResponse|RedirectResponse|Response
    {
        if (!$this->isAllowed($provider)) {
            return $this->fail(['provider' => ['Provider not supported']], 'Validation error', 422);
        }

        try {
            $social = Socialite::driver($provider)->stateless()->user();

            $email = strtolower(trim($social->getEmail() ?? ''));
            $name  = trim($social->getName() ?? '');
            
            // Handle different provider data structures
            // Google uses: given_name, family_name
            // Facebook uses: first_name, last_name
            if ($provider === 'google') {
                $first = trim($social->user['given_name'] ?? '');
                $last  = trim($social->user['family_name'] ?? '');
            } else {
                // Facebook and other providers
                $first = trim($social->user['first_name'] ?? $social->offsetGet('first_name') ?? '');
                $last  = trim($social->user['last_name'] ?? $social->offsetGet('last_name') ?? '');
            }

            if (!$first || !$last) {
                if ($name) {
                    [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');
                }
            }

            if (!$email) {
                // For Google and Facebook, redirect with error; for others, return JSON
                if ($provider === 'google' || $provider === 'facebook') {
                    return redirect('myofficeapp://auth/error?message=' . urlencode('Email permission is required on your social account'));
                }
                return $this->fail(['email' => ['Email permission is required on your social account']], 'Validation error', 400);
            }

            // Map provider -> meta keys
            [$idKey, $emailKey, $nameKey, $avatarKey] = $provider === 'google'
                ? ['social_google_id','social_google_email','social_google_name','social_google_avatar']
                : ['social_facebook_id','social_facebook_email','social_facebook_name','social_facebook_avatar'];

            // 1) Try existing link by provider id
            $link = UserMeta::where('name', $idKey)->where('val', (string) $social->getId())->first();
            $user = $link?->user;

            // 2) Else by email
            if (!$user) {
                $user = Users::where('email', $email)->first();
            }

            // 3) Create or rotate api_key
            $newApiKey = base64_encode(Str::random(40));
            $created   = false;

            if (!$user) {
                $user = new Users([
                    'first_name' => $first ?: ($name ?: 'User'),
                    'last_name'  => $last ?: '',
                    'name'       => trim(($first ?: '') . ' ' . ($last ?: '')) ?: ($name ?: 'User'),
                    'email'      => $email,
                    'password'   => Hash::make(Str::random(16)),
                    'role_id'    => 3,
                    'super_host' => 0,
                ]);
                $user->forceFill(['api_key' => $newApiKey])->save();
                $created = true;
            } else {
                $user->forceFill(['api_key' => $newApiKey])->save();
            }

            // 4) Upsert meta linkage
            UserMeta::upsertPairs($user->id, [
                $idKey                  => (string) $social->getId(),
                $emailKey               => $email,
                $nameKey                => $name ?: trim(($first ?: '') . ' ' . ($last ?: '')),
                $avatarKey              => (string) $social->getAvatar(),
                'social_meta_avatar'    => (string) $social->getAvatar(),
            ]);

            // 5) For Google and Facebook, redirect to deep link; for others, return JSON
            if ($provider === 'google' || $provider === 'facebook') {
                $deepLinkUrl = 'myofficeapp://auth/success?token=' . urlencode($user->api_key);
                
                // Check if request is from browser (User-Agent check) or has show_token param
                $userAgent = $request->header('User-Agent', '');
                $isBrowser = strpos($userAgent, 'Mozilla') !== false || $request->has('show_token');
                
                // If testing in browser, show token on page instead of deep link redirect
                if ($isBrowser || app()->environment('local')) {
                    $html = '<!DOCTYPE html>
<html>
<head>
    <title>OAuth Success</title>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
        .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; border-radius: 5px; margin: 20px 0; }
        .token { background: #f8f9fa; border: 1px solid #dee2e6; padding: 15px; border-radius: 5px; word-break: break-all; font-family: monospace; }
        .info { color: #6c757d; font-size: 14px; margin-top: 10px; }
        button { background: #007bff; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; margin-top: 10px; }
    </style>
</head>
<body>
    <h1>✅ Authentication Successful!</h1>
    <div class="success">
        <strong>Logged in as:</strong> ' . htmlspecialchars($user->email) . '<br>
        <strong>Name:</strong> ' . htmlspecialchars($user->name) . '
    </div>
    <h3>Your API Token:</h3>
    <div class="token" id="token">' . htmlspecialchars($user->api_key) . '</div>
    <button onclick="navigator.clipboard.writeText(document.getElementById(\'token\').textContent)">Copy Token</button>
    <div class="info">
        <p><strong>Deep Link URL:</strong><br>
        <code>' . htmlspecialchars($deepLinkUrl) . '</code></p>
        <p><em>Note: This deep link will work when opened from your mobile app. In browser, copy the token above.</em></p>
    </div>
</body>
</html>';
                    return response($html)->header('Content-Type', 'text/html');
                }
                
                return redirect($deepLinkUrl);
            }

            // For other providers, return JSON response
            $payload = $this->userPayload($user, $user->api_key, UserMeta::getValue($user->id, $avatarKey));

            return $created
                ? $this->created($payload, 'Account created via '.$provider)
                : $this->ok($payload, 'Login successful');

        } catch (\Throwable $e) {
            $msg = app()->environment('production')
                ? 'Unable to authenticate with '.$provider
                : $e->getMessage();
            
            // For Google and Facebook, redirect with error; for others, return JSON
            if ($provider === 'google' || $provider === 'facebook') {
                // Check if request is from browser
                $userAgent = $request->header('User-Agent', '');
                $isBrowser = strpos($userAgent, 'Mozilla') !== false;
                
                // If browser, show error page instead of deep link
                if ($isBrowser || app()->environment('local')) {
                    $html = '<!DOCTYPE html>
<html>
<head>
    <title>OAuth Error</title>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
        .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; border-radius: 5px; margin: 20px 0; }
        .info { color: #6c757d; font-size: 14px; margin-top: 10px; }
    </style>
</head>
<body>
    <h1>❌ Authentication Error</h1>
    <div class="error">
        <strong>Error:</strong> ' . htmlspecialchars($msg) . '
    </div>
    <div class="info">
        <p>Please try again or contact support if the issue persists.</p>
    </div>
</body>
</html>';
                    return response($html)->header('Content-Type', 'text/html');
                }
                
                return redirect('myofficeapp://auth/error?message=' . urlencode($msg));
            }
            
            return $this->serverError($msg);
        }
    }

    /* ---------------- helpers ---------------- */

    private function isAllowed(string $provider): bool
    {
        return in_array($provider, $this->allowed, true);
    }

    private function userPayload(Users $user, string $apiKey, ?string $avatar): array
    {
        return [
            'id'            => $user->id,
            'first_name'    => $user->first_name,
            'last_name'     => $user->last_name,
            'name'          => $user->name,
            'email'         => $user->email,
            'role_id'       => $user->role_id,
            'country'       => $user->country,
            'mobile_number' => $user->phone,
            'avatar'        => $avatar,
            'api_key'       => $apiKey,
            'created_at'    => $user->created_at,
            'updated_at'    => $user->updated_at,
        ];
    }
}