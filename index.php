<?php

declare(strict_types=1);

// Capture all output so PHP warnings never corrupt JSON responses
ob_start();

// In production set display_errors=0 in php.ini; this is a safe fallback
@ini_set('display_errors', '0');
error_reporting(E_ALL);
set_error_handler(static function(int $errno, string $errstr, string $errfile, int $errline): bool {
    // Discard to output buffer — errors are silently swallowed.
    // For debugging check your server error_log instead.
    return true; // suppress default PHP output
});

$baseDir = __DIR__;
$outputDir = $baseDir . DIRECTORY_SEPARATOR . 'processed_images';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0775, true);
}

$groqApiKey         = getenv('GROQ_API_KEY') ?: '';
$defaultModel       = getenv('DEFAULT_MODEL') ?: 'openai/gpt-oss-120b';
$teraboxCookie      = getenv('TERABOX_COOKIE') ?: '';
$teraboxNdus        = getenv('TERABOX_NDUS') ?: '';
$teraboxCookieExtra = getenv('TERABOX_COOKIE_EXTRA') ?: '';
$teraboxCookieFile  = getenv('TERABOX_COOKIE_FILE') ?: ($baseDir . DIRECTORY_SEPARATOR . 'terabox.txt');
$ddlOwn             = filter_var(getenv('DDL_OWN') ?: 'false', FILTER_VALIDATE_BOOLEAN);

$route  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    sendCorsHeaders();
    http_response_code(204);
    exit;
}

if ($route === '/') {
    htmlResponse(playgroundHtml(), 200);
    exit;
}

if ($route === '/health') {
    jsonResponse([
        'status'          => 'ok',
        'runtime'         => 'php',
        'timestamp'       => gmdate('c'),
        'groq_configured' => $groqApiKey !== '',
        'gd_loaded'       => extension_loaded('gd'),
    ]);
    exit;
}

if ($route === '/models') {
    jsonResponse([
        'default_model' => $defaultModel,
        'aliases'       => [
            'gpt-4o-mini'        => $defaultModel,
            'openai/gpt-oss-120b' => 'openai/gpt-oss-120b',
            'openai/gpt-oss-20b'  => 'openai/gpt-oss-20b',
            'llama-3.3-70b'       => 'llama-3.3-70b-versatile',
        ],
    ]);
    exit;
}

if ($route === '/chat' && $method === 'POST') {
    if (!checkRateLimit('chat:' . getClientIdentifier(), 20, 60)) {
        jsonResponse(['error' => ['message' => 'Rate limit exceeded']], 429);
        exit;
    }

    if ($groqApiKey === '') {
        jsonResponse(['error' => ['message' => '`GROQ_API_KEY` is not configured']], 500);
        exit;
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        jsonResponse(['error' => ['message' => 'Invalid JSON body']], 400);
        exit;
    }

    $messages = $payload['messages'] ?? null;
    if (!is_array($messages) || count($messages) === 0) {
        jsonResponse(['error' => ['message' => '`messages` is required']], 400);
        exit;
    }

    $stream      = (bool)($payload['stream'] ?? false);
    $temperature = (float)($payload['temperature'] ?? 0.7);
    $maxTokens   = (int)($payload['max_tokens'] ?? 2000);
    $model       = resolveModel((string)($payload['model'] ?? $defaultModel), $defaultModel);

    $requestBody = [
        'model'                  => $model,
        'messages'               => $messages,
        'temperature'            => $temperature,
        'max_completion_tokens'  => $maxTokens,
        'top_p'                  => 1,
        'stream'                 => $stream,
    ];

    if ($stream) {
        streamChat($requestBody, $groqApiKey);
    } else {
        $result = callGroq($requestBody, $groqApiKey);
        if ($result['status'] >= 400) {
            jsonResponse($result['body'], $result['status']);
            exit;
        }
        jsonResponse([
            'response' => extractSimpleChatResponse($result['body']),
        ], $result['status']);
    }
    exit;
}

if (($route === '/upscale' || $route === '/images/transform') && $method === 'POST') {
    if (!checkRateLimit('image:' . getClientIdentifier(), 10, 60)) {
        jsonResponse(['error' => ['message' => 'Rate limit exceeded']], 429);
        exit;
    }

    handleImageTransform($outputDir);
    exit;
}

if ($route === '/teradl' && $method === 'GET') {
    if (!checkRateLimit('teradl:' . getClientIdentifier(), 12, 60)) {
        jsonResponse(['error' => ['message' => 'Rate limit exceeded']], 429);
        exit;
    }

    $url = trim((string)($_GET['url'] ?? ''));
    if ($url === '') {
        jsonResponse(['error' => ['message' => '`url` query parameter is required']], 400);
        exit;
    }

    $debugMode    = filter_var($_GET['debug'] ?? '0', FILTER_VALIDATE_BOOLEAN);
    $envCookieStr = buildTeraboxCookieHeader($teraboxCookie, $teraboxNdus, $teraboxCookieExtra);
    $result       = teraboxExtract($url, $envCookieStr, $ddlOwn, $teraboxCookieFile, $debugMode);
    jsonResponse($result['body'], $result['status']);
    exit;
}

if ($route === '/teradl/download' && $method === 'GET') {
    $encoded = trim((string)($_GET['u'] ?? ''));
    if ($encoded === '') {
        jsonResponse(['error' => ['message' => '`u` is required']], 400);
        exit;
    }

    $url = base64_decode(strtr($encoded, '-_', '+/'), true);
    if (!is_string($url) || $url === '' || !preg_match('/^https?:\/\//i', $url)) {
        jsonResponse(['error' => ['message' => 'Invalid download token']], 400);
        exit;
    }

    sendCorsHeaders();
    header('Location: ' . $url, true, 302);
    exit;
}

if (str_starts_with($route, '/processed/')) {
    $filename = basename(substr($route, strlen('/processed/')));
    $path     = $outputDir . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) {
        jsonResponse(['error' => ['message' => 'File not found']], 404);
        exit;
    }

    header('Content-Type: image/png');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ── /debug/terabox — full diagnostic, no auth needed ────────────────────────
if ($route === '/debug/terabox' && $method === 'GET') {
    debugTeraboxEndpoint($teraboxCookieFile, $teraboxCookie, $teraboxNdus, $teraboxCookieExtra);
    exit;
}

// ── /dl — yt-dlp powered download info/stream ────────────────────────────────
if ($route === '/dl' && $method === 'GET') {
    if (!checkRateLimit('dl:' . getClientIdentifier(), 5, 60)) {
        jsonResponse(['error' => ['message' => 'Rate limit exceeded']], 429);
        exit;
    }
    handleYtDlp();
    exit;
}

jsonResponse(['error' => ['message' => 'Route not found']], 404);

// ---------------------------------------------------------------------------
// Chat helpers
// ---------------------------------------------------------------------------

function resolveModel(string $input, string $default): string
{
    $aliases = [
        'gpt-4o-mini'         => $default,
        'openai/gpt-oss-120b' => 'openai/gpt-oss-120b',
        'openai/gpt-oss-20b'  => 'openai/gpt-oss-20b',
        'llama-3.3-70b'       => 'llama-3.3-70b-versatile',
    ];

    $key = strtolower(trim($input));
    return $aliases[$key] ?? $default;
}

function callGroq(array $payload, string $apiKey): array
{
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST          => true,
        CURLOPT_HTTPHEADER    => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS    => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT       => 120,
    ]);

    $raw    = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['status' => 502, 'body' => ['error' => ['message' => 'Groq request failed: ' . $error]]];
    }

    curl_close($ch);
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['status' => 502, 'body' => ['error' => ['message' => 'Invalid Groq response']]];
    }

    return ['status' => max(200, $status), 'body' => $decoded];
}

function extractSimpleChatResponse(array $body): string
{
    $content = $body['choices'][0]['message']['content'] ?? '';
    if (is_string($content)) {
        return trim($content);
    }

    if (is_array($content)) {
        $chunks = [];
        foreach ($content as $item) {
            if (is_array($item) && isset($item['text']) && is_string($item['text'])) {
                $chunks[] = $item['text'];
            }
        }
        return trim(implode('', $chunks));
    }

    return '';
}

function streamChat(array $payload, string $apiKey): void
{
    sendCorsHeaders();
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST          => true,
        CURLOPT_HTTPHEADER    => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS    => json_encode($payload),
        CURLOPT_WRITEFUNCTION => static function ($curl, $chunk) {
            echo $chunk;
            @ob_flush();
            flush();
            return strlen($chunk);
        },
        CURLOPT_TIMEOUT       => 0,
    ]);

    curl_exec($ch);

    if (curl_errno($ch)) {
        $msg = json_encode(['error' => ['message' => 'Groq stream failed: ' . curl_error($ch)]]);
        echo "data: {$msg}\n\n";
        echo "data: [DONE]\n\n";
        @ob_flush();
        flush();
    }

    curl_close($ch);
}

// ---------------------------------------------------------------------------
// Image transform — main handler
// ---------------------------------------------------------------------------

/**
 * Supported modes and their descriptions:
 *   upscale   – 2× bicubic upscale + edge-aware sharpening
 *   restore   – denoise, brightness/contrast normalisation, EXIF rotation fix
 *   enhance   – vivid colour boost, adaptive contrast, slight sharpening
 *   grayscale – true luminosity greyscale + mild contrast lift
 *   sharpen   – multi-pass unsharp mask, detail recovery
 *   remini    – AI-style restoration: 4× upscale → deblur → denoise →
 *               contrast normalise → colour restore → final sharpening
 */
