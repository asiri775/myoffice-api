<?php

namespace App\Providers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register() {}

    public function boot()
    {
        // Locale / timezone (optional)
        if ($tz = setting_item('site_timezone')) {
            Config::set('app.timezone', $tz);
            date_default_timezone_set($tz);
        }

        if ($from = setting_item('email_from_address')) {
            Config::set('mail.from.address', $from);
        }
        if ($fromName = setting_item('email_from_name')) {
            Config::set('mail.from.name', $fromName);
        }

        /**
         * ------------------------------------------------------
         * Driver selection
         * ------------------------------------------------------
         * 1. If MAIL_FORCE_LOG=true in .env and APP_ENV=local → always use log
         * 2. Else, if DB value email_driver is set → use DB
         * 3. Else, fallback to MAIL_MAILER from .env (default: log)
         */
        $forceLog = (bool) env('MAIL_FORCE_LOG', false);
        if ($forceLog && app()->environment('local')) {
            $driver = 'log';
        } else {
            $driverFromDb = setting_item('email_driver', null);
            $driver = $driverFromDb ?: env('MAIL_MAILER', 'log');
        }

        // Apply both new-style + legacy keys
        Config::set('mail.default', $driver);
        Config::set('mail.driver', $driver);

        // ------------------------------------------------------
        // SMTP credentials (only needed if driver = smtp)
        // ------------------------------------------------------
        if ($driver === 'smtp') {
            $smtp = [
                'transport'  => 'smtp',
                'host'       => setting_item('email_host', '127.0.0.1'),
                'port'       => (int) setting_item('email_port', 1025),
                'encryption' => setting_item('email_encryption', null), // tls / ssl / null
                'username'   => setting_item('email_username', null),
                'password'   => setting_item('email_password', null),
                'timeout'    => null,
                'auth_mode'  => null,
            ];

            Config::set('mail.mailers.smtp', $smtp);

            // Legacy keys (some libs still read these)
            Config::set('mail.host',       $smtp['host']);
            Config::set('mail.port',       $smtp['port']);
            Config::set('mail.encryption', $smtp['encryption']);
            Config::set('mail.username',   $smtp['username']);
            Config::set('mail.password',   $smtp['password']);
        }

        // ------------------------------------------------------
        // Provider-specific services
        // ------------------------------------------------------
        switch ($driver) {
            case 'mailgun':
                if ($v = setting_item('email_mailgun_domain')) {
                    Config::set('services.mailgun.domain', $v);
                }
                if ($v = setting_item('email_mailgun_secret')) {
                    Config::set('services.mailgun.secret', $v);
                }
                if ($v = setting_item('email_mailgun_endpoint')) {
                    Config::set('services.mailgun.endpoint', $v);
                }
                break;

            case 'ses':
                if ($v = setting_item('email_ses_key')) {
                    Config::set('services.ses.key', $v);
                }
                if ($v = setting_item('email_ses_secret')) {
                    Config::set('services.ses.secret', $v);
                }
                if ($v = setting_item('email_ses_region')) {
                    Config::set('services.ses.region', $v);
                }
                break;

            case 'postmark':
                if ($v = setting_item('email_postmark_token')) {
                    Config::set('services.postmark.token', $v);
                }
                break;

            case 'sendmail':
                Config::set('mail.mailers.sendmail', [
                    'transport' => 'sendmail',
                    'path' => setting_item('email_sendmail_path', '/usr/sbin/sendmail -bs'),
                ]);
                break;

            case 'log':
                Config::set('mail.mailers.log', ['transport' => 'log']);
                break;

            case 'array':
                Config::set('mail.mailers.array', ['transport' => 'array']);
                break;

            case 'failover':
                // Example: smtp + log
                Config::set('mail.mailers.failover', [
                    'transport' => 'failover',
                    'mailers'   => ['smtp', 'log'],
                ]);
                break;
        }
    }
}