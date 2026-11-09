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
define('LIST_FILE',     CACHE_DIR . '/novedades.json');
define('REGS_FILE',     CACHE_DIR . '/registros.json');
define('PAGE_SIZE',     10);
define('COVER_NONE',    'none');   // marca de "sin portada" en cache/covers/*.meta

define('OPAC_BASE',  'https://bibliotecas.aragon.es');
define('OPAC_ENTRY', OPAC_BASE . '/cgi-bin/opac');

// ---------- modo imagen ----------
if (isset($_GET['cover'])) {
    serveCover($_GET['cover']);
    exit;
}

// ---------- modo detalle ----------
if (isset($_GET['detail'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    serveDetail($_GET['detail']);
    exit;
}

// ---------- modo JSON ----------
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$start = max(1, (int)($_GET['start'] ?? 1));

// Lista completa ordenada por fecha de alta (caché 1h, ligada a la sesión)
$list = null;
if (sessionIsValid() && file_exists(LIST_FILE) && (time() - filemtime(LIST_FILE)) < CACHE_TTL) {
    $list = json_decode(file_get_contents(LIST_FILE), true);
}
if (!$list) {
    // Rehacer la búsqueda completa; reintentar una vez si el OPAC falla
    $list = buildSortedList() ?: buildSortedList();
}
if (!$list) {
    http_response_code(502);
    echo json_encode(['error' => 'No se pudo obtener las novedades del catálogo']);
    exit;
}

echo json_encode(
    [
        'updated' => $list['updated'],
        'start'   => $start,
        'total'   => $list['total'],
        'books'   => array_slice($list['books'], $start - 1, PAGE_SIZE),
    ],
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);

// ==========================================================

function sessionIsValid(): bool
{
    if (!file_exists(SESSION_FILE)) return false;
    return (time() - filemtime(SESSION_FILE)) < CACHE_TTL;
}

function clearPageCache(): void
{
    foreach (glob(CACHE_DIR . '/novedades*.json') as $f) {
        unlink($f);
    }
    if (file_exists(COOKIE_FILE)) unlink(COOKIE_FILE);
    if (file_exists(SESSION_FILE)) unlink(SESSION_FILE);
}

/**
 * Inicia sesión, descarga todas las páginas de resultados y las ordena
 * por fecha de alta del ejemplar de Cadrete (más recientes primero).
 *
 * El OPAC no expone la fecha de alta ni permite ordenar por ella, pero el
 * número de registro local del ejemplar ($9 en el MARC) es correlativo, así
 * que ordenar por él equivale a ordenar por fecha de alta. El número se saca
 * de la ficha de detalle y se cachea de forma permanente por registro
 * bibliográfico (TITN), de modo que solo se consulta la ficha de libros nuevos.
 */
function buildSortedList(): array|false
{
    // La primera vez hay que abrir la ficha de cada libro
    set_time_limit(180);
    clearPageCache();
    $html = initSession();
    if ($html === false) return false;

    $token   = json_decode(file_get_contents(SESSION_FILE), true)['token'];
    $opacUrl = OPAC_BASE . '/cgi-bin/opac/' . $token . '/NT1';

    ['books' => $books, 'total' => $total] = parseBooks($html, $token);
    for ($s = 1 + PAGE_SIZE; $s <= $total; $s += PAGE_SIZE) {
        $page = fetchPage($opacUrl, $s);
        if ($page === false || str_contains($page, 'timeout.htm')) return false;
        array_push($books, ...parseBooks($page, $token)['books']);
    }

    // Número de registro de cada libro (caché permanente)
    $regs = file_exists(REGS_FILE) ? (json_decode(file_get_contents(REGS_FILE), true) ?: []) : [];
    $dirty = false;
    foreach ($books as $b) {
        if (!$b['titn'] || isset($regs[$b['titn']]) || !$b['detail_params']) continue;
        $detail = fetchDetail($token, $b['detail_params']);
        $reg    = $detail === false ? null : parseRegistro($detail);
        if ($reg) {
            $regs[$b['titn']] = $reg;
            $dirty = true;
        }
    }
    if ($dirty) file_put_contents(REGS_FILE, json_encode($regs, JSON_PRETTY_PRINT));

    // Más recientes primero; sin registro conocido, al final
    usort($books, function ($a, $b) use ($regs) {
        $ra = $regs[$a['titn']] ?? [0, 0];
        $rb = $regs[$b['titn']] ?? [0, 0];
        return $rb <=> $ra;
    });

    $books = array_map(function ($b) {
        unset($b['titn']);
        return $b;
    }, $books);

    $list = ['updated' => date('c'), 'total' => count($books), 'books' => $books];
    file_put_contents(LIST_FILE, json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return $list;
}

/**
 * Extrae [registro local ($9), id de ejemplar ($p)] del ejemplar de Cadrete
 * en la vista MARC de la ficha. Si hay varios ejemplares, el más reciente.
 */
function parseRegistro(string $html): ?array
{
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (!preg_match_all('/\$a CADRET \$b CADRET[^\n]*?\$9 (\d+)[^\n]*?\$p (\d+)/', $text, $m, PREG_SET_ORDER)) {
        return null;
    }
    $best = null;
    foreach ($m as $row) {
        $cur = [(int)$row[1], (int)$row[2]];
        if ($best === null || $cur > $best) $best = $cur;
    }
    return $best;
}

/**
 * Obtiene un token de sesión y ejecuta la búsqueda inicial (POST ACC=131)
 * para que el servidor OPAC tenga el resultado en caché de sesión.
 * Guarda cookies y token en disco. Devuelve el HTML de la primera página.
 */
function initSession(): string|false
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

    return $html;
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
        'xsdate'   => '90',
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

        // Id estable del registro bibliográfico (para la caché de registros)
        $titnNode = $xpath->query('.//span[contains(@class,"js-TITN")]', $item)->item(0);
        $book['titn'] = $titnNode ? trim($titnNode->textContent) : '';

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
            $href  = $linkNode->getAttribute('href');
            $query = ltrim(str_starts_with($href, '?') ? $href : '?' . ltrim($href, '/'), '?');
            $book['url']           = $detailBase . '?' . $query;
            $book['detail_params'] = $query;
        } else {
            $book['url']           = '';
            $book['detail_params'] = '';
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

function serveDetail(string $params): void
{
    // Solo caracteres válidos de query string
    if (!preg_match('/^[A-Za-z0-9=&%_+.\-]+$/', $params)) {
        http_response_code(400);
        echo json_encode(['error' => 'Parámetros inválidos']);
        return;
    }

    if (!sessionIsValid()) {
        clearPageCache();
        if (!initSession()) {
            http_response_code(502);
            echo json_encode(['error' => 'No se pudo iniciar sesión en el catálogo']);
            return;
        }
    }

    $session = json_decode(file_get_contents(SESSION_FILE), true);
    $token   = $session['token'];
    $html    = fetchDetail($token, $params);

    if ($html === false || str_contains($html, 'timeout.htm')) {
        clearPageCache();
        if (!initSession()) {
            http_response_code(502);
            echo json_encode(['error' => 'Sesión expirada, inténtalo de nuevo']);
            return;
        }
        $session = json_decode(file_get_contents(SESSION_FILE), true);
        $token   = $session['token'];
        $html    = fetchDetail($token, $params);
    }

    if ($html === false) {
        http_response_code(502);
        echo json_encode(['error' => 'No se pudo obtener el detalle del libro']);
        return;
    }

    echo json_encode(parseDetail($html), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

function fetchDetail(string $token, string $params): string|false
{
    $url     = OPAC_BASE . '/cgi-bin/opac/' . $token . '/NT4?' . $params;
    $referer = OPAC_BASE . '/cgi-bin/opac/' . $token . '/NT1';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_COOKIEFILE     => COOKIE_FILE,
        CURLOPT_HTTPHEADER     => array_merge(commonHeaders(), [
            'Referer: ' . $referer,
        ]),
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    return execCurl($ch);
}

function parseDetail(string $html): array
{
    if (str_contains($html, 'timeout.htm')) {
        return ['error' => 'La sesión ha expirado'];
    }

    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($doc);

    $detail = ['cover' => '', 'title' => '', 'fields' => [], 'synopsis' => '', 'holdings' => []];

    // ── Portada ───────────────────────────────────────────────
    $imgNode = $xpath->query(
        '//*[contains(@class,"c-detail_cover")]//img |
         //*[contains(@class,"c-detail_img")]//img |
         //*[contains(@class,"c-docs_img")]//img'
    )->item(0);
    if ($imgNode) {
        $src = $imgNode->getAttribute('src');
        $src = str_starts_with($src, 'http') ? $src : OPAC_BASE . $src;
        $detail['cover'] = str_contains($src, 'nofound') ? '' : 'proxy.php?cover=' . urlencode($src);
    }

    // ── Título ────────────────────────────────────────────────
    $titleNode = $xpath->query(
        '//*[contains(@class,"c-detail_title")] |
         //*[contains(@class,"c-docs_title")] |
         //h1[not(ancestor::nav)][not(ancestor::header)]'
    )->item(0);
    if ($titleNode) {
        $detail['title'] = trim(rtrim($titleNode->textContent, ' /'));
    }

    // ── Resumen / sinopsis ────────────────────────────────────
    $synopsisNode = $xpath->query(
        '//*[contains(@class,"c-detail_synopsis")] |
         //*[contains(@class,"c-detail_desc")] |
         //*[contains(@class,"c-detail_summary")] |
         //*[contains(@class,"c-record_synopsis")]'
    )->item(0);
    if ($synopsisNode) {
        $detail['synopsis'] = trim($synopsisNode->textContent);
    }

    // ── Campos bibliográficos (dt/dd) ─────────────────────────
    // Intentar primero en el área de detalle, luego fallback a toda la página
    $dts = $xpath->query(
        '//*[contains(@class,"c-detail")]//dt |
         //*[contains(@class,"c-record")]//dt |
         //*[@id="detail"]//dt'
    );
    if ($dts->length === 0) {
        $dts = $xpath->query('//main//dt | //article//dt | //section//dt');
    }
    if ($dts->length === 0) {
        $dts = $xpath->query('//dl//dt');
    }

    foreach ($dts as $dt) {
        $label = trim($dt->textContent, ": \t\n\r");
        if (!$label || mb_strlen($label) > 80) continue;

        $dd = $dt->nextSibling;
        while ($dd && $dd->nodeType !== XML_ELEMENT_NODE) {
            $dd = $dd->nextSibling;
        }
        if ($dd && strtolower($dd->nodeName) === 'dd') {
            $value = preg_replace('/\s+/', ' ', trim($dd->textContent));
            if ($value && mb_strlen($value) < 500) {
                $detail['fields'][] = ['label' => $label, 'value' => $value];
            }
        }
    }

    // ── Disponibilidad / ejemplares ───────────────────────────
    $holdTable = $xpath->query(
        '//*[contains(@id,"copias_table")]//table |
         //*[contains(@class,"copias_table")]//table |
         //*[contains(@class,"c-holdings")]//table |
         //*[contains(@class,"c-avail")]//table |
         //*[contains(@class,"c-copies")]//table |
         //*[contains(@class,"c-ejemplares")]//table |
         //*[@id="holdings"]//table'
    )->item(0);

    if ($holdTable) {
        $headers = [];
        $rows = $xpath->query('.//tr', $holdTable);
        foreach ($rows as $row) {
            $ths = $xpath->query('.//th', $row);
            $tds = $xpath->query('.//td', $row);
            if ($ths->length > 0) {
                $headers = [];
                foreach ($ths as $th) $headers[] = trim($th->textContent);
            } elseif ($tds->length > 0) {
                $cells     = [];
                $dataLabels = [];
                foreach ($tds as $td) {
                    $text = preg_replace('/\s+/', ' ', trim($td->textContent));
                    // data-label contiene la etiqueta responsiva exacta (puede diferir del <th>)
                    $dataLabels[] = trim($td->getAttribute('data-label'));
                    $cells[] = $text;
                }
                if ($headers) {
                    $holding = [];
                    foreach ($cells as $i => $val) {
                        if (!isset($headers[$i]) || $val === '') continue;
                        $header = $headers[$i];
                        // 1) Usar data-label si está disponible (más preciso que el header)
                        $label = $dataLabels[$i] ?? '';
                        $prefix = $label ?: $header;
                        if ($prefix !== '' && mb_stripos($val, $prefix) === 0) {
                            $val = trim(mb_substr($val, mb_strlen($prefix)));
                        }
                        if ($val !== '') $holding[$header] = $val;
                    }
                    if ($holding) $detail['holdings'][] = $holding;
                } else {
                    $filtered = array_values(array_filter($cells, fn($c) => $c !== ''));
                    if ($filtered) $detail['holdings'][] = $filtered;
                }
            }
        }
    }

    // Eliminar campos con la misma combinación label+valor
    $seen = [];
    $unique = [];
    foreach ($detail['fields'] as $f) {
        $key = mb_strtolower($f['label'] . '||' . $f['value']);
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $unique[] = $f;
        }
    }
    $detail['fields'] = $unique;

    return $detail;
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
        // Portada inexistente ya conocida (o píxel de relleno cacheado antes de este filtro)
        if (trim(file_get_contents($cacheMeta)) === COVER_NONE || isPlaceholderImage(file_get_contents($cacheImg))) {
            http_response_code(404);
            return;
        }
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

    if (!$imgData || $httpCode !== 200 || !str_starts_with($contentType, 'image/')) {
        http_response_code(404);
        exit;
    }

    // covers.absys.cloud responde 200 con un JPEG de 1×1 cuando no tiene portada:
    // tratarlo como "sin portada" (el frontend muestra su placeholder) y recordarlo
    if (isPlaceholderImage($imgData)) {
        file_put_contents($cacheImg, '');
        file_put_contents($cacheMeta, COVER_NONE);
        http_response_code(404);
        exit;
    }

    file_put_contents($cacheImg,  $imgData);
    file_put_contents($cacheMeta, $contentType);

    header('Content-Type: ' . $contentType);
    header('Cache-Control: public, max-age=86400');
    echo $imgData;
}

/**
 * true si la imagen es un relleno (p. ej. el píxel 1×1 que sirve
 * covers.absys.cloud cuando no tiene portada) o no es una imagen válida.
 */
function isPlaceholderImage(string $data): bool
{
    $size = @getimagesizefromstring($data);
    return !$size || $size[0] < 20 || $size[1] < 20;
}
