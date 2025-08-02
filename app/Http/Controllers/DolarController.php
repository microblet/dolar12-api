<?php

namespace App\Http\Controllers;

use App\Services\DolarScrapingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class DolarController extends Controller
{
    private $dolarService;

    public function __construct(DolarScrapingService $dolarService)
    {
        $this->dolarService = $dolarService;
    }

    /**
     * Obtener cotizaciones del dólar (con cache)
     */
    public function index(): JsonResponse
    {
        try {
            $data = $this->dolarService->obtenerCotizaciones();
            
            return response()->json([
                'success' => true,
                'data' => $data
            ], 200);
            
        } catch (\Exception $e) {
            Log::error('Error en endpoint de cotizaciones: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Obtener cotizaciones frescas del dólar (sin cache)
     */
    public function fresh(): JsonResponse
    {
        try {
            $data = $this->dolarService->obtenerCotizacionesFrescas();
            
            return response()->json([
                'success' => true,
                'data' => $data
            ], 200);
            
        } catch (\Exception $e) {
            Log::error('Error en endpoint de cotizaciones frescas: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Obtener cotización específica del dólar
     */
    public function show(string $tipo): JsonResponse
    {
        try {
            $data = $this->dolarService->obtenerCotizaciones();
            
            $tiposValidos = ['oficial', 'blue', 'mep', 'ccl', 'cripto', 'tarjeta', 'freelance'];
            
            if (!in_array($tipo, $tiposValidos)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tipo de cotización no válido',
                    'error' => "El tipo '$tipo' no es válido. Tipos válidos: " . implode(', ', $tiposValidos)
                ], 422);
            }
            
            $cotizacion = $data['cotizaciones'][$tipo] ?? null;
            
            if (!$cotizacion) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cotización no encontrada',
                    'error' => "No se pudo obtener la cotización para el tipo '$tipo'"
                ], 422);
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'tipo' => $tipo,
                    'cotizacion' => $cotizacion,
                    'fuente' => $data['fuente'],
                    'timestamp' => $data['timestamp']
                ]
            ], 200);
            
        } catch (\Exception $e) {
            Log::error('Error en endpoint de cotización específica: ' . $e->getMessage(), [
                'tipo' => $tipo,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Información de salud de la API
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'status' => 'healthy',
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'version' => '1.0.0',
                'description' => 'API de cotizaciones del dólar argentino'
            ]
        ], 200);
    }
} 