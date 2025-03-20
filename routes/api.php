<?php

use App\Jobs\PythonServiceJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


// Route::get('/servicio-1', function (Request $request) {

//     PythonServiceJob::dispatch('type1', 'https://mi-url.com/image.jpg');
//     return response()->json(['message' => 'servicio 1 solicitado']);
//     // return $request->user();
// });

// Route::get('/servicio-2', function (Request $request) {

//     PythonServiceJob::dispatch('type2', 'https://mi-url.com/image.jpg');
//     return response()->json(['message' => 'servicio 2 solicitado']);
//     // return $request->user();
// });
