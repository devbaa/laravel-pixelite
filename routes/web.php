<?php

use Boralp\Pixelite\Controllers\VisitController;

Route::middleware(['web'])->post('/pixelite/{pixeliteTraceId}/update', [VisitController::class, 'update'])->name('pixelite.visit.update');
