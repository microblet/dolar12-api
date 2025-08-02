<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DolarController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make sure to create a great API
| documentation!
|
*/

Route::middleware('api.key')->group(function () {
    // Rutas de cotizaciones del dólar
    Route::prefix('dolar')->group(function () {
        Route::get('/', [DolarController::class, 'index'])->name('dolar.index');
        Route::get('/fresh', [DolarController::class, 'fresh'])->name('dolar.fresh');
        Route::get('/{tipo}', [DolarController::class, 'show'])->name('dolar.show');
    });
    
    // Ruta de salud de la API
    Route::get('/health', [DolarController::class, 'health'])->name('api.health');
});

// Ruta de prueba sin autenticación (opcional)
Route::get('/test', function () {
    return response()->json([
        'success' => true,
        'message' => 'API funcionando correctamente',
        'timestamp' => now()->format('Y-m-d H:i:s')
    ]);
}); 