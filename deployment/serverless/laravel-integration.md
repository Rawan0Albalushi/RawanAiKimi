# Laravel integration design

Status: **Implemented** on 22 October 2026. The classes below exist. Tests fake the queue. The application has not called the live endpoint.

## Path

```text
Browser
  -> Inertia React page
  -> Laravel POST /chat
  -> KimiService
  -> POST https://api.runpod.ai/v2/{endpoint_id}/run
  -> poll GET /status/{job_id}
  -> vLLM on the worker, loopback only
  -> moonshotai/Kimi-VL-A3B-Instruct at the pinned revision
```

The browser never receives the RunPod API key, the endpoint URL with the key, or a direct worker address. `KimiService` is the only class that calls RunPod. The key and endpoint id come from Laravel environment variables `RUNPOD_API_KEY` and `RUNPOD_ENDPOINT_ID`. They are not shared to Inertia props.

This design does not change the private-inference requirement in the current requirements. A later approval has to record that the proof of concept calls RunPod's authenticated queue instead of an SSH tunnel. Until that approval, this document is a design only.

## Submit

`POST /chat` keeps the existing planned contract: one user message, session transcript, no account login in the proof of concept.

`KimiService` sends `sample-request.json` with the transcript mapped into `input.openai_input.messages`. The model field stays `kimi-vl`. `stream` stays false. `temperature` stays 0.2. The application `max_tokens` stays the planned 512. The smoke-test file uses 64 only to keep the first paid call short.

The submit call uses a short HTTP timeout, about 20 seconds. It only needs to receive the job id. A successful submit returns `{ "id", "status": "IN_QUEUE" }`.

## Poll

Laravel polls `GET /status/{id}` about every 2 seconds. The poll budget is 1020 seconds: 900 seconds for the worker health wait plus 120 seconds for generation. The browser does not hold one Laravel request for the whole budget. `POST /chat` submits the job with a 20 second timeout and returns. The page posts to `POST /chat/poll` about every 2 seconds. Each poll performs one `GET /status/{job_id}` with a 15 second timeout. Laravel measures the 1020 second budget from the submit time and, on expiry, calls `POST /cancel/{job_id}` once. The planned 120 second single-request timeout is not used for this path. `POST /run` is not retried automatically. `/runsync` is not used.

User-facing status, mapped from the documented job states:

| Job state | Text on the page |
| --- | --- |
| `IN_QUEUE` | Starting the model. This can take several minutes the first time. |
| `IN_PROGRESS` | Generating a reply. |
| `COMPLETED` without an `error` object | Show `choices[0].message.content`. |
| `COMPLETED` with an `error` object | Show the error message and stop. |
| `FAILED`, `CANCELLED`, `TIMED_OUT` | Show a short failure and stop. |
| Poll budget exceeded | Show that the wait ended, and stop polling. |

If `output` is a one-element array, read the object inside it. If `output` is the object itself, read it directly. The paid test decides which shape is real. Until then the parser accepts both and rejects anything else.

Do not use `/runsync` for the first application path. Its default wait is 90 seconds and its result retention is 1 minute. A cold start can outlast that. `/run` keeps the result for 30 minutes.

## Failures

- Submit HTTP error or a missing job id: tell the user the request was not accepted. Do not retry automatically.
- Worker `startup_error`: show the message. Do not send another job. A second job can start another billed worker.
- vLLM HTTP error inside `error.message`: show it and stop.
- Empty `content`: treat it as a failed reply.
- Two worker starts in one test day, or an estimated charge approaching USD 5: stop sending jobs. The application cannot see the RunPod invoice, so the USD 5 stop for the first test is an operator action, not a Laravel counter.

## What this design does not do

- It does not stream tokens.
- It sends at most one image, and only on the turn that uploaded it. Laravel re-encodes that file to a JPEG data URL. It does not forward a remote image URL. The live worker still rejects images until `VLLM_EXTRA_ARGS=--language-model-only` is removed and `LIMIT_MM_PER_PROMPT=image=1` is set. That environment change is not applied from this repository.
- It does not expose vLLM port 8000.
- It does not store the RunPod key in the browser, in git, or in a log line.
- It does not call the live endpoint from the test suite. The feature tests fake `/run`, `/status`, and `/cancel`.
