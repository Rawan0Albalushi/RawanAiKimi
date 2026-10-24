# First Serverless smoke test failure

Status: the endpoint named `hostile_crimson_minnow` was deleted after the test. Its id in the prepared script was `n3bvj9t7q1tvam`. The model has not been confirmed loaded. This analysis did not create an endpoint or send a request.

## What failed

Two separate failures were observed.

The worker logged a RunPod fitness-check failure and exited 1:

```text
Fitness check failed: _disk_check | RuntimeError: Insufficient ...
Worker is unhealthy, exiting.
```

The full vLLM startup message was truncated, so the vLLM cause is not known. GPU and system-memory fitness checks passed. The host GPU was reported as RTX PRO 6000 with CUDA 13.2.

The local poll then failed on `GET /status` with `Cannot send a content-body with this verb-type.` That is a Windows PowerShell 5.1 defect in the script, not a RunPod response.

## Disk check

Verified in `runpod/serverless/modules/rp_system_fitness.py` at tag `v1.12.0`. The worker image `v2.30.0` installs `runpod~=1.12.0`.

The registered function is `_disk_check`. It calls `shutil.disk_usage("/")` and fails when free space is under `RUNPOD_MIN_DISK_PERCENT` of the total size. The default is `10.0`. The exception text begins `Insufficient disk space:` and includes the free GB, the free percent, and the required percent. A failed check logs `Worker is unhealthy, exiting.` and the process exits 1. RunPod then treats the worker as unhealthy and can start a replacement.

The check is not a model-size check. It does not know that the Kimi checkpoint is 32,822,309,117 bytes (about 30.57 GiB). It only measures whatever filesystem is mounted at `/` at the moment `runpod.serverless.start()` runs.

In this worker, that call happens after the vLLM health wait, or after vLLM has already failed in a way the wrapper classifies and stays up to report. Reaching `_disk_check` means the process got that far. An unrecognized vLLM crash that calls `sys.exit(1)` before `serverless.start()` would not print `Fitness check failed`.

`HF_HOME` in this image is `/runpod-volume/huggingface-cache/hub`. Without a network volume, that path is a directory on the container disk, which is `/`. The weight download therefore consumes the same disk the fitness check measures. The container disk size used for this test was not recorded. Official defaults that were not confirmed for this endpoint are 50 GB on the template API and 64 GB for Flash GPU endpoints.

Do not set `RUNPOD_SKIP_AUTO_SYSTEM_CHECKS` or lower `RUNPOD_MIN_DISK_PERCENT` to make this pass. A worker that starts on a disk too small for the checkpoint still fails while downloading, and an unhealthy exit can restart the worker and keep billing.

## Other vLLM causes that remain possible

The truncated startup line was not recovered. The deleted endpoint's worker logs are not assumed to still exist. These are open, not ranked as the cause:

- The download ran out of space. This is consistent with the later disk check, and it is not proven.
- GPU memory was exhausted while loading or profiling the vision encoder. `--language-model-only` is passed, and it is still unknown whether that skips the vision-encoder profile that previously OOM'd on an 80 GB GPU. The wrapper retries one OOM by forcing eager mode and `MAX_NUM_BATCHED_TOKENS=8192`.
- The pinned revision was missing and the wrapper logged `Dropping MODEL_REVISION`. That line was not reported.
- A CUDA mismatch. This is unlikely: the host was CUDA 13.2, the image is CUDA 13.0.2, and the reported GPU fitness checks passed. The exact CUDA fitness line was not pasted.

## Polling defect

On Windows PowerShell 5.1, an omitted `[string]` parameter is an empty string, not `$null`. `Invoke-RunPod` treated that empty string as a body and attached it to `GET /status`. PowerShell then refused the request before it left the machine. Confirmed locally: an omitted string parameter has length 0. No status request was retried while fixing it.

`GET` and any empty body no longer set `ContentType` or `Body`. Only `POST`, `PUT`, and `PATCH` with a non-empty body do.

## Next configuration

Keep the image, model, revision, and the prepared environment variables. Change only the disk and the endpoint id.

| Setting | Next test |
| --- | --- |
| Image | `runpod/worker-v1-vllm:v2.30.0` |
| GPU | RTX PRO 6000, and only if the form shows host CUDA 13.0 or newer |
| Model environment | Unchanged `worker.env.example` |
| Container disk | 150 GB |
| Network volume | None |
| Active / max workers | 0 / 1 |
| Disk-check override | Do not set one |
| Endpoint id | A new id in `RUNPOD_ENDPOINT_ID`. Do not call `n3bvj9t7q1tvam`. |

150 GB is a sizing choice, not a measurement of this image unpacked. The compressed image is about 8.81 GiB. The unpacked image is larger and was not measured. The checkpoint is about 30.57 GiB, and the Hugging Face cache can use more than the weight bytes. 150 GB leaves room for the image, the checkpoint, cache files, and the 10 percent that `_disk_check` requires to remain free. If a later test still prints `Insufficient disk space`, record the free GB and free percent from that log before changing anything else.

Container disk is billed with the running worker and is removed when the worker stops. A network volume keeps billing until it is deleted, so it is not part of this test.

## Low-cost validation

1. Parse the scripts locally. Do not start a worker for that.
2. Create one endpoint only after approval. Do not send a request until the form shows 150 GB, active workers 0, max workers 1, and CUDA 13.0 or newer.
3. Run `invoke-smoke-test.ps1` once. The deadline is 10 minutes, then one cancel.
4. If the log says `Worker is unhealthy`, set max workers to 0 immediately. Cancelling the job does not stop a replacement worker.
5. Read Billing before a second attempt. One 10-minute run at the published 96 GB rate of USD 0.00111 per second is about USD 0.67. A restart loop can cost more while max workers stays 1. USD 5 is still not an automatic cap.

## Manual stop

1. Ctrl+C only stops the local poll. Cancel the job with `cancel-smoke-test.ps1` if the deadline did not already cancel it once.
2. Set active workers and max workers to 0 so no new worker starts.
3. Check the Workers tab until nothing is running, starting, or throttled.
4. Verify Billing. At or above USD 5, delete the endpoint.
5. Do not click Retry and do not run the invoke script again.
