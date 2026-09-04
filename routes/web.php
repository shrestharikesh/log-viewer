<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vendor\LogExplorer\Http\Controllers\LogDownloadController;
use Vendor\LogExplorer\Http\Controllers\LogFileController;
use Vendor\LogExplorer\Http\Controllers\LogSearchController;
use Vendor\LogExplorer\Http\Controllers\LogStreamController;
use Vendor\LogExplorer\Http\Controllers\LogTailController;
use Vendor\LogExplorer\Http\Controllers\LogViewController;
use Vendor\LogExplorer\Http\Controllers\LogViewerController;

/*
|--------------------------------------------------------------------------
| Log Explorer Routes
|--------------------------------------------------------------------------
|
| Wrapped by the service provider in a group that applies the configured
| prefix, name prefix ("as"), and middleware (incl. the package Authorize
| middleware). The JSON API lives under "/api"; the UI is the group root.
|
*/

// Standalone UI (skip if you only embed the Blade component).
Route::get('/', LogViewerController::class)->name('index');

Route::prefix('api')->name('api.')->group(function (): void {
    Route::get('files', [LogFileController::class, 'index'])->name('files');
    Route::get('files/show', [LogFileController::class, 'show'])->name('files.show');

    Route::get('view', LogViewController::class)->name('view');
    Route::get('tail', LogTailController::class)->name('tail');
    Route::get('search', LogSearchController::class)->name('search');
    Route::get('stream', LogStreamController::class)->name('stream');
    Route::get('download', LogDownloadController::class)->name('download');
});
