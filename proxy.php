<?php
/**
 * Proxy para las novedades de la Biblioteca Municipal de Cadrete
 * Red de Bibliotecas de Aragón - AbsysNet OPAC
 *
 * Modos:
 *   GET proxy.php?start=N   → JSON con 10 novedades desde la posición N (caché 1h)
 *   GET proxy.php?cover=URL → sirve la imagen de portada (caché permanente)
 *
 * Flujo de paginación del OPAC:
 *   1) GET  /cgi-bin/opac           → token de sesión (meta-refresh)
 *   2) POST /{token}/NT1 ACC=131    → ejecuta búsqueda, guarda cookies de sesión
 *   3) GET  /{token}/NT1?ACC=161&DOC=N → página N usando esas cookies
 */

define('CACHE_DIR',     __DIR__ . '/cache');
define('CACHE_TTL',     3600);
define('SESSION_FILE',  CACHE_DIR . '/session.json');
define('COOKIE_FILE',   CACHE_DIR . '/cookies.txt');

define('OPAC_BASE',  'https://bibliotecas.aragon.es');
define('OPAC_ENTRY', OPAC_BASE . '/cgi-bin/opac');

// ---------- modo imagen ----------
if (isset($_GET['cover'])) {
    serveCover($_GET['cover']);
    exit;
}

// ---------- modo JSON ----------
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$start = max(1, (int)($_GET['start'] ?? 1));
$cacheFile = CACHE_DIR . '/novedades_s' . $start . '.json';

// Servir caché si es válida y la sesión no ha expirado
if (sessionIsValid() && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < CACHE_TTL) {
    echo file_get_contents($cacheFile);
    exit;
}

// Si la sesión expiró o no existe, iniciar una nueva búsqueda
// (esto invalida todas las páginas cacheadas de la sesión anterior)
if (!sessionIsValid()) {
    clearPageCache();
    if (!initSession()) {
        http_response_code(502);
        echo json_encode(['error' => 'No se pudo iniciar sesión en el catálogo de Aragón']);
        exit;
    }
}

// Obtener el token de sesión activo
$session = json_decode(file_get_contents(SESSION_FILE), true);
$token   = $session['token'];
$opacUrl = OPAC_BASE . '/cgi-bin/opac/' . $token . '/NT1';

// Fetch de la página solicitada
$html = ($start === 1)
    ? fetchFirstPage($opacUrl)
    : fetchPage($opacUrl, $start);

if ($html === false) {
    // Puede que la sesión haya expirado en el servidor; reintentar una vez
    clearPageCache();
    if (!initSession()) {
        http_response_code(502);
        echo json_encode(['error' => 'No se pudo obtener las novedades del catálogo']);
        exit;
    }
    $session = json_decode(file_get_contents(SESSION_FILE), true);
    $token   = $session['token'];
    $opacUrl = OPAC_BASE . '/cgi-bin/opac/' . $token . '/NT1';
    $html    = ($start === 1) ? fetchFirstPage($opacUrl) : fetchPage($opacUrl, $start);
}

if ($html === false) {
    http_response_code(502);
    echo json_encode(['error' => 'No se pudo obtener las novedades del catálogo']);
    exit;
}

['books' => $books, 'total' => $total] = parseBooks($html, $token);

$json = json_encode(
    ['updated' => date('c'), 'start' => $start, 'total' => $total, 'books' => $books],
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);
file_put_contents($cacheFile, $json);
echo $json;

// ==========================================================

function sessionIsValid(): bool
{
    if (!file_exists(SESSION_FILE)) return false;
    return (time() - filemtime(SESSION_FILE)) < CACHE_TTL;
}

function clearPageCache(): void
{
    foreach (glob(CACHE_DIR . '/novedades_s*.json') as $f) {
        unlink($f);
    }
    if (file_exists(COOKIE_FILE)) unlink(COOKIE_FILE);
    if (file_exists(SESSION_FILE)) unlink(SESSION_FILE);
}

/**
 * Obtiene un token de sesión y ejecuta la búsqueda inicial (POST ACC=131)
 * para que el servidor OPAC tenga el resultado en caché de sesión.
 * Guarda cookies y token en disco.
 */
function initSession(): bool
{
    if (!is_dir(CACHE_DIR)) mkdir(CACHE_DIR, 0755, true);

    // 1. GET entry page → token
    $ch = curl_init(OPAC_ENTRY);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_COOKIEJAR      => COOKIE_FILE,
        CURLOPT_HTTPHEADER     => commonHeaders(),
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    if (!$body || !preg_match('#URL=/cgi-bin/opac/([A-Za-z0-9]+)\?#i', $body, $m)) {
        return false;
    }
    $token   = $m[1];
    $opacUrl = OPAC_BASE . '/cgi-bin/opac/' . $token . '/NT1';

    // 2. POST ACC=131 → ejecuta búsqueda, guarda cookies
    $html = fetchFirstPage($opacUrl);
    if ($html === false || str_contains($html, 'timeout.htm')) return false;

    // Guardar sesión
    file_put_contents(SESSION_FILE, json_encode(['token' => $token, 'created' => date('c')]));

    // Cachear la página 1 ya que la tenemos
    ['books' => $books, 'total' => $total] = parseBooks($html, $token);
    $json = json_encode(
        ['updated' => date('c'), 'start' => 1, 'total' => $total, 'books' => $books],
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );
    file_put_contents(CACHE_DIR . '/novedades_s1.json', $json);

    return true;
}

/**
 * POST ACC=131 — primera búsqueda, devuelve página 1
 */
