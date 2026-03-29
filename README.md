# MN Bots PHP API

A pure PHP API service with:
- Chat completions via Groq (`/chat`) with optional SSE streaming.
- Image processing (`/upscale`, `/images/transform`) powered by GD.
- TeraBox download-link extraction (`/teradl?url={url}`).
- Utility endpoints (`/health`, `/models`).
- A built-in web playground at `/`.

## API Endpoints

### `GET /`
Interactive playground UI.

### `GET /health`
Returns runtime health metadata.

### `GET /models`
Returns model aliases and configured default model.

### `POST /chat`
Request JSON:

```json
{
  "model": "openai/gpt-oss-120b",
  "messages": [{"role": "user", "content": "Hello"}],
  "temperature": 0.7,
  "max_tokens": 1024,
  "stream": false
}
```

Response shape is simplified:

```json
{
  "response": "Assistant reply text"
}
```

### `POST /upscale` or `POST /images/transform`
Multipart form-data:
- `image`: image file
- `mode`: `upscale | restore | enhance | grayscale | sharpen`

Returns JSON with `download_url`.

### `GET /teradl?url={terabox_share_url}`
Extracts file metadata and direct download link from a public TeraBox share URL.
If `DDL_OWN=true`, `download_link` is returned as your own domain redirect URL.
Responses include a `cached` flag when a recent extraction result is served from cache for faster repeat calls.

## Environment Variables

- `GROQ_API_KEY` (required for `/chat`)
- `DEFAULT_MODEL` (optional, default: `openai/gpt-oss-120b`)
- `TERABOX_COOKIE` (optional, recommended for TeraBox extraction reliability; accepts full cookie string like `ndus=...` or just the raw ndus value)
  - Keep this value on a **single line** in your dashboard env vars (no spaces/newlines).
  - Cookies generated from `dm.1024terabox.com` are also supported.
- `TERABOX_NDUS` (optional, raw ndus value; merged into Cookie header automatically)
- `TERABOX_COOKIE_EXTRA` (optional, extra cookie pairs like `browserid=...; lang=en`; merged with `TERABOX_COOKIE`)
- `TERABOX_COOKIE_FILE` (optional path; default `./terabox.txt`, supports JSON map, JSON cookie array, plain cookie string, or Netscape cookie file lines)
- `DDL_OWN` (optional, `true|false`, when true wraps extracted direct links with `/teradl/download?u=...`)
- `PORT` (optional for local/dev)

### `terabox.txt` file support (optional)

You can create `terabox.txt` in project root and keep cookies there instead of env vars.

Supported formats:
1. JSON map by domain:
   ```json
   {"default":"ndus=...","teraboxapp.com":"ndus=...; browserid=...",".1024terabox.com":"ndus=..."}
   ```
2. JSON array of cookie objects (`domain`, `name`, `value`).
3. Plain cookie header string: `ndus=...; browserid=...`
4. Netscape cookie file lines.

See `terabox.txt.example` in this repo.

## Local Run

```bash
php -S 0.0.0.0:8000 index.php
```

## Deployment

This repo includes platform config for:
- **Heroku** (`Procfile`, `project.toml`)
- **Render** (`render.yaml`)
- **Koyeb** (`koyeb.yaml`, `Dockerfile`)
- **Vercel** (`vercel.json`)
- **Any Docker-compatible platform** (`Dockerfile`)

## Notes

- PHP GD extension is required for image features (the project now enforces this via Composer `ext-gd`).
- Basic per-IP rate limits are enabled on `/chat`, image endpoints, and `/teradl`.
- Streaming behavior depends on platform proxy compatibility with SSE.
