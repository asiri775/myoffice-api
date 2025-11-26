<?php
declare(strict_types=1);

namespace App\Providers;

use App\Support\Settings;
use Illuminate\Support\ServiceProvider;

final class SocialAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Nothing required here
    }

    public function boot(): void
    {
        // Google OAuth - Use new credentials
        // Priority: env vars > new hardcoded credentials > database settings
        $googleId = env('GOOGLE_CLIENT_ID');
        $googleSecret = env('GOOGLE_CLIENT_SECRET');
        
        // Fallback to database settings if env vars not set
        if (!$googleId || !$googleSecret) {
            $googleId = Settings::get('google_client_id', 'advance');
            $googleSecret = Settings::get('google_client_secret', 'advance');
        }
        
        config([
            'services.google.client_id'     => $googleId,
            'services.google.client_secret' => $googleSecret,
            'services.google.redirect'      => env('GOOGLE_REDIRECT_URI', 'http://api.mybackpocket.co/api/oauth/google/callback'),
        ]);

        // Facebook OAuth - Keep existing database settings logic
        $fbId     = Settings::get('facebook_client_id', 'advance');
        $fbSecret = Settings::get('facebook_client_secret', 'advance');

        if ($fbId && $fbSecret) {
            config([
                'services.facebook.client_id'     => $fbId,
                'services.facebook.client_secret' => $fbSecret,
            ]);
        }
    }
}