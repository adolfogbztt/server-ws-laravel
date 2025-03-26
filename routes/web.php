<?php

use App\Jobs\PythonServiceV2Job;
use App\Services\PythonServiceQueueMonitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('python/', function (Request $request) {
    $data = $request->all();

    $job = PythonServiceV2Job::dispatch($data['service'], $data['photo_url'], $data['token'])
        ->onQueue('python');
});

Route::get('python/jobs', function () {
    // $data = DB::table('jobs')
    //     ->where('queue', 'python')
    //     ->get();
    
    // $first = $data->first();
    // $first = json_decode($first->payload, true);

    // dd(unserialize($first->data->command));

    dd(PythonServiceQueueMonitor::getQueueStatus());
});