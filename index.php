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

$apiKey       = getenv('GROQ_API_KEY') ?: '';
$defaultModel = getenv('DEFAULT_MODEL') ?: 'openai/gpt-oss-120b';

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
        'status'    => 'ok',
        'runtime'   => 'php',
        'timestamp' => gmdate('c'),
    ]);
    exit;
}

if ($route === '/models') {
    jsonResponse([
        'default_model' => $defaultModel,
        'aliases'       => [
            'gpt-4o-mini'         => $defaultModel,
            'openai/gpt-oss-120b' => 'openai/gpt-oss-120b',
            'openai/gpt-oss-20b'  => 'openai/gpt-oss-20b',
            'llama-3.3-70b'       => 'llama-3.3-70b-versatile',
        ],
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// POST /chat — AI chat completions
// ---------------------------------------------------------------------------
//
// Proxies requests to the AI backend and supports both one-shot JSON
// responses and Server-Sent Events (SSE) streaming.
//
// ┌─────────────────────────────────────────────────────────────────────────┐
// │ Request body (application/json)                                         │
// ├──────────────────┬────────────┬────────┬─────────────────────────────── │
// │ Field            │ Type       │Default │ Description                    │
// ├──────────────────┼────────────┼────────┼─────────────────────────────── │
// │ messages*        │ array      │ —      │ Conversation turns. Each item  │
// │                  │            │        │ must have "role" (system /      │
// │                  │            │        │ user / assistant) and "content".│
// │ model            │ string     │ env    │ Model ID or alias (see /models).│
// │ stream           │ bool       │ false  │ true → SSE stream; false → JSON.│
// └──────────────────┴────────────┴────────┴─────────────────────────────── ┘
//
// Rate limit: 20 requests / 60 s per IP.
//
// Non-stream response: { "response": "<assistant text>" }
// Stream response:     OpenAI SSE format (text/event-stream), ending [DONE].
// ---------------------------------------------------------------------------

if ($route === '/chat' && $method === 'POST') {
    if (!checkRateLimit('chat:' . getClientIdentifier(), 20, 60)) {
        jsonResponse(['error' => ['message' => 'Rate limit exceeded']], 429);
        exit;
    }

    if ($apiKey === '') {
        jsonResponse(['error' => ['message' => 'API key is not configured']], 500);
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
        'model'                 => $model,
        'messages'              => $messages,
        'temperature'           => $temperature,
        'max_completion_tokens' => $maxTokens,
        'top_p'                 => 1,
        'stream'                => $stream,
    ];

    if ($stream) {
        streamChat($requestBody, $apiKey);
    } else {
        $result = callAi($requestBody, $apiKey);
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

function callAi(array $payload, string $apiKey): array
{
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
    ]);

    $raw    = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['status' => 502, 'body' => ['error' => ['message' => 'Request failed: ' . $error]]];
    }

    curl_close($ch);
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['status' => 502, 'body' => ['error' => ['message' => 'Invalid response from AI backend']]];
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
        $msg = json_encode(['error' => ['message' => 'Stream failed: ' . curl_error($ch)]]);
        echo "data: {$msg}\n\n";
        echo "data: [DONE]\n\n";
        @ob_flush();
        flush();
    }

    curl_close($ch);
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

    $bucket[]     = $now;
    $limits[$key] = $bucket;
    file_put_contents($file, json_encode($limits), LOCK_EX);
    return true;
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
    *, *::before, *::after { box-sizing: border-box; }
    body { font-family: Inter, Arial, sans-serif; margin: 0; background: #0b1220; color: #e6edf7; line-height: 1.6; }
    main { max-width: 1020px; margin: 0 auto; padding: 1.4rem 1rem; }
    h1 { font-size: 1.7rem; margin-bottom: .25rem; }
    h2 { font-size: 1.15rem; margin: 0 0 .6rem; color: #b8d0f5; }
    h3 { font-size: .95rem; color: #75c7ff; margin: 1.1rem 0 .35rem; border-bottom: 1px solid #1e3256; padding-bottom: .25rem; }
    h4 { font-size: .85rem; color: #a9bad8; margin: .8rem 0 .2rem; text-transform: uppercase; letter-spacing: .05em; }
    p  { margin: .35rem 0; color: #c4d4ea; }
    .muted { color: #7a99cc; font-size: .88rem; }
    .card  { background: #13203a; border: 1px solid #223558; border-radius: 12px; padding: 1.1rem 1.2rem; margin-bottom: 1rem; }
    .grid  { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); }
    textarea, input[type=text], select {
      width: 100%; margin-top: .4rem; padding: .6rem .75rem;
      border-radius: 8px; border: 1px solid #2f446e;
      background: #0f1a30; color: #e6edf7; font-family: inherit; font-size: .9rem;
    }
    button { cursor: pointer; background: linear-gradient(90deg, #21d4fd, #b721ff); border: none; border-radius: 8px; color: #fff; font-weight: 700; padding: .6rem 1rem; width: 100%; margin-top: .5rem; font-size: .9rem; }
    button.secondary { background: linear-gradient(90deg, #0f3460, #1a4a8a); }
    pre  { white-space: pre-wrap; word-break: break-all; background: #0a1525; border: 1px solid #1e3256; border-radius: 8px; padding: .8rem; min-height: 110px; font-size: .82rem; color: #b8d4ff; margin: .4rem 0 0; }
    code { background: #0f1a30; border: 1px solid #263f69; padding: .15rem .4rem; border-radius: 5px; font-size: .83rem; color: #75c7ff; }
    a    { color: #75c7ff; }
    table { width: 100%; border-collapse: collapse; font-size: .84rem; margin: .5rem 0; }
    th   { text-align: left; padding: .45rem .6rem; background: #0f1a30; color: #75c7ff; border: 1px solid #1e3256; font-weight: 600; }
    td   { padding: .4rem .6rem; border: 1px solid #1a2e50; vertical-align: top; color: #c4d4ea; }
    td code { font-size: .8rem; }
    .tag { display: inline-block; padding: .1rem .45rem; border-radius: 20px; font-size: .72rem; font-weight: 700; margin-left: .4rem; vertical-align: middle; }
    .tag-req  { background: #8b1a1a; color: #ffd0d0; }
    .tag-opt  { background: #1a4a1a; color: #b0f0b0; }
    .badge    { display: inline-block; background: #0f3460; border: 1px solid #1e5090; border-radius: 6px; padding: .15rem .6rem; font-size: .78rem; color: #75c7ff; margin: .1rem .15rem; }
    .label-row { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; margin-bottom: .4rem; }
    .endpoint-tag { display: inline-block; background: #0d2b0d; border: 1px solid #1e5a1e; color: #5dff5d; font-size: .75rem; font-weight: 700; padding: .15rem .55rem; border-radius: 5px; font-family: monospace; }
    .method-tag { display: inline-block; background: #1a1a50; border: 1px solid #3030a0; color: #8888ff; font-size: .75rem; font-weight: 700; padding: .15rem .5rem; border-radius: 5px; font-family: monospace; }
    .response-ok  { color: #5dff5d; font-weight: 600; }
    .response-err { color: #ff7070; font-weight: 600; }
    .section-divider { border: none; border-top: 1px solid #1e3256; margin: 1rem 0; }
    details > summary { cursor: pointer; color: #75c7ff; font-weight: 600; font-size: .9rem; padding: .3rem 0; }
    details[open] > summary { color: #b721ff; }
  </style>
</head>
<body>
<main>
  <h1>🤖 MN Bots PHP API</h1>
  <p class="muted">AI chat API with streaming support, multi-model routing, and rate limiting.</p>
  <p class="muted">Available endpoints: <code>/chat</code> &nbsp;·&nbsp; <code>/models</code> &nbsp;·&nbsp; <code>/health</code></p>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- PLAYGROUND                                                              -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <section class="card">
    <h2>🧪 Chat Playground</h2>
    <p class="muted">Interact with the <code>/chat</code> endpoint directly from your browser.</p>
    <div class="grid" style="grid-template-columns: 1fr 1fr; gap: .75rem; margin-top: .6rem;">
      <div>
        <label class="muted">Model</label>
        <select id="playModel">
          <option value="">Default (env DEFAULT_MODEL)</option>
          <option value="openai/gpt-oss-120b">openai/gpt-oss-120b</option>
          <option value="openai/gpt-oss-20b">openai/gpt-oss-20b</option>
          <option value="llama-3.3-70b">llama-3.3-70b (alias)</option>
        </select>
      </div>
      <div>
        <label class="muted">System Prompt (optional)</label>
        <input type="text" id="playSystem" placeholder="You are a helpful assistant." />
      </div>
    </div>
    <label class="muted" style="margin-top:.6rem;display:block;">User message</label>
    <textarea id="prompt" rows="5" placeholder="Ask anything...&#10;e.g. Explain quantum entanglement in simple terms."></textarea>
    <div style="display:flex;gap:.5rem;margin-top:.5rem;">
      <button id="sendChat" style="flex:1;">Send (JSON)</button>
      <button id="sendChatStream" class="secondary" style="flex:1;">Send (Stream)</button>
    </div>
    <pre id="chatOut">Response will appear here...</pre>
  </section>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- /chat REFERENCE                                                         -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <section class="card">
    <div class="label-row">
      <span class="method-tag">POST</span>
      <span class="endpoint-tag">/chat</span>
      <span class="badge">Rate limit: 20 req / 60 s</span>
      <span class="badge">JSON &amp; SSE streaming</span>
    </div>
    <p>Sends a conversation to the AI backend and returns a reply. Supports single-turn questions, multi-turn conversation history, custom system prompts, and real-time token streaming.</p>

    <hr class="section-divider" />
    <h3>Request body — <code>Content-Type: application/json</code></h3>
    <table>
      <thead>
        <tr><th>Field</th><th>Type</th><th>Default</th><th>Description</th></tr>
      </thead>
      <tbody>
        <tr>
          <td><code>messages</code> <span class="tag tag-req">required</span></td>
          <td>array</td>
          <td>—</td>
          <td>Ordered list of conversation turns. Each item must have <code>"role"</code> and <code>"content"</code>.<br>
              <strong>Roles:</strong> <code>system</code> (optional, first only), <code>user</code>, <code>assistant</code>.<br>
              <strong>Minimum:</strong> one <code>user</code> message.</td>
        </tr>
        <tr>
          <td><code>model</code> <span class="tag tag-opt">optional</span></td>
          <td>string</td>
          <td>env DEFAULT_MODEL</td>
          <td>Model ID or alias. See the <a href="/models">/models</a> endpoint for the full list.<br>
              Accepts: <code>openai/gpt-oss-120b</code>, <code>openai/gpt-oss-20b</code>, <code>llama-3.3-70b</code>, <code>gpt-4o-mini</code> (alias → default).</td>
        </tr>
        <tr>
          <td><code>stream</code> <span class="tag tag-opt">optional</span></td>
          <td>boolean</td>
          <td><code>false</code></td>
          <td>Set to <code>true</code> to receive the reply as a Server-Sent Events stream (<code>text/event-stream</code>). The stream follows the OpenAI SSE format and ends with <code>data: [DONE]</code>.<br>Set to <code>false</code> (default) for a standard JSON response.</td>
        </tr>
      </tbody>
    </table>

    <hr class="section-divider" />
    <h3>Response — non-stream (<code>stream: false</code>)</h3>
    <pre>{
  "response": "The assistant's reply text here."
}</pre>
    <p class="muted">On error the response contains an <code>error.message</code> field and an appropriate HTTP status code (400, 429, 500, 502).</p>

    <h3>Response — stream (<code>stream: true</code>)</h3>
    <p>Content-Type is <code>text/event-stream</code>. Each line is a Server-Sent Event in OpenAI delta format:</p>
    <pre>data: {"id":"...","object":"chat.completion.chunk","choices":[{"delta":{"content":"Hello"},...}]}
data: {"id":"...","choices":[{"delta":{"content":" world"},...}]}
data: [DONE]</pre>

    <hr class="section-divider" />
    <h3>Model aliases</h3>
    <table>
      <thead><tr><th>Alias you send</th><th>Resolves to</th></tr></thead>
      <tbody>
        <tr><td><code>gpt-4o-mini</code></td><td>Value of env <code>DEFAULT_MODEL</code></td></tr>
        <tr><td><code>openai/gpt-oss-120b</code></td><td><code>openai/gpt-oss-120b</code> (unchanged)</td></tr>
        <tr><td><code>openai/gpt-oss-20b</code></td><td><code>openai/gpt-oss-20b</code> (unchanged)</td></tr>
        <tr><td><code>llama-3.3-70b</code></td><td><code>llama-3.3-70b-versatile</code></td></tr>
        <tr><td><em>(any unrecognised string)</em></td><td>Value of env <code>DEFAULT_MODEL</code></td></tr>
      </tbody>
    </table>

    <hr class="section-divider" />
    <h3>Rate limiting</h3>
    <p>Each unique IP address is limited to <strong>20 requests per 60-second window</strong>. Exceeding this returns HTTP <code>429</code> with <code>{"error":{"message":"Rate limit exceeded"}}</code>.</p>

    <hr class="section-divider" />

    <!-- ── EXAMPLES ── -->
    <h3>Examples</h3>

    <details open>
      <summary>cURL — simple single-turn question</summary>
      <pre>curl -X POST https://mn-chat-bot-api.vercel.app/chat \
  -H "Content-Type: application/json" \
  -d '{
    "messages": [
      { "role": "user", "content": "What is the capital of France?" }
    ]
  }'</pre>
    </details>

    <details>
      <summary>cURL — custom model</summary>
      <pre>curl -X POST https://your-domain/chat \
  -H "Content-Type: application/json" \
  -d '{
    "model": "openai/gpt-oss-120b",
    "messages": [
      { "role": "user", "content": "Summarise the French Revolution in 3 bullet points." }
    ]
  }'</pre>
    </details>

    <details>
      <summary>cURL — system prompt + multi-turn conversation</summary>
      <pre>curl -X POST https://mn-chat-bot-api.vercel.app/chat \
  -H "Content-Type: application/json" \
  -d '{
    "model": "llama-3.3-70b",
    "messages": [
      { "role": "system",    "content": "You are a pirate who only speaks in nautical metaphors." },
      { "role": "user",      "content": "How do I sort a list in Python?" },
      { "role": "assistant", "content": "Arr, to sort yer list ye must call list.sort(), as sure as the tide!" },
      { "role": "user",      "content": "What about descending order?" }
    ]
  }'</pre>
    </details>

    <details>
      <summary>cURL — streaming response (SSE)</summary>
      <pre>curl -X POST https://mn-chat-bot-api.vercel.app/chat \
  -H "Content-Type: application/json" \
  --no-buffer \
  -d '{
    "stream": true,
    "messages": [
      { "role": "user", "content": "Write a short poem about the sea." }
    ]
  }'</pre>
    </details>

    <details>
      <summary>JavaScript (fetch) — non-stream</summary>
      <pre>const res = await fetch('/chat', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    model: 'openai/gpt-oss-120b',
    messages: [
      { role: 'system', content: 'You are a helpful assistant.' },
      { role: 'user',   content: 'Explain async/await in JavaScript.' }
    ]
  })
});
const data = await res.json();
console.log(data.response);</pre>
    </details>

    <details>
      <summary>JavaScript (fetch) — real-time streaming</summary>
      <pre>const res = await fetch('/chat', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    stream: true,
    messages: [{ role: 'user', content: 'Tell me a long story about a robot.' }]
  })
});

const reader = res.body.getReader();
const decoder = new TextDecoder();

while (true) {
  const { done, value } = await reader.read();
  if (done) break;

  const lines = decoder.decode(value).split('\n');
  for (const line of lines) {
    if (!line.startsWith('data: ')) continue;
    const json = line.slice(6).trim();
    if (json === '[DONE]') { console.log('\n[stream ended]'); break; }
    try {
      const chunk = JSON.parse(json);
      const token = chunk.choices?.[0]?.delta?.content ?? '';
      process.stdout.write(token); // or append to DOM
    } catch (_) {}
  }
}</pre>
    </details>

    <details>
      <summary>Python (requests) — non-stream</summary>
      <pre>import requests

r = requests.post('https://mn-chat-bot-api.vercel.app/chat', json={
    'model': 'openai/gpt-oss-120b',
    'messages': [
        {'role': 'system',  'content': 'You are a concise technical writer.'},
        {'role': 'user',    'content': 'What is a REST API?'}
    ]
})
print(r.json()['response'])</pre>
    </details>

    <details>
      <summary>Python (httpx) — streaming</summary>
      <pre>import httpx, json

with httpx.stream('POST', 'https://mn-chat-bot-api.vercel.app/chat', json={
    'stream': True,
    'messages': [{'role': 'user', 'content': 'Count from 1 to 20 slowly.'}]
}) as r:
    for line in r.iter_lines():
        if not line.startswith('data: '):
            continue
        payload = line[6:].strip()
        if payload == '[DONE]':
            break
        delta = json.loads(payload)
        token = delta['choices'][0]['delta'].get('content', '')
        print(token, end='', flush=True)</pre>
    </details>

    <details>
      <summary>PHP (curl) — non-stream</summary>
      <pre>$ch = curl_init('https://mn-chat-bot-api.vercel.app/chat');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode([
        'model'    => 'openai/gpt-oss-120b',
        'messages' => [
            ['role' => 'user', 'content' => 'What is PHP used for?']
        ],
    ]),
]);
$raw  = curl_exec($ch);
$data = json_decode($raw, true);
echo $data['response'];</pre>
    </details>

    <details>
      <summary>Node.js (https) — non-stream</summary>
      <pre>const https = require('https');

const body = JSON.stringify({
  model: 'llama-3.3-70b',
  messages: [{ role: 'user', content: 'What is Node.js?' }]
});

const req = https.request({
  hostname: 'mn-chat-bot-api.vercel.app',
  path: '/chat',
  method: 'POST',
  headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) }
}, res => {
  let data = '';
  res.on('data', c => data += c);
  res.on('end', () => console.log(JSON.parse(data).response));
});
req.write(body);
req.end();</pre>
    </details>

    <hr class="section-divider" />
    <h3>Error responses</h3>
    <table>
      <thead><tr><th>HTTP Status</th><th>Cause</th><th>Body</th></tr></thead>
      <tbody>
        <tr><td>400</td><td>Missing or invalid <code>messages</code>, malformed JSON</td><td><code>{"error":{"message":"..."}}</code></td></tr>
        <tr><td>429</td><td>Rate limit exceeded (20 req / 60 s per IP)</td><td><code>{"error":{"message":"Rate limit exceeded"}}</code></td></tr>
        <tr><td>500</td><td>API key not configured on server</td><td><code>{"error":{"message":"..."}}</code></td></tr>
        <tr><td>502</td><td>AI backend unreachable or returned invalid data</td><td><code>{"error":{"message":"..."}}</code></td></tr>
      </tbody>
    </table>
  </section>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- OTHER ENDPOINTS                                                         -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <section class="card">
    <h2>📡 Other Endpoints</h2>

    <h3><code>GET /models</code></h3>
    <p>Returns the configured default model and all available model aliases.</p>
    <pre>GET /models

→ {
  "default_model": "openai/gpt-oss-120b",
  "aliases": {
    "gpt-4o-mini": "openai/gpt-oss-120b",
    "openai/gpt-oss-120b": "openai/gpt-oss-120b",
    "openai/gpt-oss-20b": "openai/gpt-oss-20b",
    "llama-3.3-70b": "llama-3.3-70b-versatile"
  }
}</pre>

    <h3><code>GET /health</code></h3>
    <p>Liveness probe. Returns <code>200 OK</code> when the server is up.</p>
    <pre>GET /health

→ {
  "status": "ok",
  "runtime": "php",
  "timestamp": "2025-01-01T00:00:00+00:00"
}</pre>
  </section>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- CREDITS                                                                 -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <section class="card">
    <h2>Credits</h2>
    <p>This API is created by <strong>MN TG aka Musammil N</strong>.</p>
    <ul style="color:#c4d4ea;padding-left:1.2rem;">
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

async function fetchWithTimeout(url, options = {}, ms = 30000) {
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), ms);
  try {
    return await fetch(url, { ...options, signal: ctrl.signal });
  } catch (e) {
    if (e.name === 'AbortError') throw new Error('Request timed out after ' + Math.round(ms/1000) + 's');
    throw e;
  } finally {
    clearTimeout(t);
  }
}

function buildPayload(stream) {
  const model  = document.getElementById('playModel').value.trim();
  const system = document.getElementById('playSystem').value.trim();
  const prompt = document.getElementById('prompt').value.trim();

  const messages = [];
  if (system) messages.push({ role: 'system', content: system });
  messages.push({ role: 'user', content: prompt });

  const body = { messages, stream };
  if (model) body.model = model;
  return body;
}

// ── Non-stream send ────────────────────────────────────────────────────────
document.getElementById('sendChat').onclick = async () => {
  const prompt = document.getElementById('prompt').value.trim();
  if (!prompt) return (chatOut.textContent = '⚠ Prompt is required');
  chatOut.textContent = '⏳ Loading...';
  try {
    const res  = await fetchWithTimeout('/chat', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(buildPayload(false)),
    });
    const data = await res.json();
    chatOut.textContent = JSON.stringify(data, null, 2);
  } catch (err) {
    chatOut.textContent = '❌ Error: ' + (err && err.message ? err.message : String(err));
  }
};

// ── Streaming send ─────────────────────────────────────────────────────────
document.getElementById('sendChatStream').onclick = async () => {
  const prompt = document.getElementById('prompt').value.trim();
  if (!prompt) return (chatOut.textContent = '⚠ Prompt is required');
  chatOut.textContent = '⏳ Streaming...\n\n';
  try {
    const res = await fetch('/chat', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(buildPayload(true)),
    });

    const reader  = res.body.getReader();
    const decoder = new TextDecoder();
    let full = '';
    chatOut.textContent = '';

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      const lines = decoder.decode(value).split('\n');
      for (const line of lines) {
        if (!line.startsWith('data: ')) continue;
        const json = line.slice(6).trim();
        if (json === '[DONE]') { chatOut.textContent += '\n\n[stream complete]'; break; }
        try {
          const chunk = JSON.parse(json);
          const token = chunk.choices?.[0]?.delta?.content ?? '';
          full += token;
          chatOut.textContent = full;
        } catch (_) {}
      }
    }
  } catch (err) {
    chatOut.textContent = '❌ Stream error: ' + (err && err.message ? err.message : String(err));
  }
};
</script>
</body>
</html>
HTML;
}
