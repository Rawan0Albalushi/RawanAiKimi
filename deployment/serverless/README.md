# RunPod Serverless files

Status: **Application wired, live app call not made.** The first endpoint `hostile_crimson_minnow` (`n3bvj9t7q1tvam`) failed and was deleted. The operator later reported a completed `/run` smoke test on endpoint `f6icz11anfucte` for `moonshotai/Kimi-VL-A3B-Instruct` at revision `398eede0903cd983a2bfa0cc634e9ac1d843f375`, served as `kimi-vl`, on `runpod/worker-v1-vllm:v2.30.0` (vLLM 0.32.0) with `--language-model-only`. Workers were reported scaled to zero after that test. This repository does not contain that response body, and a completed job is not proof the pinned revision was loaded. The Laravel app targets that endpoint id only when `RUNPOD_ENDPOINT_ID` is set locally. It has not been called from this application. Do not change the worker settings from this repository.

The GPU Pod files in the parent `deployment/` directory stay as they are. This directory does not change them.

## Image

```text
runpod/worker-v1-vllm:v2.30.0
```

The git tag `v2.30.0` builds from `vllm/vllm-openai:v0.32.0`. Docker Hub has the tag. Confirm the running vLLM version from the worker log before accepting a reply. Details are in `worker-configuration.md`.

## Files

| File | Purpose |
| --- | --- |
| `worker.env.example` | Endpoint environment. No secrets. |
| `worker-configuration.md` | What each variable does, the endpoint form, and rollback. |
| `endpoint-settings.json` | Active workers 0, max workers 1, idle timeout 60 seconds. |
| `sample-request.json` | Text-only `POST /run` body. |
| `sample-response.md` | Queue envelope and the vLLM object the handler yields. |
| `laravel-integration.md` | Design for React to Laravel to the queue. Not implemented. |
| `validate-config.ps1` | Local checks for the files above. |
| `smoke-test-request.json` | One text-only `/run` body. `max_tokens` is 32. |
| `invoke-smoke-test.ps1` | Submits one job and polls `/status` with a body-less GET. Requires `RUNPOD_ENDPOINT_ID`. |
| `cancel-smoke-test.ps1` | Cancels the saved job. Does not submit another job. |
| `smoke-test.md` | Log checks, billing, and the manual USD 5 stop. |

## Prerequisites before any paid test

1. Explicit approval to spend at most USD 5.
2. A RunPod account with a payment method. Credits are non-refundable.
3. The endpoint form offers a 96 GB GPU row. The price table calls it `6000s PRO` at USD 0.00111 per second. Do not start if that row is absent or priced higher.
4. No network volume.
5. No second GPU priority.
6. The API key is created in the RunPod console and stored only in the operator's secret store. It is not committed.

## Cost controls

| Control | Setting |
| --- | --- |
| Active workers | 0 |
| Max workers | 1 |
| Scale to zero | Yes, because active workers stay 0 |
| Idle timeout | 60 seconds if the form accepts it, otherwise 5 |
| Startup cap | `VLLM_STARTUP_TIMEOUT=900` |
| Attempts | 2 worker starts, then delete the endpoint |
| Test budget | USD 5 |

Max workers is not a spending cap. One worker at the 96 GB rate is about USD 4.00 per hour (`0.00111 × 3600`). Two cold starts, or one worker left running, can pass USD 5 while max workers is still 1. The account spend limit documented by RunPod defaults to USD 80 per hour and does not enforce this test. Watch the Billing page during the test and delete the endpoint the same day.

An active-worker count of 1 for 4 hours is about USD 15.98. That configuration is out of scope.

Tax on the invoice is unknown. The USD 5 cap is the target for compute. If tax is extra, stop sooner.

## Rollback

Follow the rollback steps in `worker-configuration.md`. Deleting the endpoint ends worker compute. A network volume, if created, bills until it is deleted separately. The Pod startup files are not a Serverless rollback tool. Do not deploy the Pod as part of cleaning up a Serverless test.

## Local check

From this directory:

```powershell
powershell -NoProfile -File .\validate-config.ps1
```

A passing script means the files are internally consistent with the v2.30.0 source names. It does not mean the endpoint exists or the model loads.
