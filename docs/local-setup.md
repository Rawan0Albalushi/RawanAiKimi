# Local setup

The chat application runs on this Windows workstation. It does not need MySQL, Redis, or a GPU. With `RUNPOD_API_KEY` left empty, opening the page and typing a message does not contact RunPod.

## Requirements

- PHP 8.3 or newer, with `openssl`, `mbstring`, `tokenizer`, `xml`, `ctype`, `json`, `fileinfo`, and `curl`
- Composer 2
- Node.js 20 or newer, with npm
- Herd's PHP is suitable. On this machine the Windows `php` stub in `System32` is not PHP. Use Herd's `php.exe`, for example `C:\Users\Rawan.Balushi.RAWAN\.config\herd\bin\php83\php.exe`, or put that directory first on `PATH`.

## Install

From the repository root:

```powershell
copy .env.example .env
php artisan key:generate
composer install
npm install
npm run build
php artisan serve
```

Open `http://127.0.0.1:8000`.

For frontend development, run `npm run dev` in a second terminal and keep `php artisan serve` in the first. `npm run build` is enough when you only need to click through the page.

`php artisan migrate` is not required. Chat state is in the file session (`storage/framework/sessions`). Cache is the file store. The queue connection is `sync`. The skeleton still contains Laravel's default migrations, and they are unused.

## Environment

`.env` is gitignored. `.env.example` lists the keys and contains no secrets.

| Variable | Local value before a live test |
| --- | --- |
| `RUNPOD_API_KEY` | Empty |
| `RUNPOD_ENDPOINT_ID` | Empty, or `f6icz11anfucte` only after you intend to call that endpoint |
| `KIMI_BASE_URL` | `https://api.runpod.ai/v2` |
| `KIMI_MODEL` | `kimi-vl` |
| `SESSION_DRIVER` | `file` |
| `CACHE_STORE` | `file` |
| `QUEUE_CONNECTION` | `sync` |

`KimiService` accepts only `https://api.runpod.ai/v2`. Another host is rejected before any request, so the API key cannot be sent to a mistyped URL.

Do not put the RunPod key in a frontend file, a Vite variable, or a document. `VITE_` variables are bundled into JavaScript. The key must not use that prefix.

## What you can exercise without a GPU

- The empty transcript
- English and Arabic interface text, including right-to-left layout
- The message field, including Arabic text
- The "not configured" state while the key is empty
- `php vendor/bin/phpunit`, which fakes `/run`, `/status`, and `/cancel`

A successful model reply on screen requires the live checklist. The PHPUnit suite already covers that reply with a fake RunPod response.

## Tests

```powershell
php vendor/bin/phpunit
```

The suite sets a fake RunPod key and calls `Http::preventStrayRequests()`, so a missing fake fails the test instead of calling the network.
