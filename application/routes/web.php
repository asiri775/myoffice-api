<?php


/** @var \Laravel\Lumen\Routing\Router $router */

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/

$router->get('/', function () use ($router) {
    return $router->app->version();
});


$router->get('/mail-test', function () {
    \Illuminate\Support\Facades\Mail::to('you@example.com')
        ->send(new \App\Mail\DbTemplateMailable('Test Email', '<p>Hello from Lumen!</p>'));

    return 'Mail triggered';
});

$router->get('/_mail-config', function () {
    return response()->json([
        'default' => config('mail.default'),
        'driver'  => config('mail.driver'),              // legacy key
        'smtp'    => config('mail.mailers.smtp') ?? null,
        'log'     => config('mail.mailers.log') ?? null,
        'from'    => config('mail.from'),
    ]);
});


$router->group(['prefix' => 'api/'], function ($app) {


    $app->get('space/', 'SpaceController@index');
    $app->get('space/{id}/', 'SpaceController@show');



    $app->post('login/','UsersController@authenticate');
    $app->post('register/','UsersController@userRegister');


    $app->get('oauth/{provider}/redirect', 'SocialAuthController@redirect');
    $app->get('oauth/{provider}/callback', 'SocialAuthController@callback');

    // protected
    $app->group(['middleware' => 'auth'], function($app) {
        $app->post('space/','SpaceController@store');
        $app->put('space/{id}/', 'SpaceController@update');
        $app->delete('space/{id}/', 'SpaceController@destroy');
    });



 });

//  $router->get('space/', 'SpaceController@index');

$router->get('/run-cmd', function () {

    /*Artisan::call('migrate');
    Artisan::call('make:auth');
    Artisan::call('make:controller', ['name' => 'DemoController']);
    dd(Artisan::output());*/
    exec('composer dump-autoload');
	exec('php artisan config:clear');
	exec('php artisan cache:clear');
	exec('composer self-update');
    //Artisan::call('config:clear');
  //  Artisan::call('cache:clear');
    echo('success');
});