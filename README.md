# Kimi Self-Hosted AI POC

Proof of concept for running an official Moonshot AI Kimi model on a rented GPU server and talking to it from a single Laravel application.

This repository contains the Laravel 13 and React chat application, the planning documents, and the prepared RunPod files. Model weights are not in this repository. The application talks to RunPod only when `RUNPOD_API_KEY` and `RUNPOD_ENDPOINT_ID` are set in the uncommitted `.env`. The test suite fakes those calls.

## Project overview

The POC demonstrates that chat responses are produced by `moonshotai/Kimi-VL-A3B-Instruct` running on infrastructure we rent and control. The browser talks only to the Laravel application. Laravel talks to a vLLM OpenAI-compatible server on the GPU host. Moonshot’s hosted API, and any other third-party inference API, are out of the design.

The application is one Laravel 13 project. React is rendered through Inertia.js, with Vite building the frontend assets. There is no separate frontend project and no separate Node.js backend.

The current release is a POC, not a production product. The service boundaries are chosen so a later MVP can add a REST API, MySQL, and authentication without replacing the chat and inference services.

## Business objective

Show a credible self-hosting path for an official Kimi checkpoint:

- The weights are the published Moonshot checkpoint, loaded on a server we rent.
- A web chat UI sends Arabic and English text through Laravel.
- Responses, failures, and latency are visible in the UI.
- A short technical report records how the model was served, what it cost, and what was measured.

The objective is evidence of self-hosting, not a multi-user product.

## POC scope

In scope for the completed POC:

- A React chat screen inside the Laravel application.
- Arabic and English text messages, with one optional JPEG, PNG, or WebP image on the same turn.
- Multi-turn context held in the Laravel file session for the current browser session.
- Loading and error states, including inference timeouts.
- A protected connection from the developer machine to vLLM.
- A record of response latency and GPU memory during the test window.
- A technical POC report.

Out of scope:

- Accounts, login, and Laravel Sanctum.
- MySQL or any other application database.
- Permanent conversation history.
- An admin dashboard.
- OCR, video, and more than one image in a message.
- Fine-tuning, training, public deployment, billing, and subscriptions.

Detailed requirements are in [docs/requirements.md](docs/requirements.md).

## Technology stack

| Area | Choice | Status |
| --- | --- | --- |
| Application | Laravel 13, one project | Decision. Laravel 13.0.0 was published on 17 March 2026 and requires PHP 8.3 or newer. |
| UI | React through Inertia.js, assets built by Vite | Decision. JavaScript/JSX, matching the Laravel React starter. |
| PHP | 8.3 or later | Decision. Matches the Laravel 13 requirement. |
| Sessions and cache | Laravel file drivers | Decision for the POC. |
| Database | None | Decision. The skeleton may contain a `database/` directory later; the POC must not configure or require MySQL. |
| Model | `moonshotai/Kimi-VL-A3B-Instruct` | Decision, subject to the Phase 2 compatibility gate. |
| Inference engine | vLLM | Decision, subject to a pinned version that Phase 2 confirms. |
| GPU host | RunPod Serverless queue endpoint `f6icz11anfucte` | Operator-reported smoke test passed. The Laravel app has not called it. Workers were reported scaled to zero. |
| Development OS | Windows, Cursor, Git | Decision. |

Provider URLs, the model id, timeouts, and any inference credential belong in environment variables. They are documented in [docs/architecture.md](docs/architecture.md). No credentials are stored in this repository.

## High-level architecture

```text
Browser
  -> Laravel + React/Inertia
    -> ChatController
      -> ChatService
        -> KimiService
          -> RunPod Serverless queue
            -> vLLM on 127.0.0.1 inside the worker
              -> Kimi-VL-A3B-Instruct weights
```

`ChatController` stays thin. `ChatService` owns the temporary transcript. `KimiService` is the only class that calls RunPod. The browser never receives the API key. The queue request uses `POST /run`, then `GET /status/{job_id}`, with `input.openai_route` set to `/v1/chat/completions`. Cancel is `POST /cancel/{job_id}`.

The Pod SSH tunnel remains documented as the unused fallback. The running application path is the authenticated queue described in [docs/architecture.md](docs/architecture.md).

## Future expansion

The POC does not build these. The service split is what keeps them inexpensive later:

- MySQL persistence for users, conversations, and messages.
- REST routes and API controllers for external and mobile clients.
- Laravel Sanctum, if those clients need authentication.
- The same `ChatService` and `KimiService` behind both Inertia and API controllers.

See [docs/future-expansion.md](docs/future-expansion.md).

## Budget constraints

The hard ceiling for all cloud cost in this POC is **below 20 OMR**.

