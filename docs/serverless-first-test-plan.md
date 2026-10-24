# Serverless first test plan

Status of this plan: **Prepared**. `deployment/serverless/validate-config.ps1` printed `VALIDATION PASSED` on 21 October 2026. That result is **Locally validated**. Creating the endpoint, loading the model, and measuring cost are **Requires paid deployment test**. Nothing in this plan is **Confirmed working**.

Do not start this plan until there is an explicit approval to spend at most USD 5. Do not create the endpoint before that approval.

## Labels

| Label | Meaning in this plan |
| --- | --- |
| Prepared | Written in the repository. |
| Locally validated | A local script or parser accepted the file. No cloud call. |
| Requires paid deployment test | Can be answered only by a billed worker. |
| Confirmed working | Observed on that worker and recorded. None of the rows below have this label. |

| Check | Label |
| --- | --- |
| Official worker image selected | Prepared |
| Environment names match tag `v2.30.0` | Locally validated |
| Sample request JSON parses | Locally validated |
| Scale settings are 0 and 1 | Locally validated |
| GPU model and VRAM | Requires paid deployment test |
| Worker process starts | Requires paid deployment test |
| vLLM reports `0.32.0` | Requires paid deployment test |
| Exact revision loads | Requires paid deployment test |
| Text-only generation | Requires paid deployment test |
| Cold-start duration, latency, billed time, and cost | Requires paid deployment test |
| Model serves chat | Not confirmed working |

## Cost lock

- Active workers: 0. Max workers: 1. No network volume. No second GPU.
- GPU row: the 96 GB row only if the form shows it. The published rate used for planning is `6000s PRO` at USD 0.00111 per second. If the form price is higher, stop.
- `VLLM_STARTUP_TIMEOUT=900`. Execution timeout 300 seconds. Idle timeout 60 seconds if accepted, otherwise 5.
- Two worker starts maximum. Then delete the endpoint even if the second start fails.
- Estimated cost of one 15-minute cold start, a 30-second reply, and 60 seconds of idle time: about USD 1.10 (`990 × 0.00111`). That estimate is not an invoice.
- Hard stop: USD 5 on the Billing page, or two starts, whichever comes first.
- Max workers does not enforce the USD 5 cap. One forgotten worker is about USD 4.00 per hour. The documented account limit of USD 80 per hour does not enforce it either.

## Before the endpoint exists

1. Run `powershell -NoProfile -File deployment/serverless/validate-config.ps1` and keep the `VALIDATION PASSED` line.
2. In the RunPod console, confirm the image tag `runpod/worker-v1-vllm:v2.30.0` is selectable. Do not type `latest`.
3. Copy `worker.env.example` into the endpoint environment. Do not add `HF_TOKEN`.
4. Set the scale fields from `endpoint-settings.json`.
5. Save the form. Do not send a request yet. Read the saved GPU name, price per second, active workers, and max workers back from the page. If active workers is not 0 or max workers is not 1, delete the endpoint and stop.

## Smoke test

Send one request, from an operator shell, not from Laravel. Laravel is not initialized. Use `deployment/serverless/invoke-smoke-test.ps1`, which posts `smoke-test-request.json` to `POST /v2/n3bvj9t7q1tvam/run` and polls `/status/{job_id}`. Keep the API key in `RUNPOD_API_KEY` for that shell only. The script does not retry. The manual stop is in `deployment/serverless/smoke-test.md`. Do not run it until the spend approval is explicit.

Record these observations. Write "not printed" when a log line is absent. Do not fill gaps from this document.

| Observation | Where it comes from | Pass condition |
| --- | --- | --- |
| GPU name and VRAM | Worker log | The line names the GPU and shows about 96 GB. If no line prints the GPU, the result is unknown, not a pass. |
| Worker started | Workers tab and log line `Starting vLLM` | A worker reached running, then the log shows the vLLM command. |
| vLLM version | The same log | The banner or command path shows `0.32.0`. Any other version is a stop. |
| Revision | The same log | The command contains `--revision 398eede0903cd983a2bfa0cc634e9ac1d843f375`. The log does not contain `Dropping MODEL_REVISION`. |
| Text reply | Status JSON | `COMPLETED`, with non-empty `choices[0].message.content`, and no `error` object. Record whether `output` was an object or an array. |
| Cold start | Clock from submit until the worker log says `vLLM is healthy`, plus `delayTime` | Record the number. There is no expected value. |
| Request latency | `executionTime` and the clock around the status change to `COMPLETED` | Record both. |
| Billed worker time | Workers tab or Billing for that worker | Record seconds. |
| Actual cost | Billing page for the test window | Record USD. Stop if it reaches 5. |

A reply after `Dropping MODEL_REVISION` is a failed test. The worker would have retried the current branch instead of the pinned commit.

An out-of-memory retry that logs `MAX_NUM_BATCHED_TOKENS=8192` is not a clean first boot. Record it and stop after that attempt.

## After the test

1. Set max workers to 0 if the endpoint still exists.
2. Confirm no worker is running or throttled.
3. Delete the endpoint.
4. Confirm no network volume was created.
5. Record the final Billing amount.
6. Do not leave the endpoint up overnight. FlashBoot and the seven-day max-worker reduction do not replace deletion.

The Pod files under `deployment/runpod-start-command.json` and `deployment/runpod-start.sh` are not used in this test and are not changed by it.

## Still unknown until the paid test

- Whether the published amd64 image bytes match the git tag Dockerfile. The tag exists. The amd64 image record was last pushed on 18 October 2026 and the tag index was updated on 21 October 2026.
- Whether the 96 GB form row is RTX PRO 6000 Blackwell.
- Cold-start duration and whether the weight download is billed.
- Whether `output` is a JSON object or a one-element array.
- Whether a handler `error` object arrives as `FAILED` or as `COMPLETED`.
- Whether the form accepts an idle timeout of 60 seconds.
- Tax on the USD amount.
