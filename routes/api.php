<?php

declare(strict_types=1);

use App\Http\Controllers\AttendanceApiController;
use Illuminate\Support\Facades\Route;

Route::middleware('verify.api.token')->group(function () {
    Route::get('attendance/calculate', [AttendanceApiController::class, 'calculate']);
});