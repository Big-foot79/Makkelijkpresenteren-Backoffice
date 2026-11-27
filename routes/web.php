<?php

use App\Http\Controllers\WooWebhookController;
use Illuminate\Support\Facades\Route;

// The backoffice lives in the Filament panel.
Route::redirect('/', '/admin');

// WooCommerce webhooks (signed; CSRF does not apply, see bootstrap/app.php).
Route::post('/webhooks/woocommerce', WooWebhookController::class)
    ->middleware('throttle:600,1')
    ->name('webhooks.woocommerce');
