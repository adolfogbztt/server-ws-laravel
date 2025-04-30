<?php

use App\Http\Middleware\EnsureTokenIsValid;
use App\Http\Middleware\LocalNetworkOnly;
use App\Jobs\PythonServiceV2Job;
use App\Services\PythonServiceQueueMonitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware(EnsureTokenIsValid::class)->group(function () {
    Route::post('/handle-message', function (Request $request) {
        $data = json_decode($request->getContent());

        $jobs = DB::table('jobs')->where('queue', 'python')->count();

        $jobs2 = DB::table('jobs')->where('queue', 'python2')->count();
        $selectedQueue = 'python';

        if ($jobs > $jobs2) {
            $selectedQueue = 'python2';
        }


        PythonServiceV2Job::dispatch(
            $data->service,
            $data->photo_url,
            @$data->bgColor ?? 'transparent',
            $data->channel,
            $data->canvasIndex,
            $data->elementIndex
        )
            ->onQueue($selectedQueue);

        $statusQueue = PythonServiceQueueMonitor::getQueueStatus($selectedQueue);

        return response()->json($statusQueue);
    });
});

Route::middleware(LocalNetworkOnly::class)->group(function () {
    Route::get('/local/handle-message', function (Request $request) {
        dd($request);
    });
});