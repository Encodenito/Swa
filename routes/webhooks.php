<?php

use App\Http\Controllers\Webhooks\PayMongoWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Machine-to-machine endpoints. Loaded outside the `web` group (bootstrap/app.php), so there is
| no session, cookie, CSRF or Inertia middleware. PayMongo requests are authenticated by their
| HMAC signature in the controller.
*/

Route::post('webhooks/paymongo', PayMongoWebhookController::class)
    ->middleware('throttle:paymongo-webhook')
    ->name('webhooks.paymongo');
