# RunPod Serverless feasibility

Status: evaluation only, 21 October 2026. No endpoint was created, no image was built, and no money was spent. The existing Pod files under `deployment/` were not changed.

**CONDITIONAL.** A queue-based Serverless worker can be the self-hosted path if it loads this exact checkpoint on a rented GPU. That load is not proven. The Pod SSH command and `vllm/vllm-openai:v0.32.0` by themselves are not a Serverless deployment.

Labels:

- **VERIFIED.** Read from a RunPod or vLLM document, or from the worker source, on 21 October 2026.
- **ESTIMATED.** Arithmetic or a mapping that the source does not state outright.
- **UNKNOWN.** Needs a console reading or a real worker start.

## What “self-hosted” means here

The manager’s bar, as given for this evaluation, is that the answers come from `moonshotai/Kimi-VL-A3B-Instruct` running on GPU infrastructure we rent, not from Moonshot’s hosted API.

Under that definition, RunPod Serverless still qualifies **if** the worker loads revision `398eede0903cd983a2bfa0cc634e9ac1d843f375` itself. The weights are the public Moonshot checkpoint. The GPU is rented. Moonshot’s API is not called.

It is a weaker machine than a Pod. There is no SSH session, no `nvidia-smi` we can run, and the host is shared. The current requirement NFR-6 says vLLM must listen on loopback and Laravel must reach it through an SSH tunnel. A Serverless URL is a public HTTPS address protected by a RunPod API key. That can keep the key out of the browser, and it is not the SSH design. Adopting it requires an explicit change to NFR-6. This evaluation does not make that change.

## Model and vLLM

**VERIFIED.** vLLM v0.32.0 lists `KimiVLForConditionalGeneration` for this model id. Inputs are text and image. `--language-model-only` exists in that release and disables multimodal inputs. It does not document skipping the vision tower. A boot on RTX PRO 6000 Blackwell is still unproven. Those facts are in [model-compatibility-findings.md](model-compatibility-findings.md) and [rtx-pro-6000-deployment-review.md](rtx-pro-6000-deployment-review.md).

The checkpoint needs `--trust-remote-code` and the pinned revision. A one-click Hugging Face deploy that omits either one is not this POC.

## Official worker versus our Pod image

