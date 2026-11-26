<?php

use Illuminate\Support\Facades\Route;

// The backoffice lives in the Filament panel.
Route::redirect('/', '/admin');
