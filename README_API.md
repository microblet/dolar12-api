# API de Cotizaciones del Dólar Argentino

Esta API proporciona cotizaciones actualizadas del dólar argentino obtenidas de dolarhoy.com.

## Configuración

### 1. Variables de entorno

Agrega la siguiente variable a tu archivo `.env`:

```env
API_KEY=tu_api_key_secreta_aqui
```

### 2. Generar API Key

Para generar una API key segura:

```bash
php artisan tinker
echo Str::random(32);
```

## Endpoints

### Autenticación

Todos los endpoints requieren el header `X-API-KEY`:

```
X-API-KEY: tu_api_key_secreta_aqui
```

### Endpoints disponibles

#### 1. Obtener todas las cotizaciones (con cache)
```
GET /api/dolar
```

#### 2. Obtener cotizaciones frescas (sin cache)
```
GET /api/dolar/fresh
```

#### 3. Obtener cotización específica
```
GET /api/dolar/{tipo}
```
Tipos: `oficial`, `blue`, `mep`, `ccl`, `cripto`, `tarjeta`

#### 4. Health check
```
GET /api/health
```

#### 5. Test sin autenticación
```
GET /api/test
```

## Ejemplos de uso

### cURL
```bash
curl -H "X-API-KEY: tu_api_key" http://localhost:8000/api/dolar
```

### JavaScript
```javascript
fetch('http://localhost:8000/api/dolar', {
    headers: {'X-API-KEY': 'tu_api_key'}
})
.then(response => response.json())
.then(data => console.log(data));
```

## Respuesta de ejemplo

```json
{
    "success": true,
    "data": {
        "cotizaciones": {
            "oficial": {"compra": 350.50, "venta": 358.75},
            "blue": {"compra": 980.00, "venta": 990.00},
            "mep": {"compra": 850.25, "venta": 860.50},
            "ccl": {"compra": 880.00, "venta": 890.00},
            "cripto": {"compra": 920.00, "venta": 930.00},
            "tarjeta": {
                "valor": 573.00,
                "impuestos": "60%",
                "descripcion": "PAÍS + RG"
            }
        },
        "fuente": "dolarhoy.com",
        "scraping_success": true,
        "timestamp": "2024-01-15 14:30:00"
    }
}
```

## Características

- Cache de 2 minutos
- 3 reintentos automáticos
- User-Agent rotativo
- Logging detallado
- Headers de navegador real
- Manejo de errores consistente
