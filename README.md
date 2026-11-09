# Cadrete Catalog Explorer

Página web estática que muestra las últimas novedades de la **Biblioteca Municipal de Cadrete** a partir del [Catálogo de la Red de Bibliotecas de Aragón](https://bibliotecas.aragon.es).

## Cómo funciona

- `index.html` hace peticiones a `proxy.php` y renderiza un grid de tarjetas con scroll infinito (10 resultados por página).
- Se muestran los ejemplares dados de alta en los **últimos 90 días**, ordenados por **fecha de alta** (más recientes primero). El OPAC no expone esa fecha ni permite ordenar por ella, así que el proxy descarga todas las páginas de resultados y las ordena por el número de registro local del ejemplar de Cadrete (`$9` en el MARC de la ficha), que es correlativo.
- `proxy.php` actúa de intermediario con el OPAC AbsysNet de la Red de Bibliotecas de Aragón, evitando las restricciones CORS. También sirve las portadas de los libros para evitar el bloqueo por hotlinking.
- Los resultados y las portadas se cachean en el servidor para no sobrecargar el catálogo:
  - Novedades: **1 hora** (`cache/novedades.json`, lista completa ya ordenada)
  - Números de registro: **permanente** (`cache/registros.json`), así solo se consulta la ficha de los libros nuevos. La primera carga en frío abre la ficha de cada libro y tarda alrededor de un minuto.
  - Portadas: **permanente** (`cache/covers/`)

## Requisitos

- PHP 8.1 o superior con las extensiones `curl`, `mbstring` y `dom`
- Servidor web (Apache, Nginx...) con soporte PHP
- Permisos de escritura en el directorio del proyecto (para la carpeta `cache/`)

## Instalación

1. Copia los ficheros `index.html` y `proxy.php` en el directorio público de tu servidor.

2. Asegúrate de que el servidor web tiene permisos de escritura. La carpeta `cache/` se crea automáticamente en el primer acceso; si no, créala manualmente:

   ```bash
   mkdir cache && chmod 775 cache
   ```

3. Abre `index.html` en el navegador (a través del servidor, no como fichero local).

## Desarrollo local

Con PHP instalado, lanza el servidor integrado desde el directorio del proyecto:

```bash
php -S localhost:8080
```

Luego abre [http://localhost:8080](http://localhost:8080).

Para forzar una recarga de los datos (limpiar caché):

```bash
rm -rf cache/novedades.json cache/session.json cache/cookies.txt
```

## Estructura

```
/
├── index.html        # Frontend estático (HTML + CSS + JS vanilla)
├── proxy.php         # Proxy PHP + caché
└── cache/            # Generado automáticamente
    ├── session.json  # Token de sesión del OPAC (TTL 1h)
    ├── cookies.txt   # Cookies de sesión para paginación
    ├── novedades.json  # Lista completa ordenada por fecha de alta (TTL 1h)
    ├── registros.json  # Nº de registro de cada libro (permanente)
    └── covers/       # Portadas cacheadas permanentemente
```