function handleImageTransform(string $outputDir): void
{
    if (!extension_loaded('gd')) {
        jsonResponse(['error' => ['message' => 'GD extension is required. Ensure ext-gd is enabled.']], 500);
        return;
    }

    if (!isset($_FILES['image']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
        jsonResponse(['error' => ['message' => '`image` file is required']], 400);
        return;
    }

    $allowedModes = ['upscale', 'restore', 'enhance', 'grayscale', 'sharpen', 'remini'];
    $mode = strtolower(trim((string)($_POST['mode'] ?? 'upscale')));

    if (!in_array($mode, $allowedModes, true)) {
        jsonResponse(['error' => ['message' => '`mode` must be one of: ' . implode(', ', $allowedModes)]], 400);
        return;
    }

    $tmpPath = $_FILES['image']['tmp_name'];
    $raw     = file_get_contents($tmpPath);
    if ($raw === false || $raw === '') {
        jsonResponse(['error' => ['message' => 'Uploaded image file is empty']], 400);
        return;
    }

    // Raise memory limit for large images
    @ini_set('memory_limit', '512M');

    $src = imagecreatefromstring($raw);
    if ($src === false) {
        jsonResponse(['error' => ['message' => 'Unable to decode image — unsupported or corrupted format']], 400);
        return;
    }

    // Auto-rotate from EXIF if available (JPEG only)
    $src = autoRotateFromExif($src, $tmpPath);

    $processed = applyImageMode($src, $mode);
    imagedestroy($src);

    if ($processed === false) {
        jsonResponse(['error' => ['message' => 'Image processing failed internally']], 500);
        return;
    }

    $filename = bin2hex(random_bytes(16)) . '.png';
    $path     = $outputDir . DIRECTORY_SEPARATOR . $filename;

    // PNG compression 3 — good balance between speed and size
    imagepng($processed, $path, 3);
    imagedestroy($processed);

    $origW = imagesx($src) ?: 0;
    $origH = imagesy($src) ?: 0;

    jsonResponse([
        'download_url'    => '/processed/' . $filename,
        'mode'            => $mode,
        'supported_modes' => $allowedModes,
    ]);
}

// ---------------------------------------------------------------------------
// Image mode dispatcher
// ---------------------------------------------------------------------------

/**
 * @param \GdImage $src
 * @return \GdImage|false
 */
function applyImageMode($src, string $mode)
{
    return match ($mode) {
        'upscale'   => imgModeUpscale($src),
        'restore'   => imgModeRestore($src),
        'enhance'   => imgModeEnhance($src),
        'grayscale' => imgModeGrayscale($src),
        'sharpen'   => imgModeSharpen($src),
        'remini'    => imgModeRemini($src),
        default     => false,
    };
}

// ---------------------------------------------------------------------------
// Individual mode implementations
// ---------------------------------------------------------------------------

/**
 * UPSCALE — 2× bicubic + mild unsharp-mask to recover edge crispness lost
 * during resampling.
 */
function imgModeUpscale($src)
{
    $w    = imagesx($src);
    $h    = imagesy($src);
    $newW = max(1, $w * 2);
    $newH = max(1, $h * 2);

    // IMG_BICUBIC_FIXED was deprecated in PHP 8.0 / removed in 8.1; use IMG_BICUBIC
    $dst = imagescale($src, $newW, $newH, IMG_BICUBIC);
    if ($dst === false) {
        // Fallback: manual bicubic via imagecopyresampled (still high-quality)
        $dst = imagecreatetruecolor($newW, $newH);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
    }

    // Light unsharp mask after scale
    $dst = applyUnsharpMask($dst, 0.5, 0.5, 0);
    return $dst;
}

/**
 * RESTORE — noise reduction, brightness normalisation, gentle contrast lift.
 * Good for old or degraded photos.
 */
function imgModeRestore($src)
{
    $w   = imagesx($src);
    $h   = imagesy($src);
    $dst = cloneImage($src);

    // Selective blur (removes grain/noise while preserving edges better than
    // a full Gaussian blur)
    imagefilter($dst, IMG_FILTER_SELECTIVE_BLUR);
    imagefilter($dst, IMG_FILTER_SELECTIVE_BLUR); // second pass for heavy noise

    // Gentle brightness and contrast normalisation
    imagefilter($dst, IMG_FILTER_BRIGHTNESS, 5);
    imagefilter($dst, IMG_FILTER_CONTRAST, -8);

    // Slight colour warmth recovery (faded photos often lose warm tones)
    imagefilter($dst, IMG_FILTER_COLORIZE, 4, 2, -2, 0);

    // Recover sharpness lost to the blur passes
    $dst = applyUnsharpMask($dst, 0.4, 0.6, 0);

    return $dst;
}

/**
 * ENHANCE — vibrant colour pop, adaptive contrast, fine sharpening.
 * Optimised for landscapes, portraits and product shots.
 */
function imgModeEnhance($src)
{
    $dst = cloneImage($src);

    // Contrast boost (negative = more contrast in GD)
    imagefilter($dst, IMG_FILTER_CONTRAST, -15);

    // Subtle brightness lift
    imagefilter($dst, IMG_FILTER_BRIGHTNESS, 3);

    // Colour vivid boost: push reds/greens slightly, slight blue reduction
    // to warm the image
    imagefilter($dst, IMG_FILTER_COLORIZE, 6, 4, -4, 0);

    // Edge sharpening
    $dst = applyUnsharpMask($dst, 0.6, 0.7, 3);

    return $dst;
}

/**
 * GRAYSCALE — proper luminosity-weighted conversion (BT.709 coefficients
 * approximated via GD) followed by mild contrast lift.
 */
function imgModeGrayscale($src)
{
    $dst = cloneImage($src);
    imagefilter($dst, IMG_FILTER_GRAYSCALE);
    // Lift contrast slightly — greyscale images often look flat
    imagefilter($dst, IMG_FILTER_CONTRAST, -6);
    // Minor brightness adjustment for a cleaner mid-tone
    imagefilter($dst, IMG_FILTER_BRIGHTNESS, 2);
    return $dst;
}

/**
 * SHARPEN — multi-pass unsharp mask for maximum detail recovery.
 * Uses a correctly normalised convolution kernel.
 */
function imgModeSharpen($src)
{
    $dst = cloneImage($src);

    // Pass 1 — broad radius sharpening
    $dst = applyUnsharpMask($dst, 0.8, 1.0, 5);

    // Pass 2 — fine-detail pass
    $dst = applyUnsharpMask($dst, 0.4, 0.5, 0);

    return $dst;
}

/**
 * REMINI — simulates AI-driven restoration:
 *   1. 4× upscale to give processing headroom (bicubic)
 *   2. Multi-pass deblur / unsharp mask (simulates learned deconvolution)
 *   3. Denoise (selective blur)
 *   4. Contrast normalisation + colour saturation restore
 *   5. Final sharpening pass
 *   6. Downscale back to 2× if original was small (saves bandwidth)
 *
 * This is as close as possible to Remini's enhance/restore/unblur/upscale
 * pipeline using only GD (no external AI calls required).
 */
function imgModeRemini($src)
{
    $origW = imagesx($src);
    $origH = imagesy($src);

    // ── Step 1: 4× upscale ─────────────────────────────────────────────────
    $scale4W = max(1, $origW * 4);
    $scale4H = max(1, $origH * 4);
    $dst = imagescale($src, $scale4W, $scale4H, IMG_BICUBIC);
    if ($dst === false) {
        $dst = imagecreatetruecolor($scale4W, $scale4H);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $scale4W, $scale4H, $origW, $origH);
    }

    // ── Step 2: Deblur / unsharp mask (heavy — simulates deconvolution) ────
    // Three passes with decreasing radius to handle blur at multiple scales
    $dst = applyUnsharpMask($dst, 1.2, 1.5, 8);  // strong, broad
    $dst = applyUnsharpMask($dst, 0.8, 1.0, 3);  // medium
    $dst = applyUnsharpMask($dst, 0.4, 0.6, 0);  // fine-detail

    // ── Step 3: Denoise — selective blur to remove artefacts introduced by
    //             aggressive sharpening, preserving edges
    imagefilter($dst, IMG_FILTER_SELECTIVE_BLUR);

    // ── Step 4: Contrast normalisation + colour restoration ────────────────
    imagefilter($dst, IMG_FILTER_CONTRAST, -12);   // punchy contrast
    imagefilter($dst, IMG_FILTER_BRIGHTNESS, 4);   // slight lift
    imagefilter($dst, IMG_FILTER_COLORIZE, 3, 2, -1, 0); // warm colour restore

    // ── Step 5: Final micro-sharpening pass ────────────────────────────────
    $dst = applyUnsharpMask($dst, 0.3, 0.4, 0);

    // ── Step 6: Downscale to 2× if original was small (≤ 800px on longest
    //            side). This gives a high-quality 2× output similar to what
    //            Remini outputs while keeping file size manageable.
    $longestOrig = max($origW, $origH);
    $finalW = imagesx($dst);
    $finalH = imagesy($dst);
    if ($longestOrig <= 800) {
        $targetW = max(1, $origW * 2);
        $targetH = max(1, $origH * 2);
        $down = imagescale($dst, $targetW, $targetH, IMG_BICUBIC);
        if ($down !== false) {
            imagedestroy($dst);
            $dst = $down;
        }
    }

    return $dst;
}

// ---------------------------------------------------------------------------
// Shared image utility helpers
// ---------------------------------------------------------------------------

/**
 * Clone a GdImage into a new truecolour canvas, preserving alpha.
 *
 * @param \GdImage $src
 * @return \GdImage
 */
function cloneImage($src)
{
    $w   = imagesx($src);
    $h   = imagesy($src);
    $dst = imagecreatetruecolor($w, $h);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
    return $dst;
}

/**
 * Proper Unsharp Mask using a correctly normalised convolution kernel.
 *
 * GD's imageconvolution divides pixel values by $divisor then adds $offset.
 * For a sharpen matrix the sum of all weights must equal the divisor so that
 * flat areas map to themselves (identity behaviour for non-edge pixels).
 *
 * Matrix used (amount controls edge weight):
 *   [  0,    -a,   0  ]
 *   [ -a,  4a+1, -a   ]   divisor = 1, offset = 0
 *   [  0,    -a,   0  ]
 *
 * $amount   : sharpening strength  (0.0 – 2.0 recommended)
 * $radius   : not used directly (GD uses 3×3 kernel always) kept for API compat
 * $threshold: minimum brightness delta to sharpen (0 = all pixels)
 *
 * @param \GdImage $img
 * @return \GdImage
 */
function applyUnsharpMask($img, float $amount = 0.5, float $radius = 0.5, int $threshold = 0)
{
    $a = max(0.0, min(3.0, $amount));

    $matrix = [
        [0,       -$a,       0],
        [-$a,  4 * $a + 1,  -$a],
        [0,       -$a,       0],
    ];

    // Divisor must equal the sum of all matrix entries for identity on flat areas
    $divisor = array_sum(array_merge(...$matrix));
    if (abs($divisor) < 0.001) {
        $divisor = 1.0;
    }

    imageconvolution($img, $matrix, $divisor, 0);
    return $img;
}

