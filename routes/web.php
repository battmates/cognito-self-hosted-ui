<?php

use App\Http\Controllers\AdminUsersController;
use App\Http\Controllers\AuthPortalController;
use App\Http\Controllers\EmailTrackingController;
use App\Http\Controllers\OAuthController;
use App\Http\Controllers\SesWebhookController;
use App\Http\Middleware\PortalSession;
use App\Http\Middleware\RequirePortalAdmin;
use Illuminate\Support\Facades\Route;

Route::post('/oauth2/token', [OAuthController::class, 'token'])->middleware('throttle:60,1');
Route::post('/webhooks/ses-events', [SesWebhookController::class, 'handle'])->middleware('throttle:120,1')->name('portal.webhooks.ses-events');

Route::middleware(PortalSession::class)->group(function () {

    Route::get('/', [AuthPortalController::class, 'home'])->name('portal.home');
    Route::get('/login', [AuthPortalController::class, 'login'])->name('portal.login');
    Route::post('/login', [AuthPortalController::class, 'storeLogin'])->middleware('throttle:10,1')->name('portal.login.store');
    Route::get('/login/{provider}', [AuthPortalController::class, 'redirectToSocialProvider'])->name('portal.login.provider');
    Route::get('/auth/callback', [AuthPortalController::class, 'handleSocialCallback'])->name('portal.login.provider.callback');
    Route::get('/register', [AuthPortalController::class, 'register'])->name('portal.register');
    Route::post('/register', [AuthPortalController::class, 'storeRegistration'])->middleware('throttle:6,1')->name('portal.register.store');
    Route::get('/privacy-policy', [AuthPortalController::class, 'privacyPolicy'])->name('portal.policy.privacy');
    Route::get('/terms-and-conditions', [AuthPortalController::class, 'termsAndConditions'])->name('portal.policy.terms');
    Route::get('/register/confirm', [AuthPortalController::class, 'confirmRegistration'])->name('portal.register.confirm');
    Route::post('/register/confirm', [AuthPortalController::class, 'storeRegistrationConfirmation'])->middleware('throttle:6,1')->name('portal.register.confirm.store');
    Route::post('/register/resend', [AuthPortalController::class, 'resendRegistrationConfirmation'])->middleware('throttle:6,1')->name('portal.register.resend');
    Route::get('/forgot-password', [AuthPortalController::class, 'forgotPassword'])->name('portal.password.forgot');
    Route::post('/forgot-password', [AuthPortalController::class, 'storeForgotPassword'])->middleware('throttle:6,1')->name('portal.password.forgot.store');
    Route::get('/reset-password', [AuthPortalController::class, 'resetPassword'])->name('portal.password.reset');
    Route::post('/reset-password', [AuthPortalController::class, 'storeResetPassword'])->middleware('throttle:6,1')->name('portal.password.reset.store');
    Route::get('/logout', [AuthPortalController::class, 'logout'])->name('portal.logout');

    Route::get('/oauth2/authorize', [AuthPortalController::class, 'login']);
    Route::get('/callback', [AuthPortalController::class, 'handleSocialCallback']);
    Route::get('/challenge', [AuthPortalController::class, 'challenge'])->name('portal.challenge');
    Route::post('/challenge', [AuthPortalController::class, 'storeChallenge'])->middleware('throttle:6,1')->name('portal.challenge.store');
    Route::post('/logout', [AuthPortalController::class, 'logout']);
});

Route::middleware([PortalSession::class, RequirePortalAdmin::class])->group(function () {
    Route::get('/admin/users', [AdminUsersController::class, 'index'])->middleware('throttle:120,1')->name('portal.admin.users');
    Route::post('/admin/users', [AdminUsersController::class, 'update'])->middleware('throttle:10,1')->name('portal.admin.users.update');
    Route::post('/admin/users/create', [AdminUsersController::class, 'create'])->middleware('throttle:10,1')->name('portal.admin.users.create');
    Route::post('/admin/users/attributes', [AdminUsersController::class, 'attributes'])->middleware('throttle:10,1')->name('portal.admin.users.attributes');
    Route::get('/admin/email-tracking', [EmailTrackingController::class, 'index'])->middleware('throttle:30,1')->name('portal.admin.email-tracking');
    Route::get('/admin/email-events', [EmailTrackingController::class, 'events'])->middleware('throttle:60,1')->name('portal.admin.email-events');
});
