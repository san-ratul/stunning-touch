# Oracle Cloud Always Free — AI Node Deployment

Target: `VM.Standard.A1.Flex` (2 OCPU, 12 GB RAM), Ubuntu ARM64.

## 1. Create the instance

1. Compute → Create Instance → shape `VM.Standard.A1.Flex`, 2 OCPU / 12 GB.
2. Image: Ubuntu 22.04/24.04 ARM64.
3. Networking: public subnet; note the public IP.
4. Security list: allow 22 (SSH, restrict source), 443 (HTTPS) — **not** 11434.

## 2. Install Ollama + model

```bash
curl -fsSL https://ollama.com/install.sh | sh
sudo systemctl edit ollama   # see .docs/OLLAMA_SETUP.md
ollama pull qwen3.5:4b
```

## 3. Reverse proxy with HTTPS + auth (Nginx example)

```nginx
server {
    listen 443 ssl;
    server_name ai.example.com;

    ssl_certificate     /etc/letsencrypt/live/ai.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/ai.example.com/privkey.pem;

    auth_basic "AI API";
    auth_basic_user_file /etc/nginx/.htpasswd;

    location / {
        proxy_pass http://127.0.0.1:11434;
        proxy_read_timeout 960s;   # long CPU generations
        proxy_send_timeout 960s;
    }
}
```

```bash
sudo htpasswd -c /etc/nginx/.htpasswd aiuser
sudo systemctl reload nginx
```

Laravel admin config: endpoint `https://ai.example.com/api/chat`,
authentication `basic`, user/password from the htpasswd file.

## 4. systemd management

Ollama's installer registers `ollama.service` (restarts on failure, starts on
boot). Verify:

```bash
systemctl status ollama
systemctl is-enabled ollama
```

## 5. Benchmark before tuning (mandatory)

```bash
ollama run qwen3.5:4b --verbose "Write a 900 word article about image compression."
free -h     # RAM headroom after generation
```

Record tokens/s and RAM. Do not raise `context_length` above 16384 or enable
concurrent generations without measuring on this exact VM. The app records
real request durations (AI Monitoring → avg generation time) as the
authoritative measurement.

## 6. Connect the Laravel app

Admin → AI Provider:

1. Endpoint: `https://ai.example.com/api/chat` (or `http://127.0.0.1:11434/api/chat` when co-located).
2. Model: `qwen3.5:4b`.
3. Authentication: `basic` (or `none` when co-located on the same VM).
4. Timeout 900 s, context 16384, retries 2.
5. **Test Connection** → **Check Model Availability**.

## Recovery

- Ollama restarts automatically (systemd). After a reboot, the first request
  reloads the model — the 900 s timeout absorbs this.
- Pipeline runs are resumable; a crashed VM boot resumes slots on the next cron tick.
- Backups: database (posts/settings) matters more than the AI node — the node
  is stateless apart from the pulled model (re-pull to restore).
