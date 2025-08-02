<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class ApiKeyMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $apiKey = trim($request->header('X-API-KEY'));
        $expectedApiKey = config('app.api_key');

        if (!$apiKey) {
            Log::warning('Intento de acceso sin API key', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'url' => $request->fullUrl()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'API key requerida',
                'error' => 'X-API-KEY header no encontrado'
            ], 422);
        }

        if ($apiKey !== $expectedApiKey) {
            Log::warning('Intento de acceso con API key inválida', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'url' => $request->fullUrl(),
                'provided_key' => substr($apiKey, 0, 8) . '...' // Solo log los primeros 8 caracteres por seguridad
            ]);

            return response()->json([
                'success' => false,
                'message' => 'API key inválida',
                'error' => 'La API key proporcionada no es válida'
            ], 422);
        }

        Log::info('Acceso autorizado a la API', [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'url' => $request->fullUrl()
        ]);

        return $next($request);
    }
} 