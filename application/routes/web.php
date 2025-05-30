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

$router->group(['prefix' => 'api/'], function ($app) {
    $app->get('login/','UsersController@authenticate');
    $app->post('space/','SpaceController@store');
    $app->get('space/', 'SpaceController@index');
    $app->get('space/{id}/', 'SpaceController@show');
    $app->put('space/{id}/', 'SpaceController@update');
    $app->delete('space/{id}/', 'SpaceController@destroy');

    $app->post('register/','UsersController@userRegister');
 });

 $router->get('space/', 'SpaceController@index');

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
