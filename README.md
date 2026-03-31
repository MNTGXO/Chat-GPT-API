# MN Bots ChatGpt Api

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
 
## Contributing

We welcome contributions to improve ChatGpt Api! To contribute:

1. Fork the repository.
2. Create a new branch (`git checkout -b feature-branch`).
3. Make your changes and commit (`git commit -m 'Add new feature'`).
4. Push to the branch (`git push origin feature-branch`).
5. Open a pull request to the main repository.

Please ensure your code follows the project's coding standards and includes appropriate documentation.

## Support

For support, join our community:

- **Telegram Bot Channel**: [@mnbots](https://t.me/mnbots)
- **Support Group**: [@mnbots_support](https://t.me/mnbots_support)
- **Contact Owners**:
  - [@Mrmntg](https://t.me/Mrmntg)
  - [@musammiln](https://t.me/musammiln)

## Credits

Developed by [MN BOTS](https://github.com/mn-bots)  
GitHub Owner: [@mntgxo](https://github.com/mntgxo)

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details
