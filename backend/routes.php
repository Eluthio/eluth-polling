<?php
use Illuminate\Support\Facades\Route;

require_once $pluginPath . '/controller.php';

Route::prefix('/api/plugins/polls')->middleware(['api', 'auth.central'])->group(function () {
    Route::get('/',            [PollsController::class, 'active']);
    Route::get('/active',      [PollsController::class, 'active']);
    Route::post('/',           [PollsController::class, 'create'])->middleware('throttle:5,1');
    Route::post('/{id}/vote',  [PollsController::class, 'vote']);
    Route::post('/{id}/close', [PollsController::class, 'close']);
});
