# RunPod Serverless migration plan

Status: plan only, 21 October 2026. Serverless is being evaluated. It is not deployed. Phase 4 has not started. The Pod startup files stay as they are.

## Recommendation

Do not migrate yet. The queue worker is the only Serverless shape worth a later test. The Pod remains the fallback because it matches the SSH requirement already written down.

A test is a **CONDITIONAL GO** only after a written approval and only inside the caps below. Loading this model is not treated as proven until that test prints a completion.

## Blockers

| Blocker | Why it stops a deploy today |
| --- | --- |
| Image version is not confirmed | Release v2.30.0 says vLLM v0.32.0. The configuration page still documents a `v0.23.0` build-arg default, and one Dockerfile view showed an older pin. The running image must be checked before a paid start |
| `--language-model-only` is not a worker env var | It has to be passed through `VLLM_EXTRA_ARGS`. Whether that string reaches vLLM is untested |
| RTX PRO 6000 Blackwell is not named on the Serverless price row | The 96 GB row is `6000s PRO` at USD 0.00111/second. Equating the two is not written in the docs |
| No boot of this checkpoint on compute capability 12.0 | Same unknown as the Pod review. An out-of-memory result ends the session |
| NFR-6 is an SSH tunnel | Serverless is authenticated HTTPS. The browser can still be kept away from the key, but the requirement text does not allow this URL until it is amended |
| FR-16 wants a GPU memory sample from the server | Serverless does not provide the SSH session used for `nvidia-smi` |
| Laravel’s 120-second planning timeout | A cold start can outlast it. The first test must not go through Laravel |
| Default max workers is 3 | Leaving the default can start more than one 96 GB worker |
| Active workers above 0 | Bills all day. Four hours on the 96 GB row is about USD 15.98, which is over 5 OMR |
| Account spend limit is USD 80/hour | It will not stop a small test. A person has to delete the endpoint |

## Minimum-cost test

Do not run this until the approval names the GPU row, the image tag, the caps, and the person who deletes the endpoint.

| Cap | Value |
| --- | --- |
| Spend | USD 5, about 1.92 OMR, and still below 5 OMR |
| Attempts | Two worker starts. A third start needs a new approval |
| Workers | Active 0, maximum 1, one GPU |
| Startup wait | `VLLM_STARTUP_TIMEOUT=900` |
| Idle | 60 seconds if the form accepts it, otherwise the verified default of 5 seconds |
| Storage | No network volume. No extra disk beyond the form’s minimum |
| End state | Delete the endpoint the same day. Confirm the console shows no running worker |

Steps:

1. Read the image tag published with worker release v2.30.0. If that tag is not vLLM v0.32.0, stop. The alternative is a custom image `FROM vllm/vllm-openai:v0.32.0`, which is a separate build and is not part of this approval.
2. Create the queue endpoint with the environment in [runpod-serverless-architecture.md](runpod-serverless-architecture.md). Do not paste `deployment/runpod-start-command.json`.
3. Send one chat completion with a direct client and a long timeout. The served model name is `kimi-vl`. Record the cold-start time, the HTTP status, and the worker log line that shows the revision.
4. If the log shows out of memory, delete the endpoint. Do not remove `--language-model-only`, do not raise the context length, and do not switch model or dtype.
5. If a completion returns, record latency and then delete the endpoint. A GPU memory sample is still missing unless the log provides one.
6. Laravel and React stay unbuilt until a later phase. They are not part of this test.

## What would change later, and only after a successful test

1. Amend NFR-6 so an authenticated RunPod URL is allowed, with the key only on the server.
2. Point `KimiService` at `https://api.runpod.ai/v2/<ENDPOINT_ID>/openai/v1`.
3. Set the Laravel timeout from the measured cold start, not from the 120-second placeholder.
4. Decide how FR-16’s GPU memory sample is collected without SSH.
5. Keep max workers at 1 and active workers at 0 for the POC.

Until that test exists, the Pod design in [architecture.md](architecture.md) and the prepared SSH command remain the documented deployment path. They are also not deployed.

## Sources

The evidence is in [runpod-serverless-feasibility.md](runpod-serverless-feasibility.md), [runpod-serverless-architecture.md](runpod-serverless-architecture.md), and [runpod-serverless-cost-analysis.md](runpod-serverless-cost-analysis.md).
