<?php

use App\Jobs\PythonServiceV2Job;
use App\Services\PythonServiceQueueMonitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