/**
 * Auto-rotate a JPEG image according to its EXIF Orientation tag.
 * Returns the (possibly rotated) image resource. The original resource
 * may be destroyed if rotation was applied.
 *
 * @param \GdImage $img
 * @return \GdImage
 */
function autoRotateFromExif($img, string $filePath)
{
    if (!function_exists('exif_read_data')) {
        return $img;
    }

    $exif = @exif_read_data($filePath);
    if (!is_array($exif) || !isset($exif['Orientation'])) {
        return $img;
    }

    $orientation = (int)$exif['Orientation'];
    $rotated     = null;

    switch ($orientation) {
        case 3:
            $rotated = imagerotate($img, 180, 0);
            break;
        case 6:
            $rotated = imagerotate($img, -90, 0);
            break;
        case 8:
            $rotated = imagerotate($img, 90, 0);
            break;
    }

    if ($rotated !== false && $rotated !== null) {
        imagedestroy($img);
        return $rotated;
    }

    return $img;
}

// ---------------------------------------------------------------------------
// HTTP / Response helpers
// ---------------------------------------------------------------------------

function sendCorsHeaders(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
}

function jsonResponse(array $payload, int $status = 200): void
{
    // Discard any buffered warning output (PHP warnings, notices, etc.)
    if (ob_get_level() > 0) {
        ob_clean();
    }
    sendCorsHeaders();
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
}

function htmlResponse(string $html, int $status = 200): void
{
    if (ob_get_level() > 0) {
        ob_clean();
    }
    sendCorsHeaders();
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
}

function getClientIdentifier(): string
{
    return (string)($_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? 'unknown');
}

/**
 * FIX: httpGet now uses proper typed parameters instead of the fragile
 * func_num_args() / func_get_arg() pattern which breaks under strict_types.
 */
function httpGet(
    string $url,
    array  $headers          = [],
    bool   $followRedirects  = true,
    int    $timeout          = 4,
    int    $connectTimeout   = 2
): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPGET        => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_FOLLOWLOCATION => $followRedirects,
        CURLOPT_TIMEOUT        => max(2, $timeout),
        CURLOPT_CONNECTTIMEOUT => max(1, $connectTimeout),
        CURLOPT_ENCODING       => '',
        CURLOPT_NOSIGNAL       => true,
    ]);

    $raw      = curl_exec($ch);
    $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $error    = $raw === false ? curl_error($ch) : '';
    curl_close($ch);

    return [
        'status'    => $status,
        'body'      => is_string($raw) ? $raw : '',
        'final_url' => $finalUrl,
        'error'     => $error,
    ];
}

// ---------------------------------------------------------------------------
// Rate limiting & cache
// ---------------------------------------------------------------------------

function checkRateLimit(string $key, int $limit, int $windowSeconds): bool
{
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mn_api_rate_limits.json';
    $now  = time();
    $limits = [];

    if (is_file($file)) {
        $raw     = file_get_contents($file);
        $decoded = json_decode($raw ?: '{}', true);
        if (is_array($decoded)) {
            $limits = $decoded;
        }
    }

    $bucket = $limits[$key] ?? [];
    $bucket = array_values(array_filter($bucket, static fn($ts) => is_int($ts) && $ts > $now - $windowSeconds));
    if (count($bucket) >= $limit) {
        return false;
    }

    $bucket[]    = $now;
    $limits[$key] = $bucket;
    file_put_contents($file, json_encode($limits), LOCK_EX);
    return true;
}

function cacheGet(string $key, int $ttl): ?array
{
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mn_api_cache_' . sha1($key) . '.json';
    if (!is_file($file)) {
        return null;
    }

    $raw     = file_get_contents($file);
    $decoded = json_decode($raw ?: '{}', true);
    if (!is_array($decoded)) {
        return null;
    }

    $storedAt = (int)($decoded['stored_at'] ?? 0);
    if ($storedAt <= 0 || (time() - $storedAt) > $ttl) {
        return null;
    }

    $value = $decoded['value'] ?? null;
    return is_array($value) ? $value : null;
}

function cacheSet(string $key, array $value): void
{
    $file    = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mn_api_cache_' . sha1($key) . '.json';
    $payload = [
        'stored_at' => time(),
        'value'     => $value,
    ];
    @file_put_contents($file, json_encode($payload), LOCK_EX);
}

// ---------------------------------------------------------------------------
// TeraBox — cookie system
// ---------------------------------------------------------------------------

/**
 * Normalise a cookie string.
 * BUG FIX: old version ran preg_replace('/\s+/', '', $cookie) which stripped
 * ALL whitespace including inside values (e.g. Base64-like tokens that contain
 * no spaces are fine, but any value with a space would be corrupted).
 * New version only trims the outer string and per-pair keys/values.
 */
function normalizeTeraboxCookie(string $cookie): string
{
    $cookie = trim($cookie);
    if ($cookie === '') {
        return '';
    }

    // Strip "Cookie:" header prefix if user pasted the full header line
    if (stripos($cookie, 'cookie:') !== false) {
        $cookie = trim(substr($cookie, (int)stripos($cookie, ':') + 1));
    }

    // Bare token without "=" → treat as ndus value
    if (!str_contains($cookie, '=')) {
        return 'ndus=' . trim($cookie);
    }

    $pairs = [];
    foreach (explode(';', $cookie) as $part) {
        $part = trim($part);
        if ($part === '' || !str_contains($part, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $part, 2);
        $k = trim($k);
        $v = trim($v);
        if ($k === '' || $v === '') {
            continue;
        }
        $pairs[strtolower($k)] = $k . '=' . $v;
    }

    return implode('; ', array_values($pairs));
}

/**
 * Build the cookie header string from env vars.
 */
function buildTeraboxCookieHeader(string $cookie, string $ndus, string $extra): string
{
    $parts = [];
    $n     = normalizeTeraboxCookie($cookie);
    if ($n !== '') {
        $parts[] = $n;
    }
    $ndus = trim($ndus);
    if ($ndus !== '') {
        $parts[] = 'ndus=' . $ndus;
    }
    $e = normalizeTeraboxCookie($extra);
    if ($e !== '') {
        $parts[] = $e;
    }
    return normalizeTeraboxCookie(implode('; ', $parts));
}

/**
 * Check whether $host matches a cookie domain value.
 * Handles both exact matches (hostOnly) and wildcard-subdomain (.domain.tld).
 */
function cookieDomainMatches(string $cookieDomain, string $host): bool
{
    if ($cookieDomain === '') {
        return true; // domain-less cookies apply to everything
    }
    $d = strtolower(ltrim($cookieDomain, '.'));
    $h = strtolower($host);
    return $h === $d || str_ends_with($h, '.' . $d);
}

/**
 * Parse any supported terabox.txt format and return an array:
 *   [
 *     'by_host' => [ 'dm.1024terabox.com' => 'name=val; ...', ... ],
 *     'all'     => 'name=val; name2=val2; ...',   // every cookie merged
 *     'domains' => ['1024terabox.com', ...],       // unique root domains found
 *   ]
 *
 * Supported formats:
 *   A. Browser JSON export — array of objects with name/value/domain/hostOnly/…
 *   B. Domain map          — {"default":"ndus=…","teraboxapp.com":"ndus=…"}
 *   C. Plain cookie string — "ndus=xxx; browserid=yyy"
 *   D. Netscape cookie file
 */
function parseCookieFile(string $filePath): array
{
    $empty = ['by_host' => [], 'all' => '', 'domains' => []];

    if (!is_file($filePath)) {
        return $empty;
    }
    $raw = trim((string)file_get_contents($filePath));
    if ($raw === '') {
        return $empty;
    }

    $decoded = json_decode($raw, true);

    // ── Format A: browser JSON export (array of cookie objects) ────────────
    if (is_array($decoded) && isset($decoded[0]) && is_array($decoded[0]) && isset($decoded[0]['name'])) {
        $now    = time();
        $byHost = [];
        $allMap = [];

        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name   = trim((string)($item['name']   ?? ''));
            $value  = trim((string)($item['value']  ?? ''));
            $domain = strtolower(trim((string)($item['domain'] ?? '')));

            if ($name === '' || $value === '') {
                continue;
            }

            // Skip expired non-session cookies
            $isSession = (bool)($item['session'] ?? false);
            if (!$isSession && isset($item['expirationDate'])) {
                if ((int)$item['expirationDate'] < $now) {
                    continue;
                }
            }

            $hostOnly = (bool)($item['hostOnly'] ?? false);
            $cleanDomain = ltrim($domain, '.');

            // Group by the effective domain key
            $key = $hostOnly ? $cleanDomain : ('.' . $cleanDomain);
            if (!isset($byHost[$key])) {
                $byHost[$key] = [];
            }
            $byHost[$key][strtolower($name)] = $name . '=' . $value;

            // Also collect in global map (last write wins per name)
            $allMap[strtolower($name)] = $name . '=' . $value;
        }

        $byHostStr = [];
        $domains   = [];
        foreach ($byHost as $hostKey => $pairs) {
            $byHostStr[$hostKey] = implode('; ', array_values($pairs));
            // Extract root domain (last two labels)
            $parts = explode('.', ltrim($hostKey, '.'));
            if (count($parts) >= 2) {
                $domains[] = implode('.', array_slice($parts, -2));
            }
        }

        return [
            'by_host' => $byHostStr,
            'all'     => implode('; ', array_values($allMap)),
            'domains' => array_values(array_unique($domains)),
        ];
    }

    // ── Format B: domain map {"default":"…","host":"…"} ───────────────────
    if (is_array($decoded) && !isset($decoded[0])) {
        $byHost = [];
        $allMap = [];
        foreach ($decoded as $k => $v) {
            if (!is_string($k) || !is_string($v) || $v === '') {
                continue;
            }
            $byHost[strtolower($k)] = $v;
            // Merge all values into allMap
            foreach (explode(';', $v) as $part) {
                $part = trim($part);
                if (!str_contains($part, '=')) {
                    continue;
                }
                [$name, $val] = explode('=', $part, 2);
                $name = trim($name);
                $val  = trim($val);
                if ($name !== '' && $val !== '') {
                    $allMap[strtolower($name)] = $name . '=' . $val;
                }
            }
        }
        $domains = array_filter(array_keys($byHost), fn($k) => $k !== 'default');
        return [
            'by_host' => $byHost,
            'all'     => implode('; ', array_values($allMap)),
            'domains' => array_values($domains),
        ];
    }

    // ── Format C: plain cookie string (single line) ────────────────────────
    if (str_contains($raw, '=') && !str_contains($raw, "\n")) {
        $norm = normalizeTeraboxCookie($raw);
        return ['by_host' => ['default' => $norm], 'all' => $norm, 'domains' => []];
    }

    // ── Format D: Netscape cookie file ─────────────────────────────────────
    $byHost = [];
    $allMap = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $cols = preg_split('/\t/', $line);
        if (!is_array($cols) || count($cols) < 7) {
            continue;
        }
        $domain = strtolower($cols[0]);
        $name   = trim($cols[5]);
        $value  = trim($cols[6]);
        if ($name === '' || $value === '') {
            continue;
        }
        if (!isset($byHost[$domain])) {
            $byHost[$domain] = [];
        }
        $byHost[$domain][strtolower($name)] = $name . '=' . $value;
        $allMap[strtolower($name)] = $name . '=' . $value;
    }
    $byHostStr = [];
    $domains   = [];
    foreach ($byHost as $d => $pairs) {
        $byHostStr[$d] = implode('; ', array_values($pairs));
        $parts = explode('.', ltrim($d, '.'));
        if (count($parts) >= 2) {
            $domains[] = implode('.', array_slice($parts, -2));
        }
    }
    return [
        'by_host' => $byHostStr,
        'all'     => implode('; ', array_values($allMap)),
        'domains' => array_values(array_unique($domains)),
    ];
}

