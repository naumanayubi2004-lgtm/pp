<?php

use App\Http\Controllers\Api\UKVIApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| These back the client-side React eVisa wizard. They are registered with
| the "web" middleware group (see bootstrap/app.php) so the multi-step flow
| keeps working over the existing session cookie, and CSRF is enforced on
| the POST endpoints via the X-CSRF-TOKEN header sent by the SPA.
|
*/

Route::prefix('ukvi')->name('api.ukvi.')->group(function () {
    // Wizard steps
    Route::post('/document', [UKVIApiController::class, 'storeDocument'])->name('document');
    Route::post('/date-of-birth', [UKVIApiController::class, 'dateOfBirth'])->name('date-of-birth');

    Route::get('/security-code', [UKVIApiController::class, 'securityCodeOptions'])->name('security-code.options');
    Route::post('/security-code', [UKVIApiController::class, 'sendSecurityCode'])->name('security-code.send');

    Route::post('/verify-otp', [UKVIApiController::class, 'verifyOtp'])->name('verify-otp');
    Route::post('/resend-otp', [UKVIApiController::class, 'resendOtp'])->name('resend-otp');

    // eVisa status + assets
    Route::get('/evisa', [UKVIApiController::class, 'evisa'])->name('evisa');
    Route::get('/evisa/photo', [UKVIApiController::class, 'evisaPhoto'])->name('evisa.photo');
    Route::get('/evisa/share-code', [UKVIApiController::class, 'downloadEvisaPdf'])->name('evisa.share-code');
});
