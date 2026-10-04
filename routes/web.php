<?php

use App\Http\Controllers\CenterController;
use App\Http\Controllers\SwitchLocationController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', 'dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::post('location', SwitchLocationController::class)->name('location.switch');

    Route::get('company', CenterController::class)->defaults('center', 'company')->name('company.home');
    Route::get('marketing', CenterController::class)->defaults('center', 'marketing')->name('marketing.home');
    Route::get('sales', CenterController::class)->defaults('center', 'sales')->name('sales.home');
    Route::get('production', CenterController::class)->defaults('center', 'production')->name('production.home');
    Route::get('finance', CenterController::class)->defaults('center', 'finance')->name('finance.home');
    Route::get('color-studio', CenterController::class)->defaults('center', 'color_studio')->name('color_studio.home');
    Route::get('settings', CenterController::class)->defaults('center', 'settings')->name('settings.home');
});

require __DIR__.'/settings.php';