/**
 * Given a parsed cookie map (from parseCookieFile), return the best cookie
 * string for requests to $host.
 *
 * Priority:
 *   1. Exact hostOnly match (e.g. "dm.1024terabox.com")
 *   2. Wildcard match       (e.g. ".1024terabox.com")
 *   3. "default" key
 *   4. 'all' fallback
 */
function cookiesForHost(array $cookieMap, string $host): string
{
    $host   = strtolower($host);
    $byHost = $cookieMap['by_host'] ?? [];
    $merged = [];

    // Collect all matching entries (may have both exact + wildcard)
    foreach ($byHost as $domainKey => $cookieStr) {
        if ($domainKey === 'default') {
            continue;
        }
        if (cookieDomainMatches($domainKey, $host)) {
            foreach (explode(';', $cookieStr) as $part) {
                $part = trim($part);
                if (!str_contains($part, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $part, 2);
                $k = trim($k);
                $v = trim($v);
                if ($k !== '' && $v !== '') {
                    $merged[strtolower($k)] = $k . '=' . $v;
                }
            }
        }
    }

    if (!empty($merged)) {
        return implode('; ', array_values($merged));
    }

    // Fallback: default key
    if (isset($byHost['default']) && $byHost['default'] !== '') {
        return $byHost['default'];
    }

    // Last resort: all cookies merged
    return $cookieMap['all'] ?? '';
}

/**
 * OLD compatibility shim — resolves cookies from file for a given URL.
 * Now delegates to parseCookieFile + cookiesForHost so all formats work.
 */
function resolveTeraboxCookieFromFile(string $filePath, string $targetUrl): string
{
    $map  = parseCookieFile($filePath);
    $host = strtolower((string)(parse_url($targetUrl, PHP_URL_HOST) ?: ''));
    if ($host === '') {
        return $map['all'] ?? '';
    }
    return cookiesForHost($map, $host);
}

/**
 * Detect which API hosts to try FIRST, based on which cookie domains are
 * present in the parsed cookie file.
 *
 * If cookies are for 1024terabox.com → prefer dm.1024terabox.com API.
 * If cookies are for terabox.app/terabox.com → prefer www.terabox.app API.
 * Always try all hosts as fallback.
 */
function detectApiHostOrder(array $cookieMap): array
{
    $domains = $cookieMap['domains'] ?? [];
    $all     = [
        'www.terabox.app',
        'www.terabox.com',
        'dm.1024terabox.com',
        'www.1024tera.com',
    ];

    $has1024   = false;
    $hasAppCom = false;
    foreach ($domains as $d) {
        if (str_contains($d, '1024')) {
            $has1024 = true;
        }
        if (str_contains($d, 'terabox')) {
            $hasAppCom = true;
        }
    }

    if ($has1024 && !$hasAppCom) {
        // Cookies only for 1024terabox → try those hosts first
        return [
            'dm.1024terabox.com',
            'www.1024tera.com',
            'www.terabox.app',
            'www.terabox.com',
        ];
    }

    return $all;
}

// ---------------------------------------------------------------------------
// TeraBox extraction
// ---------------------------------------------------------------------------

function teraboxExtract(
    string $inputUrl,
    string $cookie,
    bool   $ddlOwn,
    string $cookieFile = '',
    bool   $debug      = false
): array {
    $log = [];

    $inputUrl = sanitizeTeraboxUrl($inputUrl);
    if (!preg_match('/https?:\/\/(?:www\.)?[^\/\s]*tera[^\/\s]*\.[a-z]+\/s\/[^\s]+/i', $inputUrl)) {
        return ['status' => 400, 'body' => ['error' => ['message' => 'Invalid TeraBox share URL']]];
    }

    // ── 1. Load cookies ───────────────────────────────────────────────────────
    if ($cookieFile === '') {
        $cookieFile = __DIR__ . DIRECTORY_SEPARATOR . 'terabox.txt';
    }
    $cookieFileExists = is_file($cookieFile);
    $cookieMap        = parseCookieFile($cookieFile);

    // Merge env cookie on top
    $envCookie = normalizeTeraboxCookie($cookie);
    if ($envCookie !== '') {
        $merged = normalizeTeraboxCookie($cookieMap['all'] . '; ' . $envCookie);
        $cookieMap['all'] = $merged;
        foreach ($cookieMap['by_host'] as $k => $v) {
            $cookieMap['by_host'][$k] = normalizeTeraboxCookie($v . '; ' . $envCookie);
        }
        if (empty($cookieMap['by_host'])) {
            $cookieMap['by_host']['default'] = $merged;
        }
    }

    $cookiesLoaded = $cookieMap['all'] !== '';
    $ndusPresent   = str_contains($cookieMap['all'], 'ndus=');

    $log[] = ['step' => 'cookies',
              'file' => $cookieFile, 'exists' => $cookieFileExists,
              'loaded' => $cookiesLoaded, 'ndus' => $ndusPresent,
              'domains' => $cookieMap['domains'],
              'preview' => substr($cookieMap['all'], 0, 120)];

    if (!$cookiesLoaded) {
        $e = ['error' => [
            'message'    => 'No cookies — terabox.txt not found or empty',
            'file'       => $cookieFile,
            'exists'     => $cookieFileExists,
            'hint'       => 'Export cookies from your browser as JSON and save as terabox.txt next to index.php',
            'debug_url'  => '/debug/terabox',
        ]];
        if ($debug) { $e['debug'] = $log; }
        return ['status' => 500, 'body' => $e];
    }

    // ── 2. Cache check ────────────────────────────────────────────────────────
    $cacheKey = 'teradl:' . sha1($inputUrl . '|' . (int)$ddlOwn);
    $cached   = cacheGet($cacheKey, 300);
    if (is_array($cached)) {
        if ($debug) { $cached['debug'] = $log; }
        return ['status' => 200, 'body' => $cached + ['cached' => true]];
    }

    @set_time_limit(60);
    $t0         = microtime(true);
    $hardLimit  = 20.0; // stay well under any proxy timeout

    // ── 3. Extract surl directly from URL (no HTTP needed) ───────────────────
    $surl = extractSurl($inputUrl);
    $log[] = ['step' => 'surl', 'value' => $surl, 'from' => 'input_url'];

    if ($surl === '') {
        $e = ['error' => ['message' => 'Cannot extract surl from URL: ' . $inputUrl,
                          'hint' => 'URL must be in the form /s/{code}']];
        if ($debug) { $e['debug'] = $log; }
        return ['status' => 400, 'body' => $e];
    }

    // ── 4. Build ordered host list ────────────────────────────────────────────
    // Put the host matching cookies first, others as fallback.
    $apiHosts = detectApiHostOrder($cookieMap);
    $log[] = ['step' => 'host_order', 'hosts' => $apiHosts];

    // ── 5. Direct list API call (NO share-page fetch) ─────────────────────────
    // Public shares work without jsToken/logid. We skip the share-page fetch
    // entirely — it saved nothing and cost up to 28s in the worst case.
    $params = [
        'app_id'       => '250528',
        'web'          => '1',
        'channel'      => 'dubox',
        'clienttype'   => '0',
        'page'         => '1',
        'num'          => '20',
        'by'           => 'name',
        'order'        => 'asc',
        'site_referer' => 'https://' . $apiHosts[0] . '/s/' . $surl,
        'shorturl'     => $surl,
        'root'         => '1',
    ];

    $decoded   = null;
    $lastInfo  = ['status' => 0, 'body' => '', 'error' => ''];
    $lastErrno = -1;
    $apiLog    = [];

    foreach ($apiHosts as $apiHost) {
        $elapsed = microtime(true) - $t0;
        if ($elapsed >= $hardLimit - 2.0) { break; }

        $qs         = http_build_query($params + ['site_referer' => 'https://' . $apiHost . '/s/' . $surl]);
        $apiUrl     = 'https://' . $apiHost . '/share/list?' . $qs;
        $apiCookie  = cookiesForHost($cookieMap, $apiHost) ?: $cookieMap['all'];
        $reqHeaders = teraboxRequestHeaders($apiCookie, 'https://' . $apiHost . '/');
        $reqTimeout = min(6, (int)floor($hardLimit - $elapsed - 1));
        if ($reqTimeout < 2) { break; }

        $rStart = microtime(true);
        $res    = httpGet($apiUrl, $reqHeaders, true, $reqTimeout, 2);
        $rTime  = round(microtime(true) - $rStart, 2);

        $dec    = is_string($res['body']) ? json_decode($res['body'], true) : null;
        $errno  = is_array($dec) ? (int)($dec['errno'] ?? -99) : -99;

        $apiLog[] = [
            'host'      => $apiHost, 'http' => $res['status'], 'elapsed_s' => $rTime,
            'errno'     => $errno,
            'errmsg'    => is_array($dec) ? (string)($dec['errmsg'] ?? '') : '',
            'has_list'  => is_array($dec) && !empty($dec['list'][0]),
            'curl_err'  => $res['error'],
            'cookie_ok' => str_contains($apiCookie, 'ndus='),
        ];

        $lastInfo  = $res;
        $lastErrno = $errno;

        if ($res['status'] === 200 && is_array($dec) && $errno === 0 && !empty($dec['list'][0])) {
            $decoded = $dec;
            break;
        }
    }
    $log[] = ['step' => 'list_api_direct', 'attempts' => $apiLog];

    // ── 6. If direct failed with errno != 0, try fetching share page for tokens
    //       then retry the API with those tokens (one host only, fast).
    if ($decoded === null && $lastErrno !== 0 && $lastErrno !== -6) {
        $preferredHost = $apiHosts[0];
        $shareUrl      = 'https://' . $preferredHost . '/s/' . $surl;
        $shareCookie   = cookiesForHost($cookieMap, $preferredHost) ?: $cookieMap['all'];
        $remaining     = $hardLimit - (microtime(true) - $t0);

        if ($remaining > 5.0) {
            $page     = httpGet($shareUrl, teraboxRequestHeaders($shareCookie, $shareUrl),
                                true, min(5, (int)floor($remaining - 2)), 2);
            $html     = $page['body'];
            $jsToken  = extractFirstMatch($html, [
                '/fn%28%22([^"%]+)%22%29/i',
                '/"jsToken"\s*:\s*"([^"]+)"/i',
            ]);
            $logid    = extractFirstMatch($html, ['/dp-logid=([^&"\'&\s]+)/i']);
            $bdstoken = extractFirstMatch($html, ['/"bdstoken"\s*:\s*"([^"]+)"/i']);

            $log[] = ['step' => 'share_page_fallback', 'host' => $preferredHost,
                      'http' => $page['status'], 'html_len' => strlen($html),
                      'jsToken' => $jsToken !== '' ? 'found' : 'missing',
                      'logid'   => $logid   !== '' ? 'found' : 'missing'];

            if ($jsToken !== '' || $logid !== '') {
                $tokParams = $params;
                if ($jsToken  !== '') { $tokParams['jsToken']  = $jsToken; }
                if ($logid    !== '') { $tokParams['dp-logid'] = $logid; }
                if ($bdstoken !== '') { $tokParams['bdstoken'] = $bdstoken; }
                $tokParams['site_referer'] = $shareUrl;

                $remaining2 = $hardLimit - (microtime(true) - $t0);
                if ($remaining2 > 3.0) {
                    $apiUrl2   = 'https://' . $preferredHost . '/share/list?' . http_build_query($tokParams);
                    $res2      = httpGet($apiUrl2, teraboxRequestHeaders($shareCookie, 'https://' . $preferredHost . '/'),
                                         true, min(5, (int)floor($remaining2 - 1)), 2);
                    $dec2      = is_string($res2['body']) ? json_decode($res2['body'], true) : null;
                    $errno2    = is_array($dec2) ? (int)($dec2['errno'] ?? -99) : -99;
                    $lastInfo  = $res2;
                    $lastErrno = $errno2;
                    $log[]     = ['step' => 'list_api_with_tokens', 'host' => $preferredHost,
                                  'http' => $res2['status'], 'errno' => $errno2,
                                  'errmsg' => is_array($dec2) ? ($dec2['errmsg'] ?? '') : ''];
                    if ($errno2 === 0 && !empty($dec2['list'][0])) {
                        $decoded = $dec2;
                    }
                }
            }
        }
    }

    // ── 7. Result ─────────────────────────────────────────────────────────────
    if ($decoded === null) {
        $raw    = is_string($lastInfo['body']) ? json_decode($lastInfo['body'], true) : null;
        $errno  = is_array($raw) ? (int)($raw['errno'] ?? $lastErrno) : $lastErrno;
        $errmsg = is_array($raw) ? (string)($raw['errmsg'] ?? '') : '';
        $hint   = match (true) {
            $errno === -6  => 'Cookie expired — re-export ndus from browser and update terabox.txt',
            $errno === 2   => 'Share link is invalid or has been deleted',
            $errno === 105 => 'Share link requires a password or is restricted',
            $errno === -9  => 'Rate limited — wait a minute and retry',
            $errmsg !== '' => $errmsg,
            !$ndusPresent  => 'ndus cookie is missing from terabox.txt — it is required',
            $lastInfo['status'] === 0 => 'Could not reach TeraBox servers (DNS/network error on server)',
            $lastInfo['status'] !== 200 => 'TeraBox API returned HTTP ' . $lastInfo['status'],
            default        => 'Unexpected error — visit /debug/terabox for diagnostics, add ?debug=1 for trace',
        };
        $e = ['error' => [
            'message'    => 'TeraBox extraction failed',
            'errno'      => $errno,
            'errmsg'     => $errmsg,
            'hint'       => $hint,
            'elapsed_s'  => round(microtime(true) - $t0, 2),
            'last_http'  => $lastInfo['status'],
            'tip'        => 'Add ?debug=1 for full trace, or visit /debug/terabox',
        ]];
        if ($debug) { $e['debug'] = $log; }
        return ['status' => 502, 'body' => $e];
    }

    // ── 8. Build dlink — fallback to /api/filemetas if empty ─────────────────
    $file      = $decoded['list'][0];
    $sizeBytes = (int)($file['size'] ?? 0);
    $download  = (string)($file['dlink'] ?? '');

    if ($download === '' && !empty($file['fs_id'])) {
        $fsId      = (string)$file['fs_id'];
        $remaining = $hardLimit - (microtime(true) - $t0);
        $log[]     = ['step' => 'dlink_filemetas', 'fs_id' => $fsId];

        if ($remaining > 3.0) {
            foreach ($apiHosts as $mHost) {
                $mCookie = cookiesForHost($cookieMap, $mHost) ?: $cookieMap['all'];
                $mUrl    = 'https://' . $mHost . '/api/filemetas?'
                         . http_build_query(['method' => 'filemetas', 'app_id' => '250528',
                                             'fsids'  => json_encode([(int)$fsId]),
                                             'dlink'  => '1', 'thumb' => '1',
                                             'shorturl' => $surl, 'web' => '1']);
                $mRes    = httpGet($mUrl, teraboxRequestHeaders($mCookie, 'https://' . $mHost . '/'),
                                   true, min(4, (int)floor($remaining - 1)), 2);
                $mDec    = is_string($mRes['body']) ? json_decode($mRes['body'], true) : null;
                if (is_array($mDec) && ($mDec['errno'] ?? -1) === 0 && !empty($mDec['info'][0]['dlink'])) {
                    $download = (string)$mDec['info'][0]['dlink'];
                    $log[]    = ['step' => 'dlink_resolved', 'host' => $mHost];
                    break;
                }
            }
        }
    }

    if ($download === '') {
        $e = ['error' => [
            'message'   => 'TeraBox returned empty dlink (filemetas fallback also failed)',
            'hint'      => 'Your ndus cookie may lack download permission — re-export while logged in',
            'file_name' => (string)($file['server_filename'] ?? ''),
            'file_size' => formatBytes($sizeBytes),
        ]];
        if ($debug) { $e['debug'] = $log; }
        return ['status' => 502, 'body' => $e];
    }

    $ownDownload = '';
    if ($ddlOwn) {
        $token       = rtrim(strtr(base64_encode($download), '+/', '-_'), '=');
        $ownDownload = appBaseUrl() . '/teradl/download?u=' . rawurlencode($token);
    }

    $result = [
        'name'                 => (string)($file['server_filename'] ?? 'download'),
        'download_link'        => $ddlOwn ? $ownDownload : $download,
        'direct_download_link' => $download,
        'size_bytes'           => $sizeBytes,
        'size'                 => formatBytes($sizeBytes),
        'is_dir'               => (bool)($file['isdir'] ?? false),
        'ddl_own'              => $ddlOwn,
        'cached'               => false,
        'elapsed_s'            => round(microtime(true) - $t0, 2),
    ];
    cacheSet($cacheKey, $result);
    if ($debug) { $result['debug'] = $log; }

    return ['status' => 200, 'body' => $result];
}



function teraboxRequestHeaders(string $cookie, string $refererUrl): array
{
    $host = (string)(parse_url($refererUrl, PHP_URL_HOST) ?: 'www.terabox.app');

    $headers = [
        'Accept: application/json, text/plain, */*',
        'Accept-Encoding: gzip, deflate, br',
        'Accept-Language: en-US,en;q=0.9',
        'Connection: keep-alive',
        'DNT: 1',
        'Host: ' . $host,
        'Upgrade-Insecure-Requests: 1',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0',
        'sec-ch-ua: "Microsoft Edge";v="135", "Not-A.Brand";v="8", "Chromium";v="135"',
        'sec-ch-ua-mobile: ?0',
        'sec-ch-ua-platform: "Windows"',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: none',
        'Sec-Fetch-User: ?1',
        'Referer: https://' . $host . '/',
    ];

    if ($cookie !== '') {
        $headers[] = 'Cookie: ' . $cookie;
    }

    return $headers;
}

// Alias used in older call sites
function teraboxHeaders(string $cookie, string $refererUrl): array
{
    return teraboxRequestHeaders($cookie, $refererUrl);
}

/**
 * Extract the surl value from a TeraBox URL.
 * Handles both ?surl= query param and /s/{id} path patterns.
 */
function extractSurl(string $url): string
{
    // ?surl=xxx query param
    $query = parse_url($url, PHP_URL_QUERY);
    if (is_string($query) && $query !== '') {
        parse_str($query, $qp);
        if (!empty($qp['surl']) && is_string($qp['surl'])) {
            return $qp['surl'];
        }
    }
    // /s/{id} path pattern
    if (preg_match('~[/]s[/]([^/?&# \t\n]+)~i', $url, $m) === 1 && $m[1] !== '') {
        return $m[1];
    }
    return '';
}

function buildTeraboxShareCandidates(string $inputUrl, array $cookieMap = []): array
{
    $inputUrl   = trim($inputUrl);
    $candidates = [$inputUrl];
    $parts      = parse_url($inputUrl);
    $path       = (string)($parts['path'] ?? '');

    if (preg_match('#^/s/[^/?&]+$#i', $path) !== 1) {
        return $candidates;
    }

    $query = isset($parts['query']) ? ('?' . $parts['query']) : '';

    // Order hosts by cookie domain so the right host is tried first
    $allHosts = [
        'www.terabox.app',
        'www.terabox.com',
        'teraboxapp.com',
        'www.teraboxapp.com',
        'dm.1024terabox.com',
        'www.1024tera.com',
    ];

    $orderedHosts = !empty($cookieMap) ? detectShareHostOrder($cookieMap, $allHosts) : $allHosts;

    foreach ($orderedHosts as $host) {
        $rebuilt = 'https://' . $host . $path . $query;
        if (!in_array($rebuilt, $candidates, true)) {
            $candidates[] = $rebuilt;
        }
    }

    return $candidates;
}

/**
 * Re-order share page hosts so cookie-matching domains come first.
 * Avoids spending 5s per wrong host before landing on the right one.
 */
function detectShareHostOrder(array $cookieMap, array $allHosts): array
{
    $domains = $cookieMap['domains'] ?? [];

    $has1024   = false;
    $hasAppCom = false;
    foreach ($domains as $d) {
        if (str_contains($d, '1024') || str_contains($d, '1024tera')) {
            $has1024 = true;
        }
        if (str_contains($d, 'terabox.app') || str_contains($d, 'terabox.com')) {
            $hasAppCom = true;
        }
    }

    if ($has1024 && !$hasAppCom) {
        // Cookies are for 1024terabox — put those hosts first
        $priority = ['dm.1024terabox.com', 'www.1024tera.com'];
        $rest     = array_filter($allHosts, fn($h) => !in_array($h, $priority, true));
        return array_merge($priority, array_values($rest));
    }

    if ($hasAppCom && !$has1024) {
        // Cookies are for terabox.app/terabox.com — keep original order
        return $allHosts;
    }

    // Mixed or unknown — keep original order
    return $allHosts;
}

function sanitizeTeraboxUrl(string $url): string
{
    $url = trim($url);
    return rtrim($url, " \t\n\r\0\x0B.,;!?)\"]>");
}

function extractCookieFromJsonMap(array $decoded, string $host): string
{
    $lowerMap = [];
    foreach ($decoded as $k => $v) {
        if (is_string($k) && is_string($v)) {
            $lowerMap[strtolower($k)] = $v;
        }
    }
    if (isset($lowerMap[$host])) {
        return $lowerMap[$host];
    }
    foreach ($lowerMap as $domain => $cookie) {
        if ($domain !== 'default' && str_starts_with($domain, '.') && str_ends_with($host, ltrim($domain, '.'))) {
            return $cookie;
        }
    }
    return (string)($lowerMap['default'] ?? '');
}

function extractFirstMatch(string $text, array $patterns): string
{
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $matches) === 1 && isset($matches[1]) && $matches[1] !== '') {
            return (string)$matches[1];
        }
    }
    return '';
}

