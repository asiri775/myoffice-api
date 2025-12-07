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



    $app->post('bookings/verify-times','AvailabilityController@verifySelectedTimes');
    $app->post('bookings/available-dates','AvailabilityController@availableDates');

    $app->post('bookings/add-to-cart','BookingController@addToCart');

    $app->post('bookings/checkout-init','BookingController@checkoutInit');

    $app->post('bookings/coupon','CouponController@apply');

    $app->get('oauth/{provider}/redirect', 'SocialAuthController@redirect');
    $app->get('oauth/{provider}/callback', 'SocialAuthController@callback');



    $app->get('twoco/return', ['as' => 'twoco.return', 'uses' => 'TwoCheckoutController@return']);
    $app->get('twoco/cancel', ['as' => 'twoco.cancel', 'uses' => 'TwoCheckoutController@cancel']);

    // protected
    $app->group(['middleware' => 'auth'], function($app) {
        $app->post('logout/','UsersController@logout');
        $app->post('space/add-remove-favourite','SpaceController@addRemoveFavourite');
        $app->post('space/','SpaceController@store');
        $app->put('space/{id}/', 'SpaceController@update');
        $app->delete('space/{id}/', 'SpaceController@destroy');
        $app->post('space/{space_id}/reviews', 'SpaceController@submitReview');

        $app->post('bookings/search','BookingSearchController@search');

        $app->post('bookings/checkout','BookingController@checkout');



        $app->post('bookings/reschedule','BookingController@reschedule');
        $app->post('bookings/invoice','BookingController@invoice');
        $app->post('bookings/contact-host','BookingController@contactHost');
        $app->post('bookings/cancel','BookingController@cancelBooking');
        $app->post('bookings/in/verify-times','BookingController@verifySelectedTimes');
        $app->post('bookings/status-change','BookingController@statusChange');
        $app->get('bookings/{booking_id}','BookingController@show');

        // User notification settings
        $app->get('user/notification-settings', 'UsersController@getNotificationSettings');
        $app->put('user/notification-settings', 'UsersController@updateNotificationSettings');

        // User profile
        $app->get('user/profile', 'UsersController@getProfile');
        $app->put('user/profile', 'UsersController@updateProfile');

        // User social media links
        $app->get('user/social-links', 'UsersController@getSocialLinks');
        $app->put('user/social-links', 'UsersController@updateSocialLinks');

        // User favourites
        $app->get('user/favourites', 'UsersController@getFavourites');
        $app->post('user/favourites/toggle', 'UsersController@toggleFavourite');

        // User reviews
        $app->get('user/reviews', 'UsersController@getReviews');

        // User support
        $app->post('user/support', 'UsersController@submitSupport');

        // User bookings
        $app->get('user/bookings', 'UsersController@getBookings');

        // User payment methods
        $app->get('user/payment-methods', 'UsersController@getPaymentMethods');
        $app->post('user/payment-methods', 'UsersController@savePaymentMethod');
        $app->put('user/payment-methods/{id}/set-default', 'UsersController@setDefaultPaymentMethod');



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