# RunPod Serverless architecture

Status: proposed design, 21 October 2026. Not implemented. Laravel has not been initialized. No endpoint exists.

This design is an alternative to the SSH Pod in [architecture.md](architecture.md). It does not replace that document until the feasibility conditions are accepted.

## Request flow

```text
Browser
  -> Laravel + React/Inertia  (CSRF, no RunPod key)
    -> ChatController
      -> ChatService
        -> KimiService
          -> HTTPS POST to the RunPod queue endpoint
            -> worker on a rented GPU
              -> vLLM on 127.0.0.1 inside the worker
                -> Kimi-VL-A3B-Instruct at the pinned revision
```

The browser posts to Laravel only. `KimiService` is still the only class that knows the inference URL. The RunPod API key stays in Laravel’s environment and is never rendered into the page, the JavaScript bundle, or the logs.

**VERIFIED** worker shape, from the official worker README: a queue endpoint’s OpenAI-compatible base URL is

```text
https://api.runpod.ai/v2/<ENDPOINT_ID>/openai/v1
```

The documented call sends `Authorization: Bearer <RUNPOD_API_KEY>`. The key is a RunPod key, not a Moonshot key.

Inside the worker, vLLM is bound to loopback by the worker itself. `--host` cannot be overridden through the worker’s environment. That is the private hop. The public hop is RunPod’s API, which is why this design does not satisfy NFR-6 as written. NFR-6 requires an SSH tunnel and rejects an unauthenticated public inference URL. This URL is authenticated. It is still public. Using it means amending NFR-6 on purpose.

## What stays the same

- One Laravel 13 application. React through Inertia. No second frontend.
- `POST /chat` and `POST /chat/clear`. File session transcript. No MySQL.
- Text only. `--language-model-only` is still required, passed as `VLLM_EXTRA_ARGS` because the worker allowlist does not include that flag.
- Served model name `kimi-vl`.
- Temperature 0.2 and the short context cap from the Phase 2 findings.
- No image upload.

## Endpoint settings to use if a test is approved

| Setting | Value | Why |
| --- | --- | --- |
| Endpoint type | Queue | Jobs wait through a cold start. The load-balancer type returns an error until a worker is healthy and is documented to expose HTTP |
| Active workers | 0 | **VERIFIED** default. Scale to zero. Active workers of 1 or more bill continuously |
| Max workers | 1 | **VERIFIED** that the setting exists. The documented default is 3, so it must be changed. One worker matches one interactive user |
| GPUs per worker | 1 | One GPU is enough for this checkpoint’s shape |
| GPU | The 96 GB row labeled `6000s PRO`, and no second priority | A second priority could land on a smaller GPU. Whether this row is the RTX PRO 6000 Blackwell is **UNKNOWN** |
| Idle timeout | 60 seconds if the form accepts it | **VERIFIED** default is 5 seconds. 60 seconds is a design choice so a quick follow-up does not pay another cold start. The allowed maximum was not published |
| Execution timeout | 300 seconds | **VERIFIED** default is 600 seconds, range 5 seconds to 7 days. 300 seconds caps a stuck generation. It does not, on the published description, cap the cold start that happens before the handler is ready |
| FlashBoot | Leave the default, which is enabled | **VERIFIED** as a default. It does not remove the first cold start |
| Network volume | None on the first test | Cached Hugging Face models are the documented preference. A volume restricts the data center and bills until it is deleted |
| HTTP and TCP ports | None | Do not expose port 8000 |

Environment for that worker, only after the image is confirmed to be vLLM v0.32.0:

```text
MODEL_NAME=moonshotai/Kimi-VL-A3B-Instruct
MODEL_REVISION=398eede0903cd983a2bfa0cc634e9ac1d843f375
OPENAI_SERVED_MODEL_NAME_OVERRIDE=kimi-vl
TRUST_REMOTE_CODE=true
ENFORCE_EAGER=true
MAX_MODEL_LEN=2048
MAX_NUM_SEQS=1
GPU_MEMORY_UTILIZATION=0.90
VLLM_EXTRA_ARGS=--language-model-only
VLLM_STARTUP_TIMEOUT=900
```

`VLLM_STARTUP_TIMEOUT` defaults to 1800 seconds in the worker docs. 900 seconds is a budget cap for a failed boot, not a measured load time. `HF_TOKEN` is unnecessary for this public model and must not be committed.

The Pod command in `deployment/runpod-start-command.json` starts `sshd` and then `vllm serve`. Serverless queue workers do not use that command. Leave the file in place for the Pod fallback.

## Cold start and cache

**VERIFIED** from the worker lifecycle and Serverless pricing pages:

1. While scaled down, RunPod may pull the image and download a cached model. That preparation is described as normally not billed.
2. Billing starts when a request causes a worker to start, and continues until the handler is ready. That interval is the cold start. For this model it includes moving about 30.57 GiB of weights into GPU memory.
3. The job then runs.
4. After the job, the worker stays up for the idle timeout and is still billed.
5. It then scales to zero. A scaled-down worker is not billed for compute.

How long step 2 takes for this checkpoint is **UNKNOWN**. The planning Laravel timeout of 120 seconds is shorter than a plausible cold start. The first test should be a direct client with a long timeout. Laravel’s timeout stays a later decision, after a measured start.

An endpoint with no requests is reduced automatically: max workers becomes 2 after 3 days, and 0 after 7 days. That does not replace deleting the endpoint. An active-worker count above 0 is not covered by that scale-down.

## Laravel change, later

`KimiService` would send the same chat-completions body it already plans to send. Only the base URL and the credential change:

- `KIMI_BASE_URL=https://api.runpod.ai/v2/<ENDPOINT_ID>/openai/v1`
- `KIMI_API_KEY` set to the RunPod key, server-side only.

No controller, session, or React change is required for that swap. This evaluation does not create those classes.

GPU memory evidence for FR-16 is **UNKNOWN** on Serverless, because there is no SSH session in which to run `nvidia-smi`. A Pod still provides that sample. A Serverless test would need another source, such as worker logs, before it can meet FR-16.

## Sources

| Source | What it settled |
| --- | --- |
| [worker-vllm README](https://github.com/runpod-workers/worker-vllm) | OpenAI base URL, bearer key, load-balancer alternative |
| [Endpoint settings](https://docs.runpod.io/serverless/endpoints/endpoint-configurations) | Active workers, max workers, idle timeout, execution timeout, FlashBoot, 7-day scale-down |
| [Workers overview](https://docs.runpod.io/serverless/workers/overview) | Cold start, idle billing, cached models, network volumes |
| [architecture.md](architecture.md) | Current SSH design that this document does not replace |