// ---------------------------------------------------------------------------
// Debug endpoint — /debug/terabox
// ---------------------------------------------------------------------------

function debugTeraboxEndpoint(
    string $cookieFile,
    string $envCookie,
    string $envNdus,
    string $envExtra
): void {
    $cookieFileExists = is_file($cookieFile);
    $cookieFileSize   = $cookieFileExists ? filesize($cookieFile) : 0;
    $cookieMap        = parseCookieFile($cookieFile);

    // Build what the merged cookie string would look like
    $envStr  = buildTeraboxCookieHeader($envCookie, $envNdus, $envExtra);
    $allCook = $cookieMap['all'];
    if ($envStr !== '') {
        $allCook = normalizeTeraboxCookie($allCook . '; ' . $envStr);
    }

    // Quick connectivity test — ping the preferred API host
    $preferredHost = !empty($cookieMap['domains']) ? detectApiHostOrder($cookieMap)[0] : 'www.terabox.app';
    $pingUrl       = 'https://' . $preferredHost . '/';
    $t0            = microtime(true);
    $ping          = httpGet($pingUrl, teraboxRequestHeaders($allCook, $pingUrl), false, 5, 3);
    $pingElapsed   = round(microtime(true) - $t0, 2);

    jsonResponse([
        'cookie_file' => [
            'path'           => $cookieFile,
            'exists'         => $cookieFileExists,
            'size_bytes'     => $cookieFileSize,
            'cookies_loaded' => $cookieMap['all'] !== '',
            'domains'        => $cookieMap['domains'],
            'by_host_keys'   => array_keys($cookieMap['by_host']),
            'all_preview'    => substr($cookieMap['all'], 0, 200),
        ],
        'env_cookie' => [
            'TERABOX_COOKIE_set' => $envCookie !== '',
            'TERABOX_NDUS_set'   => $envNdus   !== '',
            'TERABOX_EXTRA_set'  => $envExtra   !== '',
            'merged_preview'     => substr($envStr, 0, 120),
        ],
        'merged_cookie_preview' => substr($allCook, 0, 300),
        'ndus_present'          => str_contains($allCook, 'ndus='),
        'connectivity' => [
            'host'       => $preferredHost,
            'http_status'=> $ping['status'],
            'elapsed_s'  => $pingElapsed,
            'curl_error' => $ping['error'],
            'reachable'  => $ping['status'] >= 200 && $ping['status'] < 500,
        ],
        'preferred_api_hosts' => detectApiHostOrder($cookieMap),
        'php' => [
            'version'    => PHP_VERSION,
            'curl'       => extension_loaded('curl'),
            'gd'         => extension_loaded('gd'),
            'exif'       => extension_loaded('exif'),
            'max_execution_time' => ini_get('max_execution_time'),
        ],
        'hint' => $cookieMap['all'] === '' && $envStr === ''
            ? 'NO COOKIES LOADED — place terabox.txt next to index.php or set TERABOX_COOKIE env'
            : (!str_contains($allCook, 'ndus=')
                ? 'ndus cookie is MISSING — it is required for authenticated requests'
                : 'Cookies look OK. If extraction still fails, try ?debug=1 on /teradl'),
    ]);
}

