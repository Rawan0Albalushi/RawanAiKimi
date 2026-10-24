# Deployment notes

This is not approval to deploy. The proof of concept runs on the developer workstation. Laravel is not installed on RunPod. The worker image, endpoint id, GPU row, disk, and idle settings stay as they were after the operator's smoke test.

## What would be deployed later

Only the Laravel application moves to a PHP host. The model stays on the existing Serverless endpoint.

A later host needs:

- PHP 8.3 or newer with the extensions listed in [local-setup.md](local-setup.md)
- `composer install --no-dev`
- `npm ci` and `npm run build` before the process starts, or a built `public/build` produced in CI
- A writable `storage/` and `bootstrap/cache/`
- `SESSION_DRIVER=file` and `CACHE_STORE=file` unless a later change introduces a shared store on purpose
- `QUEUE_CONNECTION=sync`
- `APP_DEBUG=false`
- `APP_ENV=production`
- `APP_URL` set to the HTTPS origin
- `SESSION_SECURE_COOKIE=true` when the site is served over HTTPS

No migration step is required for the chat. Do not point the session or cache at MySQL for this POC.

## Secrets

Set these in the host's secret store, not in the repository and not in the frontend bundle:

- `APP_KEY`
- `RUNPOD_API_KEY`
- `RUNPOD_ENDPOINT_ID=f6icz11anfucte` for the endpoint that passed the operator's smoke test

`KIMI_BASE_URL` stays `https://api.runpod.ai/v2`. `KIMI_MODEL` stays `kimi-vl`.

Logs may include the operation name and HTTP status from `kimi.request_failed`. They must not include the API key or the chat text. The application does not log either.

## Timeouts

| Call | Timeout |
| --- | --- |
| `POST /run` | 20 seconds |
| `GET /status/{job_id}` and `POST /cancel/{job_id}` | 15 seconds |
| Whole wait, across polls | 1020 seconds |

The web server and PHP `max_execution_time` only need to cover one of those short calls. They do not need to stay open for the cold start. The browser polls `POST /chat/poll`.

## What this document does not do

- It does not create, edit, or delete a RunPod endpoint.
- It does not change worker count, GPU type, disk, or idle timeout.
- It does not start a worker.
- It does not put Laravel on the GPU host.
- It does not add a paid database, cache, or queue service.

Shutdown and billing remain an operator action on the RunPod account. The application cannot see the invoice. Max workers of 1 is not a spending cap.
