<?php

use App\Http\Controllers\AuthPortalController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AuthPortalController::class, 'home'])->name('portal.home');
Route::get('/login', [AuthPortalController::class, 'login'])->name('portal.login');
Route::post('/login', [AuthPortalController::class, 'storeLogin'])->name('portal.login.store');
Route::get('/register', [AuthPortalController::class, 'register'])->name('portal.register');
Route::post('/register', [AuthPortalController::class, 'storeRegistration'])->name('portal.register.store');
Route::get('/register/confirm', [AuthPortalController::class, 'confirmRegistration'])->name('portal.register.confirm');
Route::post('/register/confirm', [AuthPortalController::class, 'storeRegistrationConfirmation'])->name('portal.register.confirm.store');
Route::post('/register/resend', [AuthPortalController::class, 'resendRegistrationConfirmation'])->name('portal.register.resend');
Route::get('/forgot-password', [AuthPortalController::class, 'forgotPassword'])->name('portal.password.forgot');
Route::post('/forgot-password', [AuthPortalController::class, 'storeForgotPassword'])->name('portal.password.forgot.store');
Route::get('/reset-password', [AuthPortalController::class, 'resetPassword'])->name('portal.password.reset');
Route::post('/reset-password', [AuthPortalController::class, 'storeResetPassword'])->name('portal.password.reset.store');
Route::get('/logout', [AuthPortalController::class, 'logout'])->name('portal.logout');
