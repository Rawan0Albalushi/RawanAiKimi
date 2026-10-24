# Worker configuration

Status: **Prepared**. The endpoint has not been created. The model has not loaded.

Selected image:

```text
runpod/worker-v1-vllm:v2.30.0
```

Do not use `latest` or a `test-*` tag.

## What was verified

| Item | Source | Result |
| --- | --- | --- |
| Docker tag exists | Docker Hub tag API, 21 October 2026 | `v2.30.0` is active. Tag updated 2026-10-21T16:43:48Z. |
| Worker base image | `Dockerfile` at git tag `v2.30.0` | `FROM vllm/vllm-openai:${VLLM_VERSION}` with `ARG VLLM_VERSION=v0.32.0`. The build exits if `vllm.__version__` is not `0.32.0`. |
| Entrypoint | same Dockerfile | `python3 /src/main.py`. This replaces the vLLM image entrypoint. |
| vLLM bind | `src/main.py` | Always `--host 127.0.0.1` and `--port` from `VLLM_PORT`, default `8000`. `HOST` and `PORT` are reserved and ignored by the argument builder. |
| Model arguments | `src/args_builder.py` | Environment names in `worker.env.example` map to the flags listed below. |
| Job body | `src/handler.py` | `input.openai_route` plus `input.openai_input` is proxied to the local vLLM server. |
| Queue operations | RunPod operation reference | `POST /run`, then `GET /status/{id}`. |

The amd64 image record behind the tag says `last_pushed` 18 October 2026, while the tag index was updated 21 October 2026. The Dockerfile at the git tag requires vLLM `0.32.0`. Whether the published amd64 bytes match that Dockerfile is **not confirmed** until the worker log is read. The first paid test must stop if the log does not show vLLM `0.32.0`.

## How each variable is used

| Variable | Read by | Becomes |
| --- | --- | --- |
| `MODEL_NAME` | alias in `args_builder.py` | `--model` |
| `MODEL_REVISION` | alias in `args_builder.py` | `--revision` |
| `OPENAI_SERVED_MODEL_NAME_OVERRIDE` | alias in `args_builder.py` | `--served-model-name` |
| `TRUST_REMOTE_CODE=true` | paired boolean | `--trust-remote-code` |
| `ENFORCE_EAGER=true` | paired boolean | `--enforce-eager` |
| `MAX_MODEL_LEN` | value flag | `--max-model-len` |
| `MAX_NUM_SEQS` | value flag | `--max-num-seqs` |
| `GPU_MEMORY_UTILIZATION` | value flag | `--gpu-memory-utilization` |
| `VLLM_EXTRA_ARGS` | appended last with `shlex.split` | `--language-model-only` |
| `MAX_CONCURRENCY` | `main.py`, passed to `runpod.serverless.start` | one in-flight job |
| `VLLM_STARTUP_TIMEOUT` | `main.py` health wait, seconds | 900 |

Accepted true values in the builder are `true`, `1`, `yes`, and `on`. This file uses `true`.

`--language-model-only` is not a named environment variable in `args_builder.py`. A variable named `LANGUAGE_MODEL_ONLY` would be ignored. It is passed only through `VLLM_EXTRA_ARGS`.

## Variables that must stay unset

- `HF_TOKEN`. The checkpoint is public. A token in the endpoint would be a secret.
- `HOST`, `PORT`, `API_KEY`, `MODEL`, `CONFIG`, `HELP`. The builder treats these as reserved and does not turn them into vLLM flags. `main.py` already pins the host.
- `VLLM_API_KEY`. It is not wired in this release.
- `HF_HOME`. The image sets it to `/runpod-volume/huggingface-cache/hub`. The Pod path `/workspace/hf_home` is not a Serverless path. Without a network volume, that cache is container disk and does not survive scale-to-zero.
- `LANGUAGE_MODEL_ONLY`, `BASE_PATH`. `BASE_PATH` is a build argument. Setting it at runtime does not move the cache directories.

## Endpoint form

Use a queue endpoint, not a load balancer. The load balancer publishes the worker and conflicts with the private-inference requirement.

Paste `endpoint-settings.json` as the scale settings. The key names `workersMin`, `workersMax`, and `idleTimeout` appear in RunPod's endpoint API example.

| Setting | Value | Why |
| --- | --- | --- |
| Active workers (`workersMin`) | 0 | Scale to zero. A value of 1 or more bills continuously. |
| Max workers (`workersMax`) | 1 | One GPU at a time. |
| Idle timeout | 60 seconds | Billed while the worker waits for another job. If the form rejects 60, use the documented default of 5 and record the saved value. |
| GPU | 96 GB row | The price table labels this `6000s PRO` at USD 0.00111 per second. That label is not documented as RTX PRO 6000 Blackwell. Select it only if the form shows that 96 GB row. Do not add a second GPU priority. |
| Network volume | none | A volume keeps billing until it is deleted and pins the endpoint to one data center. |
| Exposed HTTP or TCP | none | The worker talks to vLLM on loopback. Laravel calls the RunPod queue API. |
| FlashBoot | leave the default | It is documented as enabled by default. |
| Execution timeout | 300 seconds | Cap the running job. This is the job limit, not the time allowed to become healthy. |
| `VLLM_STARTUP_TIMEOUT` | 900 seconds | Health wait inside the container. A cold start longer than this fails before a request is served. |

The API string for the 96 GB GPU was not published in the worker source. Do not invent `gpuTypeIds`.

## Behavior that can change the first boot

`main.py` can relaunch vLLM once after a recognized failure:

- If the pinned revision is missing, it deletes `MODEL_REVISION` and retries the repository's current branch. A completion after that log line is the wrong checkpoint. Treat `Dropping MODEL_REVISION` as a failed test.
- If startup runs out of GPU memory, it forces `ENFORCE_EAGER=true` (already set) and may set `MAX_NUM_BATCHED_TOKENS=8192`, then tries once more. Record that retry. Do not treat it as a clean first boot.
- If the failure is not one of the recognized cases, the process exits and the platform may start another worker. That still bills. Stop the test after two worker starts.

Cold start, image pull, and model download duration are unknown until the paid test. Image pull and a platform cached-model download while scaled down are documented as normally not billed. Worker time from process start until stop is billed, including the health wait and the idle timeout.

## Rollback

1. Set max workers to 0 if the endpoint still exists.
2. Confirm the Workers tab shows no running or throttled worker.
3. Delete the endpoint.
4. Delete any network volume if one was created by mistake.
5. In Billing, record the charges for the test window.
6. Leave the Pod files in `deployment/` unchanged. They are the fallback and were not part of this configuration.
