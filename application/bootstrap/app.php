<?php


require_once __DIR__.'/../vendor/autoload.php';


(new Laravel\Lumen\Bootstrap\LoadEnvironmentVariables(dirname(__DIR__)))->bootstrap();


require_once __DIR__.'/../app/Helpers/helpers.php';


date_default_timezone_set(env('APP_TIMEZONE', 'UTC'));


$app = new Laravel\Lumen\Application(dirname(__DIR__));


$app->withFacades();
$app->withEloquent();


$app->singleton(Illuminate\Contracts\Debug\ExceptionHandler::class, App\Exceptions\Handler::class);
$app->singleton(Illuminate\Contracts\Console\Kernel::class, App\Console\Kernel::class);


$app->configure('app');
$app->configure('mail');
$app->configure('services');


$app->routeMiddleware([
    'auth' => App\Http\Middleware\Authenticate::class,
]);


$app->register(Illuminate\Mail\MailServiceProvider::class);
$app->alias('mailer', Illuminate\Contracts\Mail\Mailer::class);
$app->alias('mailer', Illuminate\Mail\Mailer::class);


$app->register(App\Providers\AppServiceProvider::class);
$app->register(App\Providers\AuthServiceProvider::class);

$app->register(App\Providers\SocialAuthServiceProvider::class);
$app->register(Laravel\Socialite\SocialiteServiceProvider::class);
class_alias(Laravel\Socialite\Facades\Socialite::class, 'Socialite');


$app->routeMiddleware([
    'auth'   => App\Http\Middleware\Authenticate::class,
    'signed' => App\Http\Middleware\VerifyEmailSignature::class,
]);


$app->middleware([
    App\Http\Middleware\AttachTraceId::class,
]);


$app->router->group([
    'namespace' => 'App\Http\Controllers',
], function ($router) {
    require __DIR__.'/../routes/web.php';
});

return $app;