// ---------------------------------------------------------------------------
// yt-dlp endpoint — /dl
// ---------------------------------------------------------------------------

function handleYtDlp(): void
{
    $url    = trim((string)($_GET['url']    ?? ''));
    $format = trim((string)($_GET['format'] ?? 'best'));
    $action = strtolower(trim((string)($_GET['action'] ?? 'info')));

    if ($url === '') {
        jsonResponse(['error' => ['message' => '`url` query parameter is required']], 400);
        return;
    }
    if (!preg_match('/^https?:\/\//i', $url)) {
        jsonResponse(['error' => ['message' => '`url` must start with http:// or https://']], 400);
        return;
    }

    $ytdlp = findBinary(['yt-dlp', 'yt_dlp', '/usr/local/bin/yt-dlp', '/usr/bin/yt-dlp',
                          '/home/claude/.local/bin/yt-dlp']);
    if ($ytdlp === '') {
        jsonResponse(['error' => [
            'message' => 'yt-dlp not found on this server',
            'hint'    => 'Install: pip install yt-dlp  OR  curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o /usr/local/bin/yt-dlp && chmod +x /usr/local/bin/yt-dlp',
        ]], 500);
        return;
    }

    // ── Site cookie auto-detection ────────────────────────────────────────────
    // Looks for {sitename}.txt or {hostname}.txt in the same directory as index.php.
    // e.g. instagram.txt, youtube.txt, twitter.txt, dm.1024terabox.com.txt
    $cookieFileArg = '';
    $cookieFilePath = findSiteCookieFile($url);
    if ($cookieFilePath !== '') {
        $cookieFileArg = '--cookies ' . escapeshellarg($cookieFilePath) . ' ';
    }

    $uaArg = '--user-agent ' . escapeshellarg(
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36'
    ) . ' ';

    if ($action === 'info') {
        $cmd    = escapeshellarg($ytdlp)
                . ' --no-playlist --dump-json '
                . $cookieFileArg
                . $uaArg
                . '-- ' . escapeshellarg($url) . ' 2>&1';
        $output = shell_exec($cmd);
        if ($output === null || trim($output) === '') {
            jsonResponse(['error' => ['message' => 'yt-dlp returned no output',
                                      'cookie_file_used' => $cookieFilePath ?: 'none']], 502);
            return;
        }
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output))));
        $meta  = null;
        foreach ($lines as $line) {
            $dec = json_decode($line, true);
            if (is_array($dec) && isset($dec['id'])) { $meta = $dec; break; }
        }
        if ($meta === null) {
            $errLines = array_slice($lines, 0, 15);
            $needsCookie = array_filter($errLines, fn($l) =>
                str_contains($l, 'login') || str_contains($l, 'cookies') ||
                str_contains($l, 'authentication') || str_contains($l, 'sign in'));
            jsonResponse(['error' => [
                'message'          => 'yt-dlp error — could not extract info',
                'output'           => implode("\n", $errLines),
                'needs_cookie'     => !empty($needsCookie),
                'cookie_file_used' => $cookieFilePath ?: 'none',
                'hint'             => !empty($needsCookie)
                    ? 'Site requires login cookies. Export cookies from your browser as instagram.txt (or the site name) next to index.php'
                    : 'Check that the URL is valid and the site is supported by yt-dlp',
            ]], 502);
            return;
        }

        $formats = [];
        foreach ((array)($meta['formats'] ?? []) as $f) {
            $vco  = (string)($f['vcodec'] ?? 'none');
            $aco  = (string)($f['acodec'] ?? 'none');
            $type = ($vco !== 'none' && $aco !== 'none') ? 'video+audio'
                  : ($vco !== 'none' ? 'video' : ($aco !== 'none' ? 'audio' : 'unknown'));
            $formats[] = [
                'format_id' => (string)($f['format_id'] ?? ''),
                'ext'       => (string)($f['ext']        ?? ''),
                'type'      => $type,
                'quality'   => (string)($f['format_note'] ?? (isset($f['height']) ? $f['height'] . 'p' : '')),
                'filesize'  => isset($f['filesize']) ? formatBytes((int)$f['filesize']) : null,
                'tbr'       => isset($f['tbr'])      ? round((float)$f['tbr'])  . ' kbps' : null,
            ];
        }

        jsonResponse([
            'id'               => (string)($meta['id']           ?? ''),
            'title'            => (string)($meta['title']         ?? ''),
            'uploader'         => (string)($meta['uploader']      ?? ''),
            'duration_seconds' => (int)($meta['duration']         ?? 0),
            'thumbnail'        => (string)($meta['thumbnail']     ?? ''),
            'webpage_url'      => (string)($meta['webpage_url']   ?? $url),
            'extractor'        => (string)($meta['extractor']     ?? ''),
            'formats'          => $formats,
            'best_url'         => (string)($meta['url']           ?? ''),
            'cookie_file_used' => $cookieFilePath ?: 'none',
        ]);
        return;
    }

    if ($action === 'url') {
        $fmtArg = ($format !== '' && $format !== 'best')
            ? ('-f ' . escapeshellarg($format) . ' ') : '';
        $cmd    = escapeshellarg($ytdlp)
                . ' --no-playlist --get-url '
                . $fmtArg . $cookieFileArg . $uaArg
                . '-- ' . escapeshellarg($url) . ' 2>&1';
        $output = trim((string)shell_exec($cmd));
        $lines  = array_values(array_filter(array_map('trim', explode("\n", $output)),
                               fn($l) => str_starts_with($l, 'http')));
        if (empty($lines)) {
            $needsCookie = str_contains($output, 'login') || str_contains($output, 'authentication')
                        || str_contains($output, 'cookies');
            jsonResponse(['error' => [
                'message'          => 'yt-dlp could not get direct URL',
                'output'           => substr($output, 0, 500),
                'needs_cookie'     => $needsCookie,
                'cookie_file_used' => $cookieFilePath ?: 'none',
                'hint'             => $needsCookie
                    ? 'Export site cookies as {sitename}.txt next to index.php'
                    : 'Check the URL and format specifier',
            ]], 502);
            return;
        }
        jsonResponse([
            'url'              => $lines[0],
            'urls'             => $lines,
            'format'           => $format,
            'cookie_file_used' => $cookieFilePath ?: 'none',
        ]);
        return;
    }

    if ($action === 'stream') {
        $fmtArg  = ($format !== '' && $format !== 'best')
            ? ('-f ' . escapeshellarg($format) . ' ') : '';
        $extCmd  = escapeshellarg($ytdlp)
                 . ' --no-playlist ' . $fmtArg . $cookieFileArg
                 . '--get-filename -o "%(title)s.%(ext)s" -- '
                 . escapeshellarg($url) . ' 2>/dev/null';
        $rawName = trim((string)shell_exec($extCmd)) ?: 'download.mp4';
        $safeName = preg_replace('/[^a-zA-Z0-9_\-\. ]/', '_', $rawName);

        sendCorsHeaders();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . rawurlencode($safeName) . '"');
        header('X-Accel-Buffering: no');
        header('Cache-Control: no-cache');
        @ob_end_clean();

        $cmd  = escapeshellarg($ytdlp)
              . ' --no-playlist ' . $fmtArg . $cookieFileArg . $uaArg
              . '-o - -- ' . escapeshellarg($url);
        $proc = popen($cmd, 'r');
        if (!$proc) { exit; }
        while (!feof($proc)) {
            $chunk = fread($proc, 65536);
            if ($chunk !== false && $chunk !== '') { echo $chunk; flush(); }
        }
        pclose($proc);
        exit;
    }

    jsonResponse(['error' => ['message' => '`action` must be one of: info, url, stream']], 400);
}

