# Model compatibility checklist

Status: updated in Phase 2 on 21 October 2026. The narrative and the recommendation are in [model-compatibility-findings.md](model-compatibility-findings.md).

Labels:

- **VERIFIED.** Official documentation, published metadata, or a released source file.
- **ESTIMATED.** Calculated, not booted here.
- **UNKNOWN.** Needs a GPU boot, a driver reading, or a price.

No row is a statement that this project has run the model.

## Sources

| Source | Phase 2 result |
| --- | --- |
| [moonshotai/Kimi-VL-A3B-Instruct](https://huggingface.co/moonshotai/Kimi-VL-A3B-Instruct) | **VERIFIED.** Public, not gated, MIT metadata, `image-text-to-text`. The vLLM paragraph on this card is stale. It still says merge request 16387 is unmerged. |
| Hugging Face model API | **VERIFIED** 21 October 2026. SHA `398eede0903cd983a2bfa0cc634e9ac1d843f375`. `lastModified` 30 January 2026. `usedStorage` 32,822,309,117 bytes. 16,407,657,776 BF16 parameters. |
| [MoonshotAI/Kimi-VL README](https://github.com/MoonshotAI/Kimi-VL/blob/main/README.md) | **VERIFIED** as Moonshot’s newer deployment note. It says vLLM main supports the model and pins Transformers 4.51.3 for the Transformers path. The POC does not follow `main` and does not use that Transformers pin for the server. |
| [vLLM v0.32.0 supported models](https://docs.vllm.ai/en/v0.32.0/models/supported_models/) | **VERIFIED.** `KimiVLForConditionalGeneration` lists `moonshotai/Kimi-VL-A3B-Instruct`. Pipeline parallel is marked. LoRA is not marked. |
| [vLLM v0.8.5 release](https://github.com/vllm-project/vllm/releases/tag/v0.8.5) | **VERIFIED.** First release notes entry for Kimi-VL, via PR 16387, published 28 April 2025. Not the pin. |
| [vLLM PR 16387](https://github.com/vllm-project/vllm/pull/16387) | **VERIFIED historical warning.** MoonViT head size 72. Without upstream flash-attn, a fallback path can OOM the GPU. A maintainer reproduced an 80 GB OOM until flash-attn was installed. |
| [vLLM issue 17952](https://github.com/vllm-project/vllm/issues/17952) | **VERIFIED as a published log.** v0.8.5.post1 loaded the weights at 30.614 GiB on an A800 80 GB, then OOM’d while profiling the vision encoder. Closed by pointing back at PR 16387. |
| [config.json](https://huggingface.co/moonshotai/Kimi-VL-A3B-Instruct/blob/main/config.json) | **VERIFIED.** Fields recorded in the findings. |
| [v0.32.0 GPU install](https://docs.vllm.ai/en/v0.32.0/getting_started/installation/gpu/) | **VERIFIED.** Linux, Python 3.11–3.14, compute capability 7.5+. |
| RunPod pricing UI | **UNKNOWN.** Still Phase 3. No quote was taken. |

## Model weights and storage

| Check | Result | Label |
| --- | --- | --- |
| Repository id | `moonshotai/Kimi-VL-A3B-Instruct` | VERIFIED |
| Total parameters | 16,407,657,776 BF16 | VERIFIED |
| Activated parameters | 2.8B in the card prose, 3B in the variant table, 2.8B+0.4B in one benchmark row | VERIFIED wording. Not the VRAM size |
| Dtype | BF16 | VERIFIED |
| Shard count | 7 safetensor files | VERIFIED |
| Hub storage | 30.57 GiB | VERIFIED |
| Weight bytes | 16,407,657,776 × 2 = 30.56 GiB | VERIFIED arithmetic |
| Measured load | 30.614 GiB in a v0.8.5.post1 log on an A800 80 GB | VERIFIED as that log. Not our boot, and not v0.32.0 |
| License | MIT in card metadata. No `LICENSE` file in the sibling list | VERIFIED metadata |
| Gating | Not gated on 21 October 2026 | VERIFIED that day. Reconfirm before download |
| Custom code | Present, and `auto_map` points at it | VERIFIED |
| Official quantized weights | None in the file list | VERIFIED for this list |
| Base model | `moonshotai/Moonlight-16B-A3B` | VERIFIED |
| Disk plan | Checkpoint 30.57 GiB plus an unmeasured container. Plan at least 100 GB, and price 150 GB if the rate matters | ESTIMATED volume size |

Download time and cold-start time remain **UNKNOWN**.

## GPU VRAM

No GPU is approved. 48 GB is not a verified fit.

| Input | Result | Label |
| --- | --- | --- |
| BF16 weight floor | 30.56 GiB raw, about 30.6 GiB once loaded in the 0.8.5 log | VERIFIED |
| KV cache at 2,048 tokens, one sequence, compressed layout | About 61 MiB, using `(512 + 64) × 27 × 2` bytes per token | ESTIMATED. Depends on v0.32.0 still using the DeepSeek-style cache seen in the v0.16.0 class |
| Vision profile and CUDA graphs | The 80 GB failure happened here | UNKNOWN for v0.32.0 with `--language-model-only` |
| 24 GB class | Below the weight floor | Not a BF16 candidate |
| 32 GB and 40 GB classes | Remainder after 30.6 GiB is too small to treat as a plan | Not candidates |
| A40 48 GB | Architecture 8.6 is legal for vLLM. Remainder after weights is unproven, and smaller than the 80 GB card that already failed a vision profile | Architecture VERIFIED. Fit UNKNOWN. Not called sufficient |
| A100 80 GB | Architecture 8.0 is legal, and A100 is a vLLM example. Weights have been observed to load on an 80 GB A800. Startup still failed in that log | First boot candidate. Fit of v0.32.0 UNKNOWN |

MoE shape, **VERIFIED**: 27 text layers, hidden size 2048, 64 routed experts, 2 shared experts, 6 experts per token, first layer dense. The full expert set is inside the 30.6 GiB. Activated-parameter counts do not shrink that floor. CPU offload stays out of the plan.

Vision tower, **VERIFIED**: MoonViT, 27 layers, hidden size 1152, head size 72. Its weights are inside the 30.6 GiB. The POC sends no images. `--language-model-only` is the planned way to skip image profiling. Whether that skips the profile on this model is **UNKNOWN**.

Context policy:

- The checkpoint allows 131,072 positions. **VERIFIED.**
- Moonshot’s serve example uses `--max-model-len 32768`. That example is not the POC setting.
- First boot setting: `--max-model-len 2048`. **ESTIMATED** as a conservative cap, not a measured maximum.

`gpu_memory_utilization` default in the v0.32.0 tag is **0.92**. **VERIFIED.** First boot sets **0.90**.

## Inference engine pin

| Engine | Phase 2 result | POC rule |
| --- | --- | --- |
| vLLM v0.32.0 | Released 18 October 2026. Docs for that version list this model | **Pin.** Linux server. Python 3.12. Prefer `vllm/vllm-openai:v0.32.0-cu129` when the driver is unknown. The RTX PRO 6000 pod that reports CUDA 13.2 uses `vllm/vllm-openai:v0.32.0` instead. See [rtx-pro-6000-deployment-review.md](rtx-pro-6000-deployment-review.md) |
| vLLM v0.8.5 | First release with the feature. Also the version in the OOM log | Historical only |
| Hugging Face Transformers | Card says Python 3.10, Torch >= 2.1.0, Transformers 4.48.2. GitHub README says Torch 2.5.1 and Transformers 4.51.3 | Reference path. Not the server. Those pins conflict with v0.32.0 |
| Hosted Moonshot API | Exists outside this project | Forbidden |
| Community GGUF | A space name appears on the model API | Not an official runtime |

v0.32.0 requires `transformers>=5.16.1,<5.20.0` and bundles PyTorch. **VERIFIED** from the release notes and install page. Do not install Moonshot’s 4.x Transformers pin into that environment.

Flash-attn is a **historical** workaround from PR 16387, not a step proven necessary for v0.32.0. Add it only if a future boot log shows the same MoonViT fallback. That would be a new decision, inside an already approved GPU session, and an OOM still stops the session.

## CUDA, Python, and GPUs

| Item | Phase 2 result |
| --- | --- |
| Python | 3.11–3.14 required. POC uses 3.12. Moonshot’s 3.10 pin does not apply to this server |
| PyTorch | The wheel’s own build. Do not overlay Torch 2.5.1 |
| CUDA | Published artifacts include CUDA 13.0 and CUDA 12.9. CUDA 13 images document an R580 driver, with a compatibility note for some older datacenter drivers |
| Driver on the future pod | UNKNOWN until Phase 4 |
| A40 and A100 architecture | Both are compute capability 8.0 or higher, so both meet the 7.5 rule |
| Named SKU has loaded this checkpoint for us | Not done |

## Quantization

| Check | Status |
| --- | --- |
| Official quantized Instruct files | None in the file list. VERIFIED |
| vLLM quant method marked for `KimiVLForConditionalGeneration` | Not marked on the v0.32.0 row. UNKNOWN |
| Community GGUF as a way onto 24 GB | Out of scope unless a later approval changes the engine |

## GPU hourly cost

Phase 3 priced advertised rates only. The live deploy form is still **UNKNOWN** until someone opens it. See [gpu-pricing-analysis.md](gpu-pricing-analysis.md).

The October 8, 2026 RunPod list advertises Community Cloud A100 PCIe 80 GB at USD 1.19/hr and A100 SXM 80 GB at USD 1.39/hr. A 4-hour session with 150 GB of running disk is about USD 4.84 to USD 5.64, under 3 OMR at the peg, before tax and card FX. The A40 is cheaper and remains an unverified fallback. Vast.ai has no static A100 price. Rental stays closed until a checkout matches this sheet and a separate spend approval is given.

## Stop and go before cloud rental

Phase 2 answers the compatibility items as follows:

| Gate | Result |
| --- | --- |
| A released vLLM documents this checkpoint | Met by v0.32.0 |
| CUDA, PyTorch, and Python are written down | Met, with the driver still to be read on the pod |
| Weight floor about 30.6 GiB BF16 | Met |
| Proposed class is above that floor plus a real reserve | Met only as a pricing candidate for 80 GB. 48 GB does not clear this gate |
| SHA and license reconfirmed | Met on 21 October 2026. SHA is checked again before download |
| Still official BF16 weights and vLLM | Met |
| First-session price under 20 OMR | Advertised RunPod arithmetic meets it. Checkout tax, FX, and the live hourly rate are still open. Rental stays closed |

Rental also stays closed if Phase 3 finds that the A100 session does not fit the budget. The A40 is not an automatic replacement.

## Phase 2 recommendation

**CONDITIONAL GO** to Phase 3 pricing only. Acceptance of that recommendation is still required. Phase 3 does not create a RunPod resource.
