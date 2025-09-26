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
        // Your dump shows these in group 'advance'
        $googleId     = Settings::get('google_client_id', 'advance');
        $googleSecret = Settings::get('google_client_secret', 'advance');

        $fbId         = Settings::get('facebook_client_id', 'advance');
        $fbSecret     = Settings::get('facebook_client_secret', 'advance');

        if ($googleId && $googleSecret) {
            config([
                'services.google.client_id'     => $googleId,
                'services.google.client_secret' => $googleSecret,
            ]);
        }

        if ($fbId && $fbSecret) {
            config([
                'services.facebook.client_id'     => $fbId,
                'services.facebook.client_secret' => $fbSecret,
            ]);
        }
    }
}