The Central Bank of Oman publishes a fixed peg of **USD 2.6008 per Omani rial** ([CBO fixed peg](https://cbo.gov.om/Pages/FixedPeg.aspx)). At that peg, 20 OMR is about **USD 52.02**. That figure is a planning conversion, not a RunPod quote. Card foreign-exchange fees and any tax charged by the provider reduce the usable amount and must be included before spend is approved.

No paid resource may be created without an explicit approval that names the GPU, disk, maximum hours, and maximum spend. A running or stopped pod can continue to charge for compute or disk, so every approval includes a shutdown check.

GPU hourly prices from third-party listings are not treated as current RunPod prices. Phase 3 reads RunPod’s own pricing before any SKU is proposed for approval.

## Project status

| Item | State |
| --- | --- |
| Phase | Application implemented locally. Live app-to-endpoint test not run |
| Application | Laravel 13.38, Inertia React, file session, file cache |
| Tests | PHPUnit, RunPod HTTP client faked. No GPU required |
| Model | `moonshotai/Kimi-VL-A3B-Instruct` |
| Reported worker | `runpod/worker-v1-vllm:v2.30.0`, vLLM 0.32.0. The live endpoint is still text-only via `VLLM_EXTRA_ARGS=--language-model-only`. The prepared image setting, not applied, is `LIMIT_MM_PER_PROMPT=image=1` with that flag removed |
| Reported endpoint | `f6icz11anfucte`, served model `kimi-vl`, workers scaled to zero after the operator's smoke test |
| Revision pin | `398eede0903cd983a2bfa0cc634e9ac1d843f375`. A completed `/run` does not by itself prove this revision was loaded |
| Spend from this application | None. Do not send a live message without approval |

### Run it locally

PHP 8.3 or newer and Node.js are required. No database is required.

```powershell
copy .env.example .env
php artisan key:generate
npm install
npm run build
php artisan serve
```

Leave `RUNPOD_API_KEY` empty until you explicitly approve a live call. With the key empty, the page loads and will not contact RunPod. Details are in [docs/local-setup.md](docs/local-setup.md). Deployment notes are in [docs/deployment.md](docs/deployment.md). The live test checklist is [docs/live-integration-checklist.md](docs/live-integration-checklist.md).

### Documents

| Document | Purpose |
| --- | --- |
| [docs/requirements.md](docs/requirements.md) | Functional and non-functional requirements, scope, acceptance criteria |
| [docs/architecture.md](docs/architecture.md) | Runtime design and proposed Laravel layout |
| [docs/implementation-plan.md](docs/implementation-plan.md) | Phases 1–10, gates, risks, and budget checkpoints |
| [docs/model-compatibility-checklist.md](docs/model-compatibility-checklist.md) | What must be true before a GPU is rented |
| [docs/model-compatibility-findings.md](docs/model-compatibility-findings.md) | Phase 2 evidence and the conditional-go recommendation |
| [docs/gpu-pricing-analysis.md](docs/gpu-pricing-analysis.md) | Phase 3 advertised prices, session math, and the rental conditions |
| [docs/rtx-pro-6000-deployment-review.md](docs/rtx-pro-6000-deployment-review.md) | Read-only image, CUDA, and launch review for the RTX PRO 6000 pod. Not a deploy approval |
| [docs/runpod-ssh-readiness.md](docs/runpod-ssh-readiness.md) | SSH startup command, tunnel steps, and the checks still open. Prepared, not deployed |
| [deployment/runpod-start-command.json](deployment/runpod-start-command.json) | JSON to paste into RunPod’s Container start command. Not yet pasted |
| [docs/runpod-serverless-feasibility.md](docs/runpod-serverless-feasibility.md) | Whether Serverless can run this checkpoint. Evaluation only |
| [docs/runpod-serverless-architecture.md](docs/runpod-serverless-architecture.md) | Proposed Laravel-to-endpoint flow. Not implemented |
| [docs/runpod-serverless-cost-analysis.md](docs/runpod-serverless-cost-analysis.md) | Serverless rates and a sub-5 OMR test cap |
| [docs/runpod-serverless-migration-plan.md](docs/runpod-serverless-migration-plan.md) | Blockers and the minimum test. No endpoint created |
| [deployment/serverless/README.md](deployment/serverless/README.md) | Prepared Serverless worker files, cost controls, and rollback |
| [deployment/serverless/smoke-test.md](deployment/serverless/smoke-test.md) | One-job smoke test and the manual USD 5 stop. Not executed |
| [docs/serverless-first-test-plan.md](docs/serverless-first-test-plan.md) | First paid smoke test. The first attempt failed |
| [docs/serverless-smoke-test-failure.md](docs/serverless-smoke-test-failure.md) | Disk-check finding, poll fix, and the next disk size |
| [docs/local-setup.md](docs/local-setup.md) | How to run the chat on this workstation without a GPU |
| [docs/deployment.md](docs/deployment.md) | What a later deploy would require. Not an approval to deploy |
| [docs/live-integration-checklist.md](docs/live-integration-checklist.md) | The checks before the first real message from the app |
| [docs/future-expansion.md](docs/future-expansion.md) | MySQL, REST, mobile clients, and authentication |

## Claim labels

Technical statements in these documents use one of three labels:

- **VERIFIED.** Supported by an official document or a published file, read on 21 October 2026.
- **ESTIMATED.** Calculated from those sources and not measured on our hardware.
- **UNKNOWN.** Requires a real GPU boot, a driver check, or a price quote.

## Next step

Do not send a chat message from the app until the checklist in [docs/live-integration-checklist.md](docs/live-integration-checklist.md) is approved. The endpoint id to use, when that approval exists, is `f6icz11anfucte`. Do not call the deleted endpoint `n3bvj9t7q1tvam`. Do not change workers, GPU settings, or the container disk from this repository.
