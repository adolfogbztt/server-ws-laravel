<?php

use App\Http\Middleware\EnsureTokenIsValid;
use App\Http\Middleware\LocalNetworkOnly;
use App\Jobs\PythonLocalServiceJob;
use App\Jobs\PythonServiceV2Job;
use App\Services\PythonLocalService;
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

// // Route::middleware(LocalNetworkOnly::class)->group(function () {
// Route::post('/local/handle-message', function (Request $request) {
//     // $data = json_decode($request->getContent());
//     // $service = $request->input('service');

//     // $photoBase64 = base64_encode($request->file('photo'));

//     // $pythonLocalService = new PythonLocalService(
//     //     $service,
//     //     $photoBase64
//     // );

//     // $result = $pythonLocalService->handle();

//     $path = $request->file('photo')->store('images', 'public');
//     $base64Photo = getImageBase64($path);
//     $service = $request->input('service');

//     $pythonLocalService = new PythonLocalService(
//         $service,
//         $base64Photo
//     );

//     return response()->json($pythonLocalService);
// });
// // });

// function getImageBase64($path): string
// {
//     // Obtiene el contenido binario de la imagen
//     $fileContent = Storage::disk('public')->get($path);
//     // Obtiene el tipo MIME del archivo
//     $mime = Storage::disk('public')->mimeType($path);
//     // Codifica en base64
//     $base64 = base64_encode($fileContent);
//     // Devuelve como string base64 con prefijo de datos
//     return "data:$mime;base64,$base64";
// }
