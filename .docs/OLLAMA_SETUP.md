# Ollama Setup — Oracle Cloud Always Free (ARM64)

Target: `VM.Standard.A1.Flex`, 2 OCPU / 12 GB RAM, Ubuntu ARM64.

## 1. Install Ollama

```bash
curl -fsSL https://ollama.com/install.sh | sh
```

Ollama installs a systemd service (`ollama.service`) that starts on boot.

## 2. Pull the model

```bash
ollama pull qwen3.5:4b
```

See `.docs/QWEN_MODEL_CONFIGURATION.md` for RAM/context guidance.

## 3. Bind to a private interface

Edit the service override:

```bash
sudo systemctl edit ollama
```

```ini
[Service]
Environment="OLLAMA_HOST=127.0.0.1:11434"
Environment="OLLAMA_CONTEXT_LENGTH=16384"
Environment="OLLAMA_KEEP_ALIVE=10m"
```

```bash
sudo systemctl daemon-reload && sudo systemctl restart ollama
```

**Never bind Ollama to `0.0.0.0` without an authenticating reverse proxy in
front.** See `.docs/SECURITY.md`.

## 4. Endpoints the app can use

| Style | Endpoint | Notes |
|---|---|---|
| OpenAI-compatible | `http://127.0.0.1:11434/v1` | App appends `/chat/completions`; no `num_ctx` support (set `OLLAMA_CONTEXT_LENGTH` instead) |
| Ollama native | `http://127.0.0.1:11434/api/chat` | App sends `options.num_ctx`, `keep_alive`, `stream:false`; usage comes back as `prompt_eval_count`/`eval_count` |

The Laravel app may run on shared hosting — use the public URL of the
authenticating reverse proxy (HTTPS) in that case, with `auth_mode=basic` or
`bearer`.

## 5. Verify from the app

Admin → AI Provider → **Test Connection**, then **Check Model Availability**
(lists models via `/api/tags`).

## 6. Restart behavior

The systemd unit restarts Ollama automatically. After VM reboots, model loads
lazily on first request; the app's long `timeout_seconds` (default 900)
absorbs the first-load latency.