function fetchFirstPage(string $opacUrl): string|false
{
    $postData = http_build_query([
        'ACC'      => '131',
        'START'    => '1',
        'xindex'   => '',
        'xindty'   => '',
        'xsqf99'   => '1',
        'xsoi99'   => '5',
        'groupb'   => 'B',
        'groups'   => 'S',
        'groupg'   => 'BG',
        'xstype'   => 'COPI',
        'xsdate'   => '7',
        'subcat'   => 'OPA/CADRET',
        'FORM'     => '5',
        'select03' => '01',
    ]);

    $ch = curl_init($opacUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_COOKIEJAR      => COOKIE_FILE,
        CURLOPT_COOKIEFILE     => COOKIE_FILE,
        CURLOPT_HTTPHEADER     => array_merge(commonHeaders(), [
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: ' . OPAC_BASE,
            'Referer: ' . $opacUrl,
        ]),
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    return execCurl($ch);
}

/**
 * GET ACC=161&DOC=N — páginas siguientes, requiere cookies de sesión
 */
function fetchPage(string $opacUrl, int $start): string|false
{
    $url = $opacUrl . '?ACC=161&DOC=' . $start;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_COOKIEFILE     => COOKIE_FILE,
        CURLOPT_HTTPHEADER     => array_merge(commonHeaders(), [
            'Referer: ' . $opacUrl,
        ]),
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    return execCurl($ch);
}

function execCurl($ch): string|false
{
    $response = curl_exec($ch);
    $errno    = curl_errno($ch);
    curl_close($ch);

    if ($errno || $response === false) return false;

    $utf8 = mb_convert_encoding($response, 'UTF-8', 'ISO-8859-1');
    $utf8 = preg_replace('/<meta[^>]+charset\s*=\s*["\']?iso-8859-1["\']?[^>]*>/i', '<meta charset="utf-8">', $utf8);
    return $utf8;
}

function commonHeaders(): array
{
    return [
        'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: es-ES,es;q=0.9',
    ];
}

function parseBooks(string $html, string $token): array
{
    if (str_contains($html, 'timeout.htm')) {
        return ['books' => [], 'total' => 0];
    }

    $books      = [];
    $total      = 0;
    $detailBase = OPAC_BASE . '/cgi-bin/opac/' . $token . '/NT4';

    if (preg_match('#\d+\s*/\s*\d+\s+de\s+(\d+)#i', $html, $m)) {
        $total = (int)$m[1];
    }

    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();

    $xpath = new DOMXPath($doc);
    $items = $xpath->query('//li[contains(@class,"c-docs_unit")]');

    foreach ($items as $item) {
        $book = [];

        $imgNode = $xpath->query('.//div[contains(@class,"c-docs_img")]//img', $item)->item(0);
        if ($imgNode) {
            $src = $imgNode->getAttribute('src');
            $src = str_starts_with($src, 'http') ? $src : OPAC_BASE . $src;
            // Ignorar la imagen de "sin portada" del servidor
            $book['cover'] = str_contains($src, 'nofound') ? '' : 'proxy.php?cover=' . urlencode($src);
        } else {
            $book['cover'] = '';
        }

        $linkNode = $xpath->query('.//h3//a[1]', $item)->item(0);
        if ($linkNode) {
            $href = $linkNode->getAttribute('href');
            $book['url'] = $detailBase . (str_starts_with($href, '?') ? $href : '?' . ltrim($href, '/'));
        } else {
            $book['url'] = '';
        }

        $authorNode = $xpath->query('.//span[contains(@class,"c-docs_author")]', $item)->item(0);
        $book['author'] = $authorNode ? trim($authorNode->textContent) : '';

        $titleNode = $xpath->query('.//span[contains(@class,"c-docs_title")]', $item)->item(0);
        $book['title'] = $titleNode ? trim(rtrim($titleNode->textContent, ' /')) : '';

        $ddNode = $xpath->query('.//div[contains(@class,"c-docs_data")]//dl/dd', $item)->item(0);
        $book['publication'] = $ddNode ? trim($ddNode->textContent) : '';

        if ($book['title']) {
            $books[] = $book;
        }
    }

    return ['books' => $books, 'total' => $total];
}

function serveCover(string $url): void
{
    $allowed = ['covers.absys.cloud', 'bibliotecas.aragon.es'];
    $host = parse_url($url, PHP_URL_HOST);
    $port = parse_url($url, PHP_URL_PORT);
    if (!$host || !in_array($host, $allowed, true) || $port !== null) {
        http_response_code(400);
        exit;
    }

    $cacheDir = CACHE_DIR . '/covers';
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);

    $cacheKey  = md5($url);
    $cacheMeta = $cacheDir . '/' . $cacheKey . '.meta';
    $cacheImg  = $cacheDir . '/' . $cacheKey . '.img';

    if (file_exists($cacheImg) && file_exists($cacheMeta)) {
        header('Content-Type: ' . trim(file_get_contents($cacheMeta)));
        header('Cache-Control: public, max-age=86400');
        readfile($cacheImg);
        return;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => [
            'Referer: ' . OPAC_BASE . '/',
            'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $imgData     = curl_exec($ch);
    $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = strtok(curl_getinfo($ch, CURLINFO_CONTENT_TYPE), "\r\n");
    curl_close($ch);

    if (!$imgData || $httpCode !== 200 || !str_starts_with($contentType, 'image/')) {
        http_response_code(404);
        exit;
    }

    file_put_contents($cacheImg,  $imgData);
    file_put_contents($cacheMeta, $contentType);

    header('Content-Type: ' . $contentType);
    header('Cache-Control: public, max-age=86400');
    echo $imgData;
}
