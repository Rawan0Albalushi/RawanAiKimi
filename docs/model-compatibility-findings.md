# Model compatibility findings

Status: Phase 2, 21 October 2026. Read-only. No weights were downloaded, no packages were installed, and no cloud resource was created.

Labels used below:

- **VERIFIED.** Stated by an official document, a published config or metadata file, or a released vLLM source file read on this date.
- **ESTIMATED.** Arithmetic or a reasoned projection from those sources. Not measured on our hardware.
- **UNKNOWN.** Needs a real GPU boot, a driver reading, or a vendor price.

## Executive summary

**Recommendation: CONDITIONAL GO to Phase 3 pricing. Do not rent yet.**

`moonshotai/Kimi-VL-A3B-Instruct` is a public BF16 Mixture-of-Experts vision-language checkpoint. Released vLLM, not only the development branch, documents it. The pin for this POC is **vLLM v0.32.0**, published 18 October 2026. That release’s own supported-models page lists `KimiVLForConditionalGeneration` with this Hugging Face id.

The weight floor is about **30.6 GiB**. A public vLLM 0.8.5 log on an 80 GB GPU reported `Model loading took 30.6140 GiB`, then ran out of memory during the vision-encoder profile before it served a request. That log is not our boot, and it is not v0.32.0. It is enough to refuse the claim that 48 GB is sufficient.

Phase 3 may price an **A100 80 GB** as the first boot candidate and an **A40 48 GB** only as an unproven fallback. A fit is **UNKNOWN** until a later, separately approved boot. If the A100 session price, including disk, does not fit under 20 OMR, stop and ask. Do not treat the A40 as the answer to that price problem.

## Official sources

| Source | URL | What it settled |
| --- | --- | --- |
| Hugging Face model card | https://huggingface.co/moonshotai/Kimi-VL-A3B-Instruct | Identity, license metadata, stale vLLM paragraph |
| Hugging Face model API | https://huggingface.co/api/models/moonshotai/Kimi-VL-A3B-Instruct | SHA, gating, parameter count, storage, file list |
| `config.json` | https://huggingface.co/moonshotai/Kimi-VL-A3B-Instruct/blob/main/config.json | Architecture fields and BF16 dtype |
| `generation_config.json` | https://huggingface.co/moonshotai/Kimi-VL-A3B-Instruct/blob/main/generation_config.json | Instruct temperature 0.2 |
| Moonshot GitHub README | https://github.com/MoonshotAI/Kimi-VL/blob/main/README.md | Newer deployment section and Transformers pin |
| vLLM v0.8.5 release | https://github.com/vllm-project/vllm/releases/tag/v0.8.5 | First release that names Kimi-VL support |
| vLLM v0.32.0 release | https://github.com/vllm-project/vllm/releases/tag/v0.32.0 | Pin, wheels, and container tags |
| vLLM v0.32.0 supported models | https://docs.vllm.ai/en/v0.32.0/models/supported_models/ | Architecture row for this checkpoint |
| vLLM v0.32.0 GPU install | https://docs.vllm.ai/en/v0.32.0/getting_started/installation/gpu/ | OS, Python, compute capability, CUDA images |
| vLLM v0.32.0 cache config | https://github.com/vllm-project/vllm/blob/v0.32.0/vllm/config/cache.py | `gpu_memory_utilization` default 0.92 |
| vLLM engine arguments | https://docs.vllm.ai/en/stable/configuration/engine_args/ | `--language-model-only`, `--enforce-eager`, `--max-model-len` |
| Kimi-VL support PR | https://github.com/vllm-project/vllm/pull/16387 | Historical MoonViT memory warning |
| vLLM issue 17952 | https://github.com/vllm-project/vllm/issues/17952 | 30.614 GiB load, then profile OOM on 80 GB |
| NVIDIA compute capability list | https://developer.nvidia.com/cuda/gpus | A40 is 8.6, A100 is 8.0 |
| NVIDIA A40 page | https://www.nvidia.com/en-us/data-center/a40/ | 48 GB GDDR6 with ECC |
| CBO peg | https://cbo.gov.om/Pages/FixedPeg.aspx | Budget conversion only. Pricing is Phase 3. |

The vLLM documentation root is https://docs.vllm.ai/. Versioned pages above are the ones this pin relies on. The unversioned `stable` and `latest` sites were used only to find flags, then checked against the v0.32.0 tag where noted.