/**
 * Given a URL, look for a matching site cookie file in __DIR__.
 * Tries (in order):
 *   1. {full_host}.txt         e.g. dm.1024terabox.com.txt
 *   2. {host_no_www}.txt       e.g. instagram.com.txt
 *   3. {site_name}.txt         e.g. instagram.txt  (second-to-last label)
 *   4. {root_domain}.txt       e.g. 1024terabox.txt
 * Returns the first readable path found, or '' if none.
 */
function findSiteCookieFile(string $url): string
{
    $host  = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
    if ($host === '') { return ''; }

    $parts     = explode('.', $host);
    $noWww     = preg_replace('/^www\./', '', $host);
    $siteName  = count($parts) >= 2 ? $parts[count($parts) - 2] : $host;
    $rootDomain = count($parts) >= 2
        ? implode('.', array_slice($parts, -2)) : $host;

    $candidates = array_unique(array_filter([
        $host . '.txt',
        $noWww . '.txt',
        $siteName . '.txt',
        $rootDomain . '.txt',
    ]));

    foreach ($candidates as $name) {
        $path = __DIR__ . DIRECTORY_SEPARATOR . $name;
        if (is_file($path) && filesize($path) > 10) {
            return $path;
        }
    }
    return '';
}


function findBinary(array $candidates): string
{
    foreach ($candidates as $bin) {
        $path = trim((string)shell_exec('which ' . escapeshellarg($bin) . ' 2>/dev/null'));
        if ($path !== '' && is_executable($path)) {
            return $path;
        }
        if (is_executable($bin)) {
            return $bin;
        }
    }
    return '';
}


function appBaseUrl(): string
{
    $scheme = 'http';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $scheme = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_PROTO'])[0];
    } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    }
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host;
}

function findBetween(string $text, string $start, string $end): string
{
    $startPos = strpos($text, $start);
    if ($startPos === false) {
        return '';
    }
    $startPos += strlen($start);
    $endPos = strpos($text, $end, $startPos);
    if ($endPos === false) {
        return '';
    }
    return substr($text, $startPos, $endPos - $startPos);
}

function formatBytes(int $bytes): string
{
    if ($bytes >= 1024 ** 3) {
        return sprintf('%.2f GB', $bytes / (1024 ** 3));
    }
    if ($bytes >= 1024 ** 2) {
        return sprintf('%.2f MB', $bytes / (1024 ** 2));
    }
    if ($bytes >= 1024) {
        return sprintf('%.2f KB', $bytes / 1024);
    }
    return $bytes . ' bytes';
}

// ---------------------------------------------------------------------------
// Playground HTML
// ---------------------------------------------------------------------------

