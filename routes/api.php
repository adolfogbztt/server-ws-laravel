<?php

use App\Http\Middleware\EnsureTokenIsValid;
use App\Jobs\PythonServiceV2Job;
use App\Services\PythonServiceQueueMonitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware(EnsureTokenIsValid::class)->group(function () {
    Route::post('/handle-message', function (Request $request) {
        $data = json_decode($request->getContent());

        PythonServiceV2Job::dispatch(
            $data->service,
            $data->photo_url,
            @$data->bgColor ?? 'transparent',
            $data->channel,
            $data->canvasIndex,
            $data->elementIndex
        )
            ->onQueue('python');
        $statusQueue = PythonServiceQueueMonitor::getQueueStatus();

        return response()->json($statusQueue);
    });
});