## Document disagreement

Three official texts do not say the same thing. The disagreement is recorded here so a later phase does not follow the stale one.

| Topic | Hugging Face model card | Moonshot GitHub README | vLLM release docs |
| --- | --- | --- | --- |
| vLLM support | Says merge request 16387 is submitted and to use that branch until it merges | News entry dated 15 April 2025 says vLLM supports Kimi-VL. Deployment section says the main branch supports it | **VERIFIED.** v0.8.5 release notes, 28 April 2025, include “Add Kimi-VL model support (#16387)”. v0.32.0’s supported-models page lists the class and this model id |
| Python | 3.10 | 3.10 | **VERIFIED for the pin.** v0.32.0 requires Python 3.11 through 3.14. The release notes say Python 3.10 was dropped |
| PyTorch | `torch>=2.1.0` | `torch==2.5.1` for the Transformers example | **VERIFIED.** The v0.32.0 wheel bundles its own PyTorch. Do not install Moonshot’s Torch pin beside it |
| Transformers | `transformers==4.48.2` | `transformers==4.51.3` | **VERIFIED constraint of the pin.** v0.32.0 release notes require `transformers>=5.16.1,<5.20.0`. `config.json` records `transformers_version` 4.50.3, which is the version that wrote the file |

The Hugging Face card is stale on vLLM. The serving authority for this POC is the v0.32.0 release, not that paragraph and not an unpinned main branch. Moonshot’s Python 3.10 and Transformers 4.x pins remain the documented path for raw Transformers inference. They are not the server pin.

## Verified compatibility findings

### Checkpoint

| Fact | Value | Label |
| --- | --- | --- |
| Repository | `moonshotai/Kimi-VL-A3B-Instruct` | VERIFIED |
| Visibility | Public, `gated: false`, `private: false` | VERIFIED, 21 October 2026 |
| License metadata | MIT in the model-card YAML and the API `cardData` | VERIFIED as metadata. The file list has no separate `LICENSE` file |
| Commit | `398eede0903cd983a2bfa0cc634e9ac1d843f375` | VERIFIED. Re-fetch immediately before any future download |
| Last modified | 30 January 2026 | VERIFIED from the API |
| Task | `image-text-to-text` | VERIFIED. The POC still sends text only |
| Architecture string | `KimiVLForConditionalGeneration`, `model_type` `kimi_vl` | VERIFIED in `config.json` |
| Custom code | `configuration_kimi_vl.py`, `modeling_kimi_vl.py`, Moonshot tokenizer files. `auto_map` points at the custom class | VERIFIED. Loading can execute repository code. Pin the commit |
| Parameters | 16,407,657,776, all BF16 | VERIFIED in safetensors metadata |
| Activated parameters | Card prose says 2.8B in the language decoder. The variant table says 3B activated. A benchmark row says 2.8B+0.4B for the language model plus vision tower | VERIFIED as published wording. This is not the VRAM size |
| Context in the card | 128K. `max_position_embeddings` is 131072 | VERIFIED. This is not the POC server setting |
| Instruct sampling | Temperature 0.2 in the card and in `generation_config.json` | VERIFIED |
| Quantized files | File list is 7 BF16 shards plus config and code. No GGUF, AWQ, or GPTQ names | VERIFIED for this file list |
| Base model | `moonshotai/Moonlight-16B-A3B` | VERIFIED |

Text config, **VERIFIED** from `config.json`: 27 layers, hidden size 2048, 16 attention heads, 64 routed experts, 2 shared experts, 6 experts per token, vocabulary 163840, embeddings not tied. Attention is the compressed-KV shape: `kv_lora_rank` 512, `qk_rope_head_dim` 64, `qk_nope_head_dim` 128, `v_head_dim` 128.

Vision config, **VERIFIED**: MoonViT, 27 layers, hidden size 1152, 16 heads, patch size 14. Head size is 1152 / 16 = 72. That matches the historical vLLM note. Those vision parameters are inside the 16.41B total, so they are already inside the weight floor.

The v0.16.0 API text for `KimiVLForConditionalGeneration` builds the language model as `DeepseekV2ForCausalLM` and the vision tower as MoonViT. **VERIFIED for v0.16.0.** The same line was not re-read in the v0.32.0 file. v0.32.0 still lists the same architecture and model id.

### vLLM releases

| Release | Evidence | Role |
| --- | --- | --- |
| v0.8.5, 28 April 2025 | Release notes list “Add Kimi-VL model support (#16387)” | First released support. Not the pin. It is the version in the 80 GB OOM report |
| v0.25.0 docs | Supported-models page also lists this row | Shows support survived later releases. Not the pin |
| v0.32.0, 18 October 2026 | Latest GitHub release. Its own docs page lists `KimiVLForConditionalGeneration` for `moonshotai/Kimi-VL-A3B-Instruct` and `moonshotai/Kimi-VL-A3B-Thinking`. Inputs are text plus image. Pipeline parallel is marked. The LoRA column is empty | **POC pin** |

Support does not depend on an unmerged patch or on `main`. v0.32.0 is three days old and large. That is a boot risk, not a reason to follow `main` instead. If v0.32.0 fails to import this architecture, the fallback investigation is v0.25.0’s docs row, and that switch needs a new note. It is not authorized now.

### Runtime requirements for v0.32.0

| Requirement | Official statement | Label |
| --- | --- | --- |
| OS | Linux. vLLM does not support Windows natively | VERIFIED. Matches the plan: vLLM on the GPU host, Laravel on Windows |
| Python | 3.11–3.14. Python 3.10 was removed in this release | VERIFIED. POC choice inside that range: **Python 3.12** |
| GPU architectures | Compute capability 7.5 or higher. The examples include A100 | VERIFIED as a general vLLM rule |
| A100 | Compute capability 8.0 on NVIDIA’s list, and named by vLLM | VERIFIED as an allowed architecture. Boot of this model is UNKNOWN |
| A40 | Compute capability 8.6 on NVIDIA’s list. 8.6 is above 7.5. vLLM’s examples do not name the A40 | VERIFIED as an allowed architecture class. Memory fit is UNKNOWN |
| CUDA artifacts | Release page: default PyPI wheel and `vllm/vllm-openai:v0.32.0` are CUDA 13.0. A CUDA 12.9 image is `vllm/vllm-openai:v0.32.0-cu129`. The versioned install page also says binaries are compiled with CUDA 12.9 and that 12.8 and 13.0 wheels exist | VERIFIED that both CUDA 12.9 and 13.0 artifacts are published. Which file `pip install vllm` resolves on a given day must be read from the release page at install time |
| Driver for CUDA 13 images | The install page says CUDA 13 normally wants an R580 or newer driver. Compatibility mode mentions R535 and R570 for select datacenter GPUs | VERIFIED as a documented constraint. The pod’s actual driver is UNKNOWN |
| PyTorch | Bundled with the wheel. Do not mix in another Torch build | VERIFIED recommendation |
| Windows client | Irrelevant to the server pin | The SSH client check remains a Phase 8 item |

Quantization methods are not marked on the Kimi-VL row. Official FP8, AWQ, or GPTQ support for this class is **UNKNOWN**. There is no official quantized Instruct file to switch to. CPU expert offload is not part of this plan. The 30.6 GiB load figure below is the full checkpoint, so inactive experts were resident in that run.

## Proposed pinned versions

| Component | Pin | Why |
| --- | --- | --- |
| Model | `moonshotai/Kimi-VL-A3B-Instruct` at `398eede0903cd983a2bfa0cc634e9ac1d843f375` | Official Instruct checkpoint. Re-check the SHA before download |
| Inference engine | vLLM **v0.32.0** | Newest released docs that list this exact model. Not `main` |
| Container preference | `vllm/vllm-openai:v0.32.0-cu129`, unless the pod driver is R580 or newer and the operator then chooses the CUDA 13.0 tag | Ampere cards are often imaged with drivers older than R580. This preference is **ESTIMATED** until `nvidia-smi` is read. A pod that reports CUDA 13.2 uses the CUDA 13.0 tag of the same pin. See [rtx-pro-6000-deployment-review.md](rtx-pro-6000-deployment-review.md) |
| Python on the pod | 3.12 | Inside the verified 3.11–3.14 window |
| Served model name | `kimi-vl` | Moonshot’s Instruct example name. It stays an environment value |
| Sampling | temperature 0.2, max tokens 512 | VERIFIED Instruct default for temperature. The token cap is the POC design default |
| Not pinned from Moonshot | Python 3.10, Torch 2.5.1, Transformers 4.48.2 or 4.51.3 | Those pins contradict v0.32.0 |

## VRAM and disk

### Weight floor

Formula, **VERIFIED arithmetic** on the published count:

`16,407,657,776 parameters × 2 bytes = 32,815,315,552 bytes = 30.56 GiB`

Storage reported by the Hub, **VERIFIED**: `32,822,309,117` bytes = **30.57 GiB**, in 7 safetensor shards.

Independent measurement from someone else’s log, **VERIFIED as the contents of issue 17952**, not as our result: vLLM 0.8.5.post1 on one NVIDIA A800 80 GB printed `Model loading took 30.6140 GiB` after loading 7 shards in about 12 seconds. The same process then failed while profiling multimodal embeddings. The maintainer pointed at the known MoonViT flash-attention issue in PR 16387. The reporter closed the issue after that explanation.

So the weights can occupy about 30.6 GiB on an 80 GB card. That does not mean an 80 GB card finishes startup, and it says nothing about 48 GB.

### Other GPU memory

| Consumer | What is known | Label |
| --- | --- | --- |
| Weights | About 30.56 GiB raw, 30.614 GiB reported by vLLM 0.8.5 | VERIFIED size. v0.32.0 load size is ESTIMATED to be in the same range because the checkpoint dtype and parameter count are unchanged |
| KV cache for the compressed layout | Per token ≈ `(512 + 64) × 27 layers × 2 bytes` = 31,104 bytes, about 30.4 KiB. At 2,048 tokens and one sequence that is about 61 MiB | ESTIMATED. It assumes the v0.16.0 DeepSeek-MLA wiring is still how v0.32.0 stores cache. It ignores fragmentation |
| CUDA graphs, activations, and the vision profile | The 0.8.5 failure was this stage, on 80 GB, with `--limit-mm-per-prompt image=2`, eager mode off, and the profiler preparing 8 maximum-size image items | UNKNOWN for v0.32.0 with `--language-model-only` |
| `gpu_memory_utilization` | Default in the v0.32.0 tag is **0.92** | VERIFIED default. The first boot should set **0.90** so a little memory stays outside the pool |

A textbook full-head KV formula would be the wrong one. The config’s `kv_lora_rank` means the cache is the compressed latent, if vLLM still implements it that way. Even the high end of this estimate is small next to the weight floor. The memory risk is the vision profile and runtime overhead, not a 2K chat cache.

### Disk

| Item | Size | Label |
| --- | --- | --- |
| Checkpoint | 30.57 GiB | VERIFIED |
| vLLM container plus CUDA userland | Not measured. The image is a separate multi-gigabyte download | UNKNOWN |
| Planning volume | At least 100 GB. Price a **150 GB** volume if the rate is material, so the image, the checkpoint, and a partial retry fit together | ESTIMATED planning number |

Download and cold-start durations are **UNKNOWN**. The 12 second figure in issue 17952 is shard load after the files were already on disk, on a different machine.

## Candidate GPU comparison

No SKU is approved. No price was taken from RunPod. “Eligible architecture” means the compute capability is inside vLLM’s published range.

| | A40 48 GB | A100 80 GB |
| --- | --- | --- |
| NVIDIA memory | 48 GB GDDR6 with ECC | 80 GB class. The 80 GB product is the one relevant here |
| Compute capability | 8.6 | 8.0 |
| Inside vLLM’s 7.5+ rule | Yes | Yes, and A100 is a named example |
| Weight floor versus card | 30.6 GiB leaves a marketing remainder near 17 GB before ECC, driver reserve, and the 0.90 pool | 30.6 GiB leaves a marketing remainder near 49 GB before the same reserves |
| Pool at 0.90 if the card reports its full marketing GiB | About 43.2 GiB, so about 12.6 GiB after a 30.6 GiB load | About 72 GiB, so about 41 GiB after a 30.6 GiB load |
| Public boot evidence | None found for this model | One v0.8.5 log loaded weights on an A800 80 GB and then OOM’d in the vision profile |
| Verdict | **Not sufficient on current evidence.** Eligible only as an unproven fallback | **First card to price and, later, to boot.** Still UNKNOWN until that boot |

ECC and the driver can make the usable A40 figure smaller than 48 GiB. That usable figure is **UNKNOWN** until `nvidia-smi` runs. It is another reason not to call 48 GB enough.

24 GB cards remain below the weight floor for resident BF16 weights. They are out of this plan.

## Known risks

1. **48 GB has not been shown to start.** The only public startup log that got as far as loading weights did so on 80 GB and still failed afterward.
2. **Vision profiling can dominate the weight floor.** PR 16387 says MoonViT head size 72 does not fit vLLM’s bundled flash-attention, and the fallback can OOM a GPU. Current `main` MoonViT code uses `MMEncoderAttention` and no longer prints that warning. Whether v0.32.0 still has the expensive path is **UNKNOWN**.
3. **v0.32.0 is a new release** with a Transformers 5.x requirement that contradicts Moonshot’s 4.x instructions. An import or config failure is possible.
4. **CUDA 13 versus the host driver.** A default CUDA 13 image can fail on an R535 or R570 datacenter driver unless compatibility mode applies. The cu129 tag is the safer first image to plan, after the driver is read.
5. **Custom code.** `--trust-remote-code` can execute the pinned repository’s Python. Keep the commit pin.
6. **Upstream context flags are unsafe to copy.** Moonshot’s example uses `--max-model-len 32768`. The original PR example uses 131072. Neither is the first boot setting.
7. **A stale model card** still tells operators the vLLM patch is unmerged. Follow this findings note instead.
8. **Budget.** 20 OMR is about USD 52 at the CBO peg before fees and tax. An 80 GB hour price may or may not leave a short boot inside that ceiling. That question is Phase 3. It is not answered here.

## Suggested first boot-test configuration

Do not run this in Phase 2 or Phase 3. It is the configuration for the first paid boot after a later spend approval. Bind to loopback. One GPU. Text only.

```text
vllm serve moonshotai/Kimi-VL-A3B-Instruct \
  --revision 398eede0903cd983a2bfa0cc634e9ac1d843f375 \
  --served-model-name kimi-vl \
  --trust-remote-code \
  --host 127.0.0.1 \
  --port 8000 \
  --tensor-parallel-size 1 \
  --language-model-only \
  --enforce-eager \
  --max-model-len 2048 \
  --max-num-seqs 1 \
  --gpu-memory-utilization 0.90
```

Why these flags:

- `--language-model-only` is documented as disabling multimodal inputs by setting every modality limit to 0. The intent is to avoid the vision profile that OOM’d the 80 GB run. Whether v0.32.0 actually skips that profile for this model is **UNKNOWN**.
- `--enforce-eager` disables CUDA graphs for the first start, which removes one memory consumer. The default is off.
- `--max-model-len 2048` is a short POC cap. It is not a measured maximum. Raise it only after a start that prints a successful KV-cache allocation.
- `--gpu-memory-utilization 0.90` is slightly under the v0.32.0 default of 0.92.
- `--max-num-seqs 1` keeps the first test to a single chat.

If this command fails with an out-of-memory error, stop the pod. Do not raise the context length, do not remove `--language-model-only`, and do not switch model, dtype, or engine in that session.

## Recommendation

**CONDITIONAL GO.**

Phase 3 may start after this recommendation is accepted. It still must not create a pod. The conditions are:

1. Keep the model and vLLM. The pin is v0.32.0 and the checkpoint SHA above.
2. Price the **A100 80 GB** first, for one download, one boot attempt with the command above, and a shutdown. Include disk of at least 100 GB, preferably 150 GB if that changes the total, and include stopped-disk charges, tax, and FX.
3. The A40 48 GB may appear on the same sheet as an unproven fallback. The sheet must say it is not a verified fit.
4. If the A100 first session does not fit under 20 OMR, stop and return. Do not approve the A40 as a substitute on the theory that it is “close.”
5. A later boot that OOMs is a stop, not permission to quantize or change models.
6. Spend still requires its own written approval after the Phase 3 sheet exists.

This is not a no-go, because a released vLLM version officially lists the checkpoint, the weight size is known, and an 80 GB card is large enough that a careful text-only boot is a reasonable experiment. It is not an unqualified go, because nobody has booted v0.32.0 for this project, and 48 GB is not an evidenced fit.