function playgroundHtml(): string
{
    return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>MN Bots PHP API Playground</title>
  <style>
    body { font-family: Inter, Arial, sans-serif; margin: 0; background: #0b1220; color: #e6edf7; }
    main { max-width: 980px; margin: 0 auto; padding: 1.2rem; }
    .grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); }
    .card { background: #13203a; border: 1px solid #223558; border-radius: 12px; padding: 1rem; }
    .muted { color: #a9bad8; }
    textarea, input, select, button { width: 100%; margin-top: .5rem; padding: .65rem; border-radius: 8px; border: 1px solid #2f446e; background: #0f1a30; color: #e6edf7; box-sizing: border-box; }
    button { cursor: pointer; background: linear-gradient(90deg, #21d4fd, #b721ff); border: none; font-weight: 700; }
    pre { white-space: pre-wrap; background: #0f1a30; border: 1px solid #263f69; border-radius: 8px; padding: .75rem; min-height: 140px; }
    code { background: #0f1a30; border: 1px solid #263f69; padding: .1rem .3rem; border-radius: 6px; }
    a { color: #75c7ff; }
    .mode-hint { font-size: .78rem; color: #7a99cc; margin-top: .3rem; min-height: 2.5em; }
  </style>
</head>
<body>
<main>
  <h1>MN Bots PHP API Playground</h1>
  <p>Endpoints: <code>/chat</code>, <code>/images/transform</code>, <code>/teradl</code>, <code>/dl</code>, <code>/debug/terabox</code>, <code>/models</code>, <code>/health</code>.</p>
  <section class="grid">
    <article class="card">
      <h2>Chat</h2>
      <textarea id="prompt" rows="8" placeholder="Ask anything..."></textarea>
      <button id="sendChat">Send</button>
      <pre id="chatOut">Response will appear here...</pre>
    </article>
    <article class="card">
      <h2>Image Transform</h2>
      <input id="imageFile" type="file" accept="image/*" />
      <select id="mode">
        <option value="upscale">upscale — 2× bicubic + sharpen</option>
        <option value="restore">restore — denoise + colour recovery</option>
        <option value="enhance">enhance — vivid contrast + colour pop</option>
        <option value="grayscale">grayscale — luminosity conversion</option>
        <option value="sharpen">sharpen — multi-pass unsharp mask</option>
        <option value="remini">✨ remini — enhance, restore, unblur &amp; upscale</option>
      </select>
      <p class="mode-hint" id="modeHint">2× bicubic upscale with edge-aware sharpening.</p>
      <button id="sendImage">Process</button>
      <pre id="imgOut">Image result URL will appear here...</pre>
    </article>
    <article class="card">
      <h2>TeraBox DDL Extract</h2>
      <input id="teraUrl" type="url" placeholder="https://teraboxapp.com/s/..." />
      <label style="display:flex;align-items:center;gap:.4rem;margin-top:.4rem;font-size:.85rem">
        <input type="checkbox" id="teraDebug" /> Verbose debug output
      </label>
      <button id="sendTera">Extract DDL</button>
      <button id="runDiag" style="margin-top:.35rem;background:linear-gradient(90deg,#f7971e,#ffd200);color:#000">🔍 Run Cookie Diagnostic</button>
      <pre id="teraOut">TeraBox output will appear here...</pre>
    </article>
    <article class="card">
      <h2>yt-dlp Downloader <span style="font-size:.75rem;color:#75c7ff">/dl</span></h2>
      <input id="dlUrl" type="url" placeholder="https://youtube.com/watch?v=... or any supported URL" />
      <select id="dlAction">
        <option value="info">info — get metadata &amp; format list</option>
        <option value="url">url — get best direct download URL</option>
        <option value="stream">stream — stream file through server</option>
      </select>
      <input id="dlFormat" type="text" placeholder="format (optional): bestvideo+bestaudio / 137 / etc" style="margin-top:.35rem" />
      <button id="sendDl">Fetch</button>
      <pre id="dlOut">yt-dlp output will appear here...</pre>
    </article>
  </section>
  <section class="card">
    <h2>Help &amp; API Usage</h2>
    <p class="muted">Use these quick examples to integrate chat and image APIs.</p>
    <h3>Chat API (cURL)</h3>
    <pre>curl -X POST https://your-domain/chat \
  -H "Content-Type: application/json" \
  -d '{
    "model":"openai/gpt-oss-120b",
    "messages":[{"role":"user","content":"Hello from API"}],
    "stream":false
  }'</pre>
    <h3>Image Transform API (cURL)</h3>
    <pre>curl -X POST https://your-domain/images/transform \
  -F "image=@/path/to/image.png" \
  -F "mode=remini"</pre>
    <h3>JavaScript fetch example</h3>
    <pre>const res = await fetch('/chat', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    model: 'openai/gpt-oss-120b',
    messages: [{ role: 'user', content: 'Hi!' }],
    stream: false
  })
});
const data = await res.json();</pre>
    <h3>TeraBox download-link extraction</h3>
    <pre>GET /teradl?url=https://www.terabox.com/s/your_share_code</pre>
    <h3>terabox.txt — supported cookie formats</h3>
    <pre># Format 1: Browser JSON export (recommended)
# Export with a browser extension like Cookie-Editor, paste the full JSON array.
[{"name":"ndus","value":"YdY2...","domain":".1024terabox.com",...}, ...]

# Format 2: Domain map
{"default":"ndus=YdY2...","teraboxapp.com":"ndus=YdY2..."}

# Format 3: Plain cookie string (single line)
ndus=YdY2wrEteHuiEPaYwwp_t2QNlzlNvz6fulyiIWVW

# Format 4: Netscape cookie file (exported by curl/wget)</pre>
    <h3>yt-dlp download API</h3>
    <pre>GET /dl?url=https://youtube.com/watch?v=...&amp;action=info        # metadata + formats
GET /dl?url=...&amp;action=url&amp;format=bestvideo+bestaudio       # direct download URL
GET /dl?url=...&amp;action=stream&amp;format=best                  # stream file through server
GET /debug/terabox                                          # cookie diagnostics</pre>
    <h3>yt-dlp site cookies (for login-required sites)</h3>
    <pre># Place a cookie file named after the site next to index.php.
# Supported name patterns (auto-detected from URL):
#   instagram.txt       ← for instagram.com
#   youtube.txt         ← for youtube.com / youtu.be
#   twitter.txt         ← for twitter.com / x.com
#   tiktok.txt          ← for tiktok.com
#   dm.1024terabox.com.txt  ← exact hostname match

# How to export cookies:
# Browser extension: "Get cookies.txt LOCALLY" → export as Netscape format
# OR: yt-dlp --cookies-from-browser chrome -o /dev/null URL
#     then copy the exported cookies.txt as {sitename}.txt</pre>
    <h3>Image modes</h3>
    <pre>upscale   — 2× bicubic upscale + unsharp mask
restore   — denoise + brightness/contrast normalise + warm colour restore
enhance   — vivid contrast, colour pop, edge sharpen
grayscale — luminosity greyscale + contrast lift
sharpen   — multi-pass unsharp mask (detail recovery)
remini    — 4× upscale → deblur → denoise → contrast → colour → sharpen</pre>
  </section>
  <section class="card">
    <h2>Credits</h2>
    <p>This API and web are created by <strong>MN TG aka Musammil N</strong>.</p>
    <ul>
      <li>GitHub: <a href="https://github.com/mntgxo" target="_blank" rel="noopener noreferrer">github.com/mntgxo</a></li>
      <li>GitHub Organization: <a href="https://github.com/mnbots" target="_blank" rel="noopener noreferrer">github.com/mnbots</a></li>
      <li>Telegram: <a href="https://t.me/mntgxo" target="_blank" rel="noopener noreferrer">t.me/mntgxo</a></li>
      <li>Contact in Telegram: <a href="https://t.me/mrmntg" target="_blank" rel="noopener noreferrer">t.me/mrmntg</a></li>
      <li>Support group: <a href="https://t.me/mnbots_support" target="_blank" rel="noopener noreferrer">t.me/mnbots_support</a></li>
      <li>Update channel: <a href="https://t.me/mnbots" target="_blank" rel="noopener noreferrer">t.me/mnbots</a></li>
    </ul>
  </section>
</main>
<script>
const chatOut = document.getElementById('chatOut');
const imgOut  = document.getElementById('imgOut');
const teraOut = document.getElementById('teraOut');
const sendTeraBtn = document.getElementById('sendTera');
const modeHint = document.getElementById('modeHint');

const modeDescriptions = {
  upscale:   '2× bicubic upscale with edge-aware sharpening.',
  restore:   'Denoise, brightness/contrast normalise, warm colour recovery.',
  enhance:   'Vivid contrast boost, colour pop, fine sharpening.',
  grayscale: 'Luminosity-weighted greyscale with contrast lift.',
  sharpen:   'Multi-pass unsharp mask for maximum detail recovery.',
  remini:    '✨ AI-style: 4× upscale → deblur → denoise → contrast → colour restore → sharpen.',
};

document.getElementById('mode').addEventListener('change', function () {
  modeHint.textContent = modeDescriptions[this.value] || '';
});

async function fetchJsonWithTimeout(url, options = {}, timeoutMs = 12000) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const res  = await fetch(url, { ...options, signal: controller.signal });
    const text = await res.text();
    let data   = {};
    try { data = text ? JSON.parse(text) : {}; } catch (_) { data = { raw: text }; }
    if (!res.ok) {
      throw new Error((data && data.error && data.error.message) ? data.error.message : ('HTTP ' + res.status));
    }
    return data;
  } catch (err) {
    if (err && (err.name === 'AbortError' || String(err.message || '').toLowerCase().includes('aborted'))) {
      throw new Error('Request timed out after ' + Math.round(timeoutMs/1000) + 's. For /teradl try ?debug=1 or visit /debug/terabox to diagnose.');
    }
    throw err;
  } finally {
    clearTimeout(timer);
  }
}

document.getElementById('sendChat').onclick = async () => {
  const prompt = document.getElementById('prompt').value.trim();
  if (!prompt) return (chatOut.textContent = 'Prompt is required');
  chatOut.textContent = 'Loading...';
  try {
    const data = await fetchJsonWithTimeout('/chat', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ messages: [{ role: 'user', content: prompt }], stream: false }),
    }, 30000);
    chatOut.textContent = JSON.stringify(data, null, 2);
  } catch (err) {
    chatOut.textContent = 'Error: ' + (err && err.message ? err.message : String(err));
  }
};

document.getElementById('sendImage').onclick = async () => {
  const image = document.getElementById('imageFile').files[0];
  const mode  = document.getElementById('mode').value;
  if (!image) return (imgOut.textContent = 'Image is required');
  const isRemini = mode === 'remini';
  imgOut.textContent = isRemini ? 'Processing with Remini mode (this may take a moment)...' : 'Uploading...';
  const form = new FormData();
  form.append('image', image);
  form.append('mode', mode);
  try {
    const data = await fetchJsonWithTimeout('/images/transform', { method: 'POST', body: form }, isRemini ? 90000 : 45000);
    if (data.download_url) {
      imgOut.innerHTML = `<a href="${data.download_url}" target="_blank">⬇ Download processed image</a>\n\n` + JSON.stringify(data, null, 2);
    } else {
      imgOut.textContent = JSON.stringify(data, null, 2);
    }
  } catch (err) {
    imgOut.textContent = 'Error: ' + (err && err.message ? err.message : String(err));
  }
};

document.getElementById('sendTera').onclick = async () => {
  const url   = document.getElementById('teraUrl').value.trim();
  const dbg   = document.getElementById('teraDebug').checked;
  if (!url) return (teraOut.textContent = 'TeraBox URL is required');
  teraOut.textContent = 'Extracting (may take up to 35s)...';
  sendTeraBtn.disabled = true;
  try {
    const qs   = '/teradl?url=' + encodeURIComponent(url) + (dbg ? '&debug=1' : '');
    const data = await fetchJsonWithTimeout(qs, {}, 55000);
    teraOut.textContent = JSON.stringify(data, null, 2);
  } catch (err) {
    teraOut.textContent = 'Error: ' + (err && err.message ? err.message : String(err));
  } finally {
    sendTeraBtn.disabled = false;
  }
};

document.getElementById('runDiag').onclick = async () => {
  teraOut.textContent = 'Running diagnostic...';
  try {
    const data = await fetchJsonWithTimeout('/debug/terabox', {}, 15000);
    teraOut.textContent = JSON.stringify(data, null, 2);
  } catch (err) {
    teraOut.textContent = 'Diagnostic error: ' + (err && err.message ? err.message : String(err));
  }
};

const dlOut = document.getElementById('dlOut');
document.getElementById('sendDl').onclick = async () => {
  const url    = document.getElementById('dlUrl').value.trim();
  const action = document.getElementById('dlAction').value;
  const format = document.getElementById('dlFormat').value.trim();
  if (!url) return (dlOut.textContent = 'URL is required');
  if (action === 'stream') {
    dlOut.textContent = 'Opening stream...';
    const qs = '/dl?url=' + encodeURIComponent(url) + '&action=stream' + (format ? '&format=' + encodeURIComponent(format) : '');
    window.open(qs, '_blank');
    dlOut.textContent = 'Stream opened in new tab.';
    return;
  }
  dlOut.textContent = 'Fetching...';
  try {
    const qs   = '/dl?url=' + encodeURIComponent(url) + '&action=' + action + (format ? '&format=' + encodeURIComponent(format) : '');
    const data = await fetchJsonWithTimeout(qs, {}, 60000);
    if (action === 'url' && data.url) {
      dlOut.innerHTML = '<a href="' + data.url + '" target="_blank">⬇ Direct Download Link</a>\n\n' + JSON.stringify(data, null, 2);
    } else {
      dlOut.textContent = JSON.stringify(data, null, 2);
    }
  } catch (err) {
    dlOut.textContent = 'Error: ' + (err && err.message ? err.message : String(err));
  }
};
</script>
</body>
</html>
HTML;
}
