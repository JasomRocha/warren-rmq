<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BrokerController;
use App\Http\Controllers\EventsController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\PanelController;
use App\Http\Controllers\TopologyController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/login', [LoginController::class, 'create'])->name('login');
Route::post('/login', [LoginController::class, 'store']);

Route::middleware('warren.auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', fn () => Inertia::render('Workspace'))->name('workspace');

    Route::prefix('api')->group(function () {
        // The connections (RabbitMQs) Warren follows, and their credentials.
        Route::get('/brokers', [BrokerController::class, 'index'])->name('api.brokers.index');
        Route::post('/brokers', [BrokerController::class, 'store'])->name('api.brokers.store');
        Route::post('/brokers/test', [BrokerController::class, 'test'])->name('api.brokers.test');
        Route::put('/brokers/{broker}', [BrokerController::class, 'update'])->whereNumber('broker')->name('api.brokers.update');
        Route::delete('/brokers/{broker}', [BrokerController::class, 'destroy'])->whereNumber('broker')->name('api.brokers.destroy');

        // Everything about one connection. Read-only JSON the page polls for live numbers.
        Route::prefix('b/{broker}')->whereNumber('broker')->group(function () {
            Route::get('/topology', TopologyController::class)->name('api.topology');
            Route::get('/events', EventsController::class)->name('api.events');
            Route::get('/metrics', MetricsController::class)->name('api.metrics');
            Route::get('/paineis/{id}', PanelController::class)->name('api.paineis.show');
            Route::get('/messages/{id}', MessageController::class)->whereNumber('id')->name('api.messages.show');
        });
    });
});
