<?php

use App\Events\MessageSent;
use Illuminate\Support\Facades\Broadcast;

// Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
//     return (int) $user->id === (int) $id;
// });


// Nuevo canal público para pruebas
Broadcast::channel('test-channel', function ($user) {
    // \Log::info('Nuevo usuario conectado al canal test-channel');
    return true; // Permite que cualquier usuario se conecte
});



Broadcast::channel('lobby', function () {
    return true; // Permite a cualquier usuario conectarse sin autenticación
});


Broadcast::channel('responses.{token}', function ($user, $token) {
    return true; // Asegura que el usuario solo escuche su propio canal
});