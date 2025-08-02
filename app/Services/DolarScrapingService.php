<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\RequestException;

class DolarScrapingService
{
    private $cacheKey = 'dolar_cotizaciones';
    private $cacheMinutes = 2; // Cache por 2 minutos
    
    private $httpTimeout = 10; // Timeout en segundos
    private $httpRetries = 3; // Número de reintentos
    private $httpRetryDelay = 1000; // Delay entre reintentos en ms

    /**
     * Obtener cotizaciones (con cache)
     */
    public function obtenerCotizaciones()
    {
        return Cache::remember($this->cacheKey, now()->addMinutes($this->cacheMinutes), function () {
            return $this->scrapeDolarHoy();
        });
    }

    /**
     * Obtener cotizaciones frescas (sin cache)
     */
    public function obtenerCotizacionesFrescas()
    {
        Cache::forget($this->cacheKey);
        return $this->obtenerCotizaciones();
    }

    /**
     * Scraping principal de dolarhoy.com
     */
    private function scrapeDolarHoy()
    {
        $url = 'https://dolarhoy.com/';
            
        try {
            // Obtener el HTML usando Laravel HTTP Client
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

            $html = $response->body();
            
            // Crear DOMDocument para parsear el HTML
            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML($html);
            libxml_clear_errors();
            
            $xpath = new \DOMXPath($dom);
            
            Log::info("Iniciando scraping de página AMP DolarHoy");
            
            $rawPrices = [];
            

            
            // ESTRATEGIA 1: Buscar directamente el contenedor tile dolar
            $mainCotizacionesContainer = $xpath->query('//div[contains(@class, "tile dolar")]')->item(0);
            
            if ($mainCotizacionesContainer) {
                Log::info("Contenedor principal de cotizaciones encontrado");
                
                // Buscar todos los tiles individuales dentro del contenedor principal
                $dollarTiles = $xpath->query('.//div[contains(@class, "tile is-child")]', $mainCotizacionesContainer);
                Log::info("Tiles de dólar encontrados en contenedor principal: " . $dollarTiles->length);
                
                foreach ($dollarTiles as $index => $tile) {
                    // Buscar el enlace con la clase titleText para determinar el tipo
                    $linkElement = $xpath->query('.//a[contains(@class, "titleText")]', $tile)->item(0);
                    
                    if ($linkElement) {
                        $href = $linkElement->getAttribute('href');
                        $titleText = trim($linkElement->textContent);
                        
                        Log::info("Tile $index - Enlace encontrado: $href - Texto: $titleText");
                        
                        // Determinar el tipo basado en el href
                        $type = null;
                        if (strpos($href, '/cotizacion-dolar-oficial') !== false || strpos($href, '/cotizaciondolaroficial') !== false) {
                            $type = 'oficial';
                        } elseif (strpos($href, '/cotizacion-dolar-blue') !== false || strpos($href, '/cotizaciondolarblue') !== false) {
                            $type = 'blue';
                        } elseif (strpos($href, '/cotizacion-dolar-mep') !== false || strpos($href, '/cotizaciondolarmep') !== false || strpos($href, '/cotizaciondolarbolsa') !== false) {
                            $type = 'mep';
                        } elseif (strpos($href, '/cotizacion-dolar-ccl') !== false || strpos($href, '/cotizaciondolarccl') !== false || strpos($href, '/cotizaciondolarcontadoconliqui') !== false) {
                            $type = 'ccl';
                        } elseif (strpos($href, '/cotizacion-dolar-cripto') !== false || strpos($href, '/cotizaciondolarcripto') !== false || strpos($href, '/seccion/bitcoins') !== false) {
                            $type = 'cripto';
                        } elseif (strpos($href, '/cotizacion-dolar-tarjeta') !== false || strpos($href, '/cotizaciondolartarjeta') !== false) {
                            $type = 'tarjeta';
                        }
                        
                        if ($type) {
                            Log::info("Tipo identificado: $type");
                            
                            $compra = 0;
                            $venta = 0;
                            
                            // Extraer valor de compra
                            $compraElement = $xpath->query('.//div[contains(@class, "compra")]//div[contains(@class, "val")]', $tile)->item(0);
                            if ($compraElement) {
                                $compraText = trim($compraElement->textContent);
                                if (preg_match('/\$\s*([0-9.,]+)/', $compraText, $matches)) {
                                    $compra = floatval(str_replace(['.', ','], ['', '.'], $matches[1]));
                                    Log::info("$type - Compra encontrada: $compra (texto: $compraText)");
                                }
                            }
                            
                            // Extraer valor de venta
                            $ventaElement = $xpath->query('.//div[contains(@class, "venta")]//div[contains(@class, "val")]', $tile)->item(0);
                            if ($ventaElement) {
                                $ventaText = trim($ventaElement->textContent);
                                if (preg_match('/\$\s*([0-9.,]+)/', $ventaText, $matches)) {
                                    $venta = floatval(str_replace(['.', ','], ['', '.'], $matches[1]));
                                    Log::info("$type - Venta encontrada: $venta (texto: $ventaText)");
                                }
                            }
                            
                            // Para tarjeta, a veces solo hay un valor
                            if ($type === 'tarjeta' && $compra == 0 && $venta == 0) {
                                $valorElement = $xpath->query('.//div[contains(@class, "val")]', $tile)->item(0);
                                if ($valorElement) {
                                    $valorText = trim($valorElement->textContent);
                                    if (preg_match('/\$\s*([0-9.,]+)/', $valorText, $matches)) {
                                        $valor = floatval(str_replace(['.', ','], ['', '.'], $matches[1]));
                                        $rawPrices[$type] = ['valor' => $valor];
                                        Log::info("$type - Valor único encontrado: $valor");
                                    }
                                }
                            } else if ($compra > 0 || $venta > 0) {
                                if ($type === 'tarjeta') {
                                    // Tarjeta solo tiene valor (venta)
                                    $rawPrices[$type] = ['valor' => $venta > 0 ? $venta : $compra];
                                } else {
                                    // Todos los demás tipos tienen compra y venta
                                    $rawPrices[$type] = ['compra' => $compra, 'venta' => $venta];
                                }
                                Log::info("$type - Precios guardados", $rawPrices[$type]);
                            }
                        }
                    }
                }
            }
            
            // ESTRATEGIA 2: Fallback - Buscar en "Más Cotizaciones" si no encontramos nada
            if (empty($rawPrices)) {
                Log::info("No se encontraron precios en contenedor principal, probando 'Más Cotizaciones'");
                
                $moreCotizacionesContainer = $xpath->query('//div[contains(@class, "modulo__more_cotizaciones")]//div[contains(@class, "cotizaciones_more")]')->item(0);
                
                if ($moreCotizacionesContainer) {
                    Log::info("Contenedor 'Más Cotizaciones' encontrado");
                    
                    $moreTiles = $xpath->query('.//div[contains(@class, "tile is-child")]', $moreCotizacionesContainer);
                    Log::info("Tiles en 'Más Cotizaciones': " . $moreTiles->length);
                    
                    foreach ($moreTiles as $index => $tile) {
                        // Misma lógica que arriba para extraer precios
                        $linkElement = $xpath->query('.//a[contains(@class, "titleText")]', $tile)->item(0);
                        
                        if ($linkElement) {
                            $href = $linkElement->getAttribute('href');
                            $titleText = trim($linkElement->textContent);
                            
                            // Determinar tipo y extraer precios de la misma manera
                            $type = null;
                            if (strpos($href, '/cotizacion-dolar-oficial') !== false || strpos($href, '/cotizaciondolaroficial') !== false) {
                                $type = 'oficial';
                            } elseif (strpos($href, '/cotizacion-dolar-blue') !== false || strpos($href, '/cotizaciondolarblue') !== false) {
                                $type = 'blue';
                            } elseif (strpos($href, '/cotizacion-dolar-mep') !== false || strpos($href, '/cotizaciondolarmep') !== false || strpos($href, '/cotizaciondolarbolsa') !== false) {
                                $type = 'mep';
                            } elseif (strpos($href, '/cotizacion-dolar-ccl') !== false || strpos($href, '/cotizaciondolarccl') !== false || strpos($href, '/cotizaciondolarcontadoconliqui') !== false) {
                                $type = 'ccl';
                            } elseif (strpos($href, '/cotizacion-dolar-cripto') !== false || strpos($href, '/cotizaciondolarcripto') !== false || strpos($href, '/seccion/bitcoins') !== false) {
                                $type = 'cripto';
                            } elseif (strpos($href, '/cotizacion-dolar-tarjeta') !== false || strpos($href, '/cotizaciondolartarjeta') !== false) {
                                $type = 'tarjeta';
                            }
                            
                            if ($type && !isset($rawPrices[$type])) {
                                $compra = 0;
                                $venta = 0;
                                
                                $compraElement = $xpath->query('.//div[contains(@class, "compra")]//div[contains(@class, "val")]', $tile)->item(0);
                                if ($compraElement) {
                                    $compraText = trim($compraElement->textContent);
                                    if (preg_match('/\$\s*([0-9.,]+)/', $compraText, $matches)) {
                                        $compra = floatval(str_replace(['.', ','], ['', '.'], $matches[1]));
                                    }
                                }
                                
                                $ventaElement = $xpath->query('.//div[contains(@class, "venta")]//div[contains(@class, "val")]', $tile)->item(0);
                                if ($ventaElement) {
                                    $ventaText = trim($ventaElement->textContent);
                                    if (preg_match('/\$\s*([0-9.,]+)/', $ventaText, $matches)) {
                                        $venta = floatval(str_replace(['.', ','], ['', '.'], $matches[1]));
                                    }
                                }
                                
                                if ($compra > 0 || $venta > 0) {
                                    if ($type === 'tarjeta') {
                                        // Tarjeta solo tiene valor (venta)
                                        $rawPrices[$type] = ['valor' => $venta > 0 ? $venta : $compra];
                                    } else {
                                        // Todos los demás tipos tienen compra y venta
                                        $rawPrices[$type] = ['compra' => $compra, 'venta' => $venta];
                                    }
                                    Log::info("$type - Precios encontrados en 'Más Cotizaciones'", $rawPrices[$type]);
                                }
                            }
                        }
                    }
                }
            }
            
            // Log para debugging
            Log::info('Scraping AMP completado', [
                'raw_prices' => $rawPrices,
                'types_found' => array_keys($rawPrices),
                'scraping_success' => !empty($rawPrices)
            ]);
            
            // Calcular dólar freelance (cripto venta menos 7.5%)
            $criptoVenta = $rawPrices['cripto']['venta'] ?? 0;
            $freelanceValor = $criptoVenta > 0 ? round($criptoVenta * 0.925, 2) : 0;
            
            // Estructurar datos para el dashboard usando rawPrices
            $data = [
                'oficial' => [
                    'compra' => $rawPrices['oficial']['compra'] ?? 0,
                    'venta' => $rawPrices['oficial']['venta'] ?? 0
                ],
                'blue' => [
                    'compra' => $rawPrices['blue']['compra'] ?? 0,
                    'venta' => $rawPrices['blue']['venta'] ?? 0
                ],
                'mep' => [
                    'compra' => $rawPrices['mep']['compra'] ?? 0,
                    'venta' => $rawPrices['mep']['venta'] ?? 0
                ],
                'ccl' => [
                    'compra' => $rawPrices['ccl']['compra'] ?? 0,
                    'venta' => $rawPrices['ccl']['venta'] ?? 0
                ],
                'cripto' => [
                    'compra' => $rawPrices['cripto']['compra'] ?? 0,
                    'venta' => $rawPrices['cripto']['venta'] ?? 0
                ],
                'tarjeta' => [
                    'valor' => $rawPrices['tarjeta']['valor'] ?? 0
                ],
                'freelance' => [
                    'valor' => $freelanceValor
                ]
            ];
            
            $this->logScrapingResult($url, $response, $rawPrices, $data);
            
            return [
                'cotizaciones' => $data,
                'fuente' => 'dolarhoy.com',
                'scraping_success' => !empty($rawPrices),
                'timestamp' => now()->format('Y-m-d H:i:s')
            ];
            
        } catch (RequestException $e) {
            Log::error('Error HTTP en scraping: ' . $e->getMessage(), [
                'url' => $url,
                'response_status' => $e->response ? $e->response->status() : 'No response',
                'response_body' => $e->response ? substr($e->response->body(), 0, 500) : 'No body'
            ]);
            
            return [
                'cotizaciones' => [
                    'oficial' => [
                        'compra' => 0,
                        'venta' => 0
                    ],
                    'blue' => [
                        'compra' => 0,
                        'venta' => 0
                    ],
                    'mep' => [
                        'compra' => 0,
                        'venta' => 0
                    ],
                    'ccl' => [
                        'compra' => 0,
                        'venta' => 0
                    ],
                    'cripto' => [
                        'compra' => 0,
                        'venta' => 0
                    ],
                    'tarjeta' => [
                        'valor' => 0
                    ],
                    'freelance' => [
                        'valor' => 0
                    ]
                ],
                'fuente' => 'valores_fallback_http_error',
                'scraping_success' => false,
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'error' => "Error HTTP: {$e->getMessage()}"
            ];
            
        } catch (Exception $e) {
            Log::error('Error general en scraping: ' . $e->getMessage(), [
                'url' => $url,
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'cotizaciones' => [
                    'oficial' => [
                        'compra' => 0,
                        'venta' => 0
                    ],
                    'blue' => [
                        'compra' => 0,
                        'venta' => 0
                    ],
                    'mep' => [
                        'compra' => 0,
                        'venta' => 0
                    ],
                    'ccl' => [
                        'compra' => 0,
                        'venta' => 0
                    ],
                    'cripto' => [
                        'compra' => 0,
                        'venta' => 0
                    ],
                    'tarjeta' => [
                        'valor' => 0
                    ],
                    'freelance' => [
                        'valor' => 0
                    ]
                ],
                'fuente' => 'valores_fallback',
                'scraping_success' => false,
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
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language' => 'es-ES,es;q=0.8,en-US;q=0.5,en;q=0.3',
            // Accept-Encoding removido - Laravel HTTP Client maneja compresión automáticamente
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

    /**
     * Log detallado del resultado del scraping
     */
    private function logScrapingResult(string $url, $response, array $rawPrices, array $finalData): void
    {
        Log::info('Scraping completado', [
            'url' => $url,
            'status_code' => $response->status(),
            'response_time' => $response->handlerStats()['total_time'] ?? 'unknown',
            'content_length' => strlen($response->body()),
            'raw_prices' => $rawPrices,
            'final_data' => $finalData,
            'found_types' => array_keys($rawPrices),
            'scraping_success' => !empty($rawPrices),
            'timestamp' => now()->toISOString()
        ]);
    }
} 