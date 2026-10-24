# Sample response

This describes the schema. It is not a captured completion. The model has not run.

## Submit

`POST https://api.runpod.ai/v2/{endpoint_id}/run`

Header: `Authorization: Bearer {RUNPOD_API_KEY}`

Body: `sample-request.json`.

The handler in `src/handler.py` at tag `v2.30.0` accepts `input.openai_route` and `input.openai_input`, then POSTs `openai_input` to local `http://127.0.0.1:8000/v1/chat/completions`. `stream` must stay `false` for this proof of concept. A true value makes the handler yield raw server-sent events instead of one JSON object.

The operation reference shows this immediate response:

```json
{
  "id": "job-id",
  "status": "IN_QUEUE"
}
```

## Poll

`GET https://api.runpod.ai/v2/{endpoint_id}/status/{job_id}`

Same authorization header. No body.

Documented request states are `IN_QUEUE`, `IN_PROGRESS`, `COMPLETED`, `FAILED`, `CANCELLED`, and `TIMED_OUT`.

A completed status response includes `id`, `status`, `delayTime`, and `executionTime`. `delayTime` is the wait before execution, in milliseconds. `executionTime` is the worker time for the job, in milliseconds. Neither field is the whole cold start, and neither field is the invoice.

## Success output

On a non-streaming call the handler yields the JSON returned by vLLM. `main.py` sets `return_aggregate_stream` to true. The operation reference shows `output` as an object in one example and as an array in another. Whether this worker's single yielded object is wrapped in an array is **not confirmed**. The paid test must record the actual shape. Laravel must not assume one shape until that capture exists.

The yielded vLLM object is a chat completion. The fields the proof of concept needs are:

- `choices[0].message.content` — the reply text
- `choices[0].finish_reason`
- `usage.prompt_tokens`, `usage.completion_tokens`, and `usage.total_tokens` when vLLM includes them
- `model` — expect `kimi-vl` because `OPENAI_SERVED_MODEL_NAME_OVERRIDE` is set

An illustrative envelope, not a model output:

```json
{
  "id": "job-id",
  "status": "COMPLETED",
  "delayTime": 0,
  "executionTime": 0,
  "output": {
    "model": "kimi-vl",
    "choices": [
      {
        "message": {
          "role": "assistant",
          "content": "text from the model"
        },
        "finish_reason": "stop"
      }
    ],
    "usage": {
      "prompt_tokens": 0,
      "completion_tokens": 0,
      "total_tokens": 0
    }
  }
}
```

## Error output

The handler yields this object instead of raising:

```json
{
  "error": {
    "message": "cause from the worker or from vLLM",
    "type": "worker_error",
    "code": null
  }
}
```

`type` is `startup_error` when vLLM could not start and the worker stayed up to report the cause. It is `worker_error` for a bad body, a dead vLLM process, an HTTP error from vLLM, or a connection error.

Which platform status wraps that object, `FAILED` or `COMPLETED`, is **not confirmed** in the handler source. The paid test must record it. Laravel treats either status as a failure when `error` is present, and treats `FAILED`, `CANCELLED`, and `TIMED_OUT` as failures even when `output` is empty.
