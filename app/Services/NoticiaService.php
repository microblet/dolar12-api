<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\RequestException;
use Carbon\Carbon;

class NoticiaService
{
    private $cacheKey = 'noticias_ambito';
    private $cacheMinutes = 2; // Cache por 2 minutos
    
    private $httpTimeout = 10; // Timeout en segundos
    private $httpRetries = 3; // Número de reintentos
    private $httpRetryDelay = 1000; // Delay entre reintentos en ms

    /**
     * Obtener noticias (con cache)
     */
    public function obtenerNoticias()
    {
        return Cache::remember($this->cacheKey, now()->addMinutes($this->cacheMinutes), function () {
            return $this->consumirRSSAmbito();
        });
    }



    /**
     * Consumir RSS de Ámbito Economía
     */
    private function consumirRSSAmbito()
    {
        $url = 'https://www.ambito.com/rss/pages/economia.xml';
            
        try {
            // Obtener el XML usando Laravel HTTP Client
            $response = Http::timeout($this->httpTimeout)
                ->withHeaders($this->getBrowserHeaders())
                ->withUserAgent($this->getRandomUserAgent())
                ->withOptions([
                    'verify' => false, // Para sitios con SSL problemático
                    'allow_redirects' => ['max' => 5],
                    'decode_content' => true, // Manejo automático de encoding
                ])
                ->retry($this->httpRetries, $this->httpRetryDelay)
                ->get($url);

            if ($response->failed()) {
                throw new Exception("Error HTTP: {$response->status()} - {$response->reason()}");
            }

            $xml = $response->body();
            
            // Crear DOMDocument para parsear el XML
            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadXML($xml);
            libxml_clear_errors();
            
            $xpath = new \DOMXPath($dom);
            
            Log::info("Iniciando consumo de RSS Ámbito Economía");
            
            $noticias = [];
            
            // Buscar todos los items del RSS
            $items = $xpath->query('//item');
            
            Log::info("Items encontrados en RSS: " . $items->length);
            
            $contador = 0;
            foreach ($items as $item) {
                if ($contador >= 5) break; // Solo los primeros 5
                
                // Extraer título
                $tituloNode = $xpath->query('.//title', $item)->item(0);
                $titulo = $tituloNode ? trim(strip_tags($tituloNode->textContent)) : '';
                
                // Extraer fecha de publicación
                $fechaNode = $xpath->query('.//pubDate', $item)->item(0);
                $fechaOriginal = $fechaNode ? trim($fechaNode->textContent) : '';
                
                // Convertir fecha a formato GMT-3 (Buenos Aires)
                $fechaFormateada = '';
                if ($fechaOriginal) {
                    try {
                        // Parsear la fecha del RSS (formato RFC 2822)
                        $fecha = Carbon::createFromFormat('D, d M Y H:i:s O', $fechaOriginal);
                        // Convertir a zona horaria de Buenos Aires (GMT-3)
                        $fecha->setTimezone('America/Argentina/Buenos_Aires');
                        // Formatear como string legible
                        $fechaFormateada = $fecha->format('Y-m-d H:i:s T');
                    } catch (Exception $e) {
                        Log::warning("Error parseando fecha: $fechaOriginal - {$e->getMessage()}");
                        $fechaFormateada = $fechaOriginal; // Usar fecha original si falla el parseo
                    }
                }
                
                if ($titulo && $fechaFormateada) {
                    $noticias[] = [
                        'titulo' => $titulo,
                        'fecha' => $fechaFormateada
                    ];
                    $contador++;
                    
                    Log::info("Noticia $contador procesada: $titulo");
                }
            }
            
            Log::info('Consumo de RSS completado', [
                'noticias_procesadas' => count($noticias),
                'url' => $url,
                'status_code' => $response->status()
            ]);
            
            return [
                'noticias' => $noticias,
                'fuente' => 'ambito.com',
                'total_procesadas' => count($noticias),
                'timestamp' => now()->format('Y-m-d H:i:s')
            ];
            
        } catch (RequestException $e) {
            Log::error('Error HTTP en consumo RSS: ' . $e->getMessage(), [
                'url' => $url,
                'response_status' => $e->response ? $e->response->status() : 'No response',
                'response_body' => $e->response ? substr($e->response->body(), 0, 500) : 'No body'
            ]);
            
            return [
                'noticias' => [],
                'fuente' => 'ambito.com',
                'total_procesadas' => 0,
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'error' => "Error HTTP: {$e->getMessage()}"
            ];
            
        } catch (Exception $e) {
            Log::error('Error general en consumo RSS: ' . $e->getMessage(), [
                'url' => $url,
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'noticias' => [],
                'fuente' => 'ambito.com',
                'total_procesadas' => 0,
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Obtener headers que simulan un navegador real
     */
    private function getBrowserHeaders(): array
    {
        return [
            'Accept' => 'application/rss+xml, application/xml, text/xml, */*',
            'Accept-Language' => 'es-ES,es;q=0.8,en-US;q=0.5,en;q=0.3',
            'Connection' => 'keep-alive',
            'Upgrade-Insecure-Requests' => '1',
            'Cache-Control' => 'max-age=0',
        ];
    }

    /**
     * Obtener un User-Agent aleatorio para evitar detección
     */
    private function getRandomUserAgent(): string
    {
        $userAgents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:122.0) Gecko/20100101 Firefox/122.0',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2.1 Safari/605.1.15'
        ];

        return $userAgents[array_rand($userAgents)];
    }
}