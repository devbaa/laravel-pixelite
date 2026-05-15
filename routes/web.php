<?php

declare(strict_types=1);

use Boralp\Pixelite\Controllers\VisitController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'throttle:30,1'])
    ->post('/pixelite/{pixeliteTraceId}/update', [VisitController::class, 'update'])
    ->name('pixelite.visit.update');
