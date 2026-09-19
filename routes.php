<?php

Route::prefix('authserver')
    ->middleware(['Yggdrasil\Middleware\CheckContentType'])
    ->group(function () {
        // Prevent password brute-forcing
        Route::middleware(['Yggdrasil\Middleware\Throttle'])
            ->group(function () {
                Route::post('authenticate', 'AuthController@authenticate');
                Route::post('signout', 'AuthController@signout');
            });

        Route::post('refresh', 'AuthController@refresh');

        Route::post('validate', 'AuthController@validate');
        Route::post('invalidate', 'AuthController@invalidate');
});

Route::prefix('sessionserver/session/minecraft')->group(function () {
    Route::post('join', 'SessionController@joinServer');
    Route::get('hasJoined', 'SessionController@hasJoinedServer');

    Route::get('profile/{uuid}', 'ProfileController@getProfileFromUuid');
});

Route::post('api/profiles/minecraft', 'ProfileController@searchProfile');

// MUA union authentication multi-backend re-signing endpoint
// hello needs no verification and is just a health check; restore blindly signs whatever is
// submitted, so it must be restricted to the central server only.
Route::get('restore', 'MultiBackendController@hello');
Route::post('restore', 'MultiBackendController@restore')
    ->middleware(['Yggdrasil\Middleware\UnionHostVerify']);
