<?php

use App\Jobs\PythonServiceV2Job;
use App\Services\PythonServiceQueueMonitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('python/', function (Request $request) {
    $data = $request->all();

    PythonServiceV2Job::dispatch($data['service'], $data['photo_url'], $data['token'])
        ->onQueue('python');

    $statusQueue = PythonServiceQueueMonitor::getQueueStatus();

    return response()->json($statusQueue);
});
