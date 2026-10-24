# First controlled smoke test

Status: **Prepared**. The scripts have not been run. No request was sent while these files were written. A reply has not been captured, and the model is not confirmed working.

Endpoint id: `n3bvj9t7q1tvam`

Image: `runpod/worker-v1-vllm:v2.30.0`

Served model name: `kimi-vl`

## Request shape

`smoke-test-request.json` is the body for one `POST /run`. The worker at git tag `v2.30.0` (`src/handler.py`) accepts this shape and forwards `openai_input` to local `/v1/chat/completions`:

```json
{
  "input": {
    "openai_route": "/v1/chat/completions",
    "openai_input": { "model": "kimi-vl", "messages": [], "stream": false }
  }
}
```

RunPod then returns a job id. The result is `GET /status/{job_id}`. Cancel is `POST /cancel/{job_id}`. Those three paths are the queue URLs in RunPod's endpoint API. The header is `Authorization: Bearer` plus the API key.

`stream` stays false. `max_tokens` is 32. The script posts `/run` once. It never calls `/retry` and never posts `/run` again after a failure.

## Before the command

1. On the endpoint page, read the host CUDA version. This image is CUDA 13.0.2 and requires a host that reports CUDA 13.0 or newer. If the page shows CUDA 12.8 only, do not run the script.
2. Read the GPU name and the price per second. Stop if it is not the 96 GB row you intend to pay for.
3. Confirm active workers are 0 and max workers are 1.
4. Set the container disk to at least 150 GB. Do not attach a network volume. The reason is in [docs/serverless-smoke-test-failure.md](../../docs/serverless-smoke-test-failure.md).
5. Open the Billing page and note the current balance. USD 5 is the test ceiling. RunPod does not enforce it. The account hourly limit does not enforce it either.
6. Set the key and the new endpoint id in this shell only:

```powershell
$env:RUNPOD_API_KEY = "paste-the-key-here"
$env:RUNPOD_ENDPOINT_ID = "new-endpoint-id"
```

Do not put the key in a file in this repository. Do not echo the variable.

## Run one job

From `deployment/serverless`:

```powershell
powershell -NoProfile -File .\invoke-smoke-test.ps1
```

The script prints the Job ID on its own line as soon as `POST /run` returns one. It then prints elapsed seconds, status, `delayTime`, and `executionTime`. It polls every 10 seconds with a GET that does not send a body. The deadline is 600 seconds. At that deadline it calls `cancel-smoke-test.ps1` once for that job and does not submit another one. The job id is written to `last-job-id.txt`, which contains no API key.

Set `RUNPOD_ENDPOINT_ID` in the shell. The script no longer defaults to `n3bvj9t7q1tvam`, because that endpoint was deleted.

Ctrl+C, or any other interruption of the poll script, stops the local script only. The script then says the remote job was not cancelled. It does not claim a cancellation it did not finish. A cancellation response does not mean the GPU worker has stopped.

Leave the Billing page open while it runs.

## Cancel

This does not submit a new job.

```powershell
powershell -NoProfile -File .\cancel-smoke-test.ps1 -JobId JOB_ID
```

If `-JobId` is omitted, the script reads `last-job-id.txt`.

## What the script does with each result

| Status | Script action |
| --- | --- |
| `IN_QUEUE`, `IN_PROGRESS` | Keep polling until the 10-minute deadline. |
| `COMPLETED` with reply text | Print the output and exit 0. This is not a pass until the log checks below succeed. |
| `COMPLETED` with an `error` object, or with no reply text | Exit 3. Do not run the script again. |
| `FAILED` | Exit 4. Do not run the script again. Do not click Retry. |
| `CANCELLED` | Exit 5. No new job is submitted. The worker may still be running. |
| `TIMED_OUT` | Exit 6. Do not run the script again. Do not click Retry. |
| 10-minute deadline | Call cancel once, then exit 2. Do not resubmit. The worker may still be running. |

`delayTime` and `executionTime` are milliseconds from RunPod. They are not the billed amount.

## Logs, version, and revision

Endpoint logs stay available from the endpoint **Logs** tab. Filter that tab by the job id. Worker logs are on the **Workers** tab, then the worker. Worker logs are removed when that worker stops, so read or download them during the run. The vLLM process log is echoed to the worker stdout.

Pass only if the log shows all of these:

- A `Starting vLLM` line whose command includes `--revision 398eede0903cd983a2bfa0cc634e9ac1d843f375`.
- vLLM version `0.32.0`.
- No line that says `Dropping MODEL_REVISION`. That line means the worker abandoned the pin.
- `vLLM is healthy` before the reply.

If the GPU name and VRAM are not printed, write "not printed". Do not treat a reply as proof of a 96 GB Blackwell GPU.

## Emergency procedure

RunPod will not stop this test at USD 5. Cancellation does not necessarily terminate the GPU worker immediately. Do these four checks in order:

1. Cancel the job. If the 10-minute deadline already called cancel once, do not send another job. If you stopped the script with Ctrl+C, the remote job is still active until you run `cancel-smoke-test.ps1 -JobId JOB_ID`.
2. Prevent new workers from starting. Set active workers to 0 and max workers to 0.
3. Check the Workers tab. Confirm no worker is running, starting, or throttled. A worker can remain after the job status says `CANCELLED`.
4. Verify Billing. Refresh the Billing page and record the amount. If the test window is at or above USD 5, delete the endpoint.

Do not use the console Retry button and do not run `invoke-smoke-test.ps1` again. Record the worker seconds and the log lines for version and revision.

At the published 96 GB rate of USD 0.00111 per second, one hour is about USD 4.00. Ten minutes is about USD 0.67. Those figures are estimates. The Billing page is the charge.

## Local check

`validate-config.ps1` parses `smoke-test-request.json` and checks the route, model, and token cap. It does not call RunPod. Parsing the PowerShell files with the language parser also does not call RunPod.
