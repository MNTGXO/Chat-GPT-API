# MN Bots PHP API

A pure PHP API service with:
- Chat completions via Groq (`/chat`) with optional SSE streaming.
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

## Environment Variables

- `GROQ_API_KEY` (required for `/chat`)
- `DEFAULT_MODEL` (optional, default: `openai/gpt-oss-120b`)
- `PORT` (optional for local/dev)

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

- Basic per-IP rate limits are enabled on `/chat`.
- Streaming behavior depends on platform proxy compatibility with SSE.
