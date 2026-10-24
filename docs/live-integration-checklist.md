# Live integration checklist

Do this only after an explicit approval to spend. One message from the app submits one RunPod job and can start a worker. The test suite does not do this. Nothing in the application work changed the endpoint.

Endpoint id: `f6icz11anfucte`

Served model name: `kimi-vl`

Reported image: `runpod/worker-v1-vllm:v2.30.0`

Reported vLLM: `0.32.0`

Reported weights: `moonshotai/Kimi-VL-A3B-Instruct` at `398eede0903cd983a2bfa0cc634e9ac1d843f375`

Text-only flag already on the worker: `--language-model-only`

A previous `/run` was reported `COMPLETED`. That result is not in this repository. Do not treat it as proof that this revision was the one loaded. The live chat test should record the worker log line that shows the revision actually loaded.

## Before the message

1. Confirm the approval names this endpoint and a spending ceiling. The earlier manual ceiling was USD 5. RunPod does not enforce it. Watch Billing.
2. Confirm active workers are 0 and max workers are still 1. Do not raise either.
3. Confirm the container disk, GPU row, and idle timeout are unchanged.
4. Put the API key only in the local `.env` as `RUNPOD_API_KEY`. Set `RUNPOD_ENDPOINT_ID=f6icz11anfucte`.
5. Confirm `KIMI_BASE_URL=https://api.runpod.ai/v2` and `KIMI_MODEL=kimi-vl`.
6. Start the app with `php artisan serve` and `npm run dev`, or use `npm run build` first.
7. Reload the page. The "not configured" note should be gone. Do not send yet if it is still visible.

## One English turn

1. Send one short English sentence.
2. Leave the page open. The status should move from "Starting the model" to "Generating a reply" when RunPod does. The first cold start can take several minutes.
3. If you need to stop, use **Cancel reply** before closing the tab. Closing the tab does not stop the worker.
4. On success, record the assistant text, the latency shown in the page, and the `kimi-vl` label under the reply.
5. From the worker log, record whether the loaded revision is `398eede0903cd983a2bfa0cc634e9ac1d843f375`. If the log does not show it, write that the revision was not confirmed.

## One Arabic turn

1. Send one short Arabic sentence in the same browser session.
2. Confirm the reply appears and that the English turn is still on screen.
3. Clear the chat. Confirm the transcript is empty.
4. Send one new sentence and confirm the cleared text is not required to understand it. This is a behavior check, not a claim about model quality.

## Stop

1. If a job is still running, cancel it in the page.
2. Confirm the endpoint returns to zero running workers. Idle timeout should do this. If a worker remains, stop it in the RunPod console. Do not delete the endpoint unless that was part of the approval.
3. Record the Billing delta.
4. Remove `RUNPOD_API_KEY` from `.env` when the session is over, or leave it only on this machine. Do not commit `.env`.

## Do not

- Do not click Retry in the RunPod console.
- Do not send a second job after `startup_error` until the chat is cleared and you have decided the failure is understood. The app blocks that second submit on purpose.
- Do not change the GPU, disk, or worker image as part of this test.
- Do not point `KIMI_BASE_URL` at another host. The application rejects that before the request.