**VERIFIED** from [runpod-workers/worker-vllm](https://github.com/runpod-workers/worker-vllm) and its configuration reference:

- The queue worker is a wrapper. It builds `vllm serve` from environment variables, waits for `/health`, and proxies jobs to vLLM on loopback. Callers use `https://api.runpod.ai/v2/<ENDPOINT_ID>/openai/v1` with a RunPod API key.
- `--host` and `--port` are reserved. The worker binds vLLM to loopback. Our Pod command’s `--host 127.0.0.1` is already how the worker behaves, but the SSH startup JSON cannot be pasted into a Serverless endpoint.
- `MODEL_NAME` maps to `--model`. `MODEL_REVISION` maps to `--revision`. `TRUST_REMOTE_CODE=true` maps to `--trust-remote-code`. `ENFORCE_EAGER`, `MAX_MODEL_LEN`, `MAX_NUM_SEQS`, and `GPU_MEMORY_UTILIZATION` map to the same flags we already chose.
- `--language-model-only` is **not** in the worker’s flag allowlist in `src/args_builder.py` as read on 21 October 2026. Setting `LANGUAGE_MODEL_ONLY` would do nothing. The documented escape hatch is `VLLM_EXTRA_ARGS="--language-model-only"`.
- Release [v2.30.0](https://github.com/runpod-workers/worker-vllm/releases/tag/v2.30.0), published 21 October 2026, says it bumps vLLM to v0.32.0. The same repository’s configuration page still says a custom image’s default `VLLM_VERSION` build arg is `v0.23.0`. A fetched view of the Dockerfile also showed an older `vllm==0.20.2` install line. Those statements disagree.

**UNKNOWN** which vLLM version is inside the image tag a console deploy would pull. The release note is the newest claim. It is not a substitute for `vllm --version` in the running image. Do not use a floating `latest` tag.

The Pod image `vllm/vllm-openai:v0.32.0` is the right pin for a Pod. On Serverless it is only the right image for a **load balancing** endpoint, which the worker README describes as a different product: no job queue, HTTP port 8000 exposed, and a request that arrives before the worker is healthy returns an error instead of waiting. Exposing an HTTP port is documented as publishing the worker’s public IP. That conflicts with NFR-6. This evaluation does not recommend that option for the POC.

## Three ways to run it

| Path | Keeps vLLM v0.32.0 | SSH / loopback | Scale to zero | Fit for this POC |
| --- | --- | --- | --- | --- |
| Official queue worker, release v2.30.0 | Claimed by the release. Not confirmed inside the image | vLLM on loopback. Callers use authenticated RunPod HTTPS | Yes, if active workers are 0 | First candidate, after the image version is confirmed |
| Custom worker built `FROM vllm/vllm-openai:v0.32.0` | Yes, if the build arg or base image is that tag | Same loopback proxy if it follows the official worker | Yes, with the same endpoint settings | The fallback if the published image is not v0.32.0 |
| Load balancer using `vllm/vllm-openai:v0.32.0` directly | Yes | Requires exposing port 8000. Cold requests fail instead of waiting | Yes, with flex workers | Not recommended. It weakens the private-endpoint rule |
| Secure Cloud GPU Pod | Yes. Command already prepared | SSH tunnel to `127.0.0.1:8000`. Matches NFR-6 | No. A running Pod bills until it is stopped. A stopped volume still bills | Remains the fallback if Serverless cannot load the model or the API-key URL is rejected |

## GPU

**VERIFIED** on the Serverless endpoint settings page, read 21 October 2026. Flex compute rates include:

| Documented row | Memory | USD per second | USD per hour, 3600 × rate |
| --- | --- | --- | --- |
| A6000, A40 | 48 GB | 0.00034 | 1.224 |
| A100 | 80 GB | 0.00076 | 2.736 |
| 6000s PRO | 96 GB | 0.00111 | 3.996 |
| H100 PRO | 80 GB | 0.00116 | 4.176 |

**UNKNOWN** whether the console row `6000s PRO` is the RTX PRO 6000 Blackwell Server Edition, the Workstation Edition, or another 96 GB board. The GPU catalog lists those Blackwell names at 96 GB. The Serverless price table does not use those names. For an endpoint with fewer than five workers, RunPod says every worker uses the highest-priority GPU that is available. Stock of that row was not checked.

48 GB remains not enough on current evidence. 80 GB A100 loaded this checkpoint’s weights on an older vLLM and then failed in the vision profile. 96 GB is larger and still unproven, on a Pod or on Serverless. Compute capability 12.0 support inside whichever worker image is pulled is **UNKNOWN**.

## What has to be true before a test

1. The image tag is read from release v2.30.0, or a custom image is built from `vllm/vllm-openai:v0.32.0`.
2. Environment sets `MODEL_NAME`, `MODEL_REVISION=398eede0903cd983a2bfa0cc634e9ac1d843f375`, `TRUST_REMOTE_CODE=true`, and `VLLM_EXTRA_ARGS=--language-model-only`.
3. Active workers stay 0. Max workers is set to 1. The default max is 3.
4. The first request is a direct API call, not Laravel. Laravel is not initialized.
5. An out-of-memory log ends the test. No quantization and no second model in that session.

## Sources

| Source | What it settled |
| --- | --- |
| [Serverless vLLM overview](https://docs.runpod.io/serverless/vllm/overview) | Official worker path and environment-variable configuration |
| [worker-vllm v2.30.0](https://github.com/runpod-workers/worker-vllm/releases/tag/v2.30.0) | Published 21 October 2026. Claims a bump to vLLM v0.32.0 |
| [worker-vllm configuration](https://github.com/runpod-workers/worker-vllm/blob/main/docs/configuration.md) | Env-to-flag map, loopback bind, extra args |
| [worker `args_builder.py`](https://github.com/runpod-workers/worker-vllm/blob/main/src/args_builder.py) | `--language-model-only` is absent from the allowlist |
| [Endpoint settings](https://docs.runpod.io/serverless/endpoints/endpoint-configurations) | Active 0, max workers, idle timeout, 96 GB row |
| [GPU types](https://docs.runpod.io/references/gpu-types) | Blackwell 96 GB names. No sentence equates them to `6000s PRO` |
