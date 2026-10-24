# RTX PRO 6000 pre-deployment review

Status: read-only review, 21 October 2026. Nothing was installed, rented, deployed, or downloaded. Application code was not changed. This note does not approve spend.

**CONDITIONAL compatibility. Not a deploy approval.**

The official image for the pinned runtime on this reported driver class is `vllm/vllm-openai:v0.32.0`. That tag is the CUDA 13.0 image in the vLLM v0.32.0 release. `vllm/vllm-openai:latest` is not the pin. v0.32.0 lists `KimiVLForConditionalGeneration`, and `--language-model-only` is a documented flag in that release. A successful load of `moonshotai/Kimi-VL-A3B-Instruct` on an RTX PRO 6000 Blackwell has not been observed. The 30 GB container disk cannot hold the checkpoint. The 120 GB volume at `/workspace` can.

The Phase 3 spend conditions were written for a Community Cloud A100 PCIe 80 GB. They do not authorize this Secure Cloud SKU.

Labels:

- **VERIFIED.** Read from an official document or a published file on 21 October 2026.
- **ESTIMATED.** Arithmetic or a mapping from those sources. Not measured on this pod.
- **UNKNOWN.** Needs `nvidia-smi`, a template reading, a checkout screen, or a real boot.

## Machine under review

These figures were supplied for the pod being prepared. They were not read from `nvidia-smi` or a checkout receipt.

| Item | Supplied value | How this review treats it |
| --- | --- | --- |
| Provider and cloud | RunPod Secure Cloud | User-supplied. Secure Cloud is outside the Phase 3 preferred Community Cloud A100 |
| GPU | NVIDIA RTX PRO 6000 Blackwell | Matches a name on NVIDIA’s compute-capability table. Workstation versus Server edition was not stated |
| VRAM | 96 GB | User-supplied. Not measured |
| Available CUDA | 13.2 | User-supplied. Usual meaning of the `nvidia-smi` “CUDA Version” line, not yet pasted |
| System RAM | 94 GB | User-supplied |
| vCPU | 16 | User-supplied |
| Container disk | 30 GB | User-supplied. Too small for the checkpoint |
| Volume disk | 120 GB at `/workspace` | User-supplied path. Large enough for one copy of the checkpoint |
| Template image | `vllm/vllm-openai:latest` | Reject for this POC. It is a moving tag |
| Model | `moonshotai/Kimi-VL-A3B-Instruct` | Unchanged pin |
| Runtime | vLLM v0.32.0 | Unchanged pin |

## 1. Official Docker image

**VERIFIED.** The vLLM v0.32.0 GitHub release, published 18 October 2026, lists these CUDA images and no others:

| Release label | Tag |
| --- | --- |
| CUDA 13.0, default | `vllm/vllm-openai:v0.32.0` |
| CUDA 12.9 | `vllm/vllm-openai:v0.32.0-cu129` |
| CUDA 13.0, Ubuntu 24.04 | `vllm/vllm-openai:v0.32.0-ubuntu2404` |
| CUDA 12.9, Ubuntu 24.04 | `vllm/vllm-openai:v0.32.0-cu129-ubuntu2404` |

There is no `v0.32.0-cu132` tag and no CUDA 13.2 image in that table. Do not invent one. Do not use a third-party SM120 image.

The image for this pod is **`vllm/vllm-openai:v0.32.0`**.

That choice follows the release table, not the moving Docker documentation. The current Docker guide’s example uses `vllm/vllm-openai:latest`. `:latest` can move off v0.32.0 without a commit in this repository. The v0.32.0 GPU documentation page still says the library contains pre-compiled CUDA 12.9 binaries, while the same version’s release table calls the default image CUDA 13.0. The release table is what names the tag to pull. After a future approved start, read `torch.version.cuda` inside that container and stop if it is not a CUDA 13.0 build.

Phase 2 preferred `vllm/vllm-openai:v0.32.0-cu129` because an Ampere host might still have a driver older than R580. This pod reports CUDA 13.2, so that preference does not apply here. The engine pin stays v0.32.0. Only the CUDA artifact changes.

NVIDIA lists both the RTX PRO 6000 Blackwell Server Edition and the Workstation Edition as compute capability 12.0 ([CUDA GPU Compute Capability](https://developer.nvidia.com/cuda/gpus)). Compute capability 12.0 is the SM 12.0 class. The v0.32.0 release notes include SM120 work: SM120 tensor-parallel configs, SM120 split-K attention, MXFP4 MoE on SM12x, and ModelOpt low-bit kernels on SM120/SM121. Those notes do not say this Kimi checkpoint was booted on an RTX PRO 6000. The v0.32.0 GPU page requires compute capability 7.5 or higher and names A100, H100, and B200 as examples. 12.0 is above 7.5. That page’s Blackwell sentence names B200 and GB200, which NVIDIA lists as compute capability 10.0, a different Blackwell from this card.

## 2. CUDA and driver compatibility

**VERIFIED** from the CUDA Toolkit 13.2 release notes:

- The CUDA driver is backward compatible. An application built for an older CUDA toolkit keeps working on a later driver.
- CUDA 13.x minor-compatibility drivers start at 580.
- CUDA 13.0 GA needs a Linux driver **>= 580.65.06**.
- CUDA 13.2 GA needs a Linux driver **>= 595.45.04**.
- The vLLM Docker guide says CUDA 13 images normally want an R580 or newer host driver.

**ESTIMATED.** If RunPod’s “Available CUDA version: 13.2” is the `nvidia-smi` CUDA Version field, the driver is new enough to run a CUDA 13.0 container, because a driver that advertises CUDA 13.2 is at or above 595.45.04, which is above the CUDA 13.0 floor of 580.65.06. A CUDA 13.0 image does not need a CUDA 13.2 image tag.

**UNKNOWN** until the first lines of `nvidia-smi` are copied:

- The exact Driver Version.
- Whether the reported 13.2 is that CUDA Version field or some other RunPod label.
- Whether this host is the Server Edition or the Workstation Edition. Both are compute capability 12.0 on NVIDIA’s table.

If the Driver Version is below 580.65.06, do not start the CUDA 13.0 image. That outcome would disagree with a genuine CUDA 13.2 report.

## 3. `KimiVLForConditionalGeneration`

**VERIFIED.** The v0.32.0 supported-models page lists `KimiVLForConditionalGeneration` for `moonshotai/Kimi-VL-A3B-Instruct` and `moonshotai/Kimi-VL-A3B-Thinking`. Inputs are text and image. Pipeline parallel is marked. The LoRA column is empty.

The checkpoint pin is unchanged: commit `398eede0903cd983a2bfa0cc634e9ac1d843f375`. Hub `usedStorage` is `32,822,309,117` bytes, **30.57 GiB**, in 7 safetensor shards. Re-read that commit on the Hub immediately before any future download.

Listing the architecture is not a boot result. Whether the kernels this model needs, including its MLA-style attention and MoonViT head size 72, are implemented for compute capability 12.0 in v0.32.0 is **UNKNOWN**.

## 4. `--language-model-only`

**VERIFIED.** Both of these say the same thing:

- v0.32.0 engine arguments: `--language-model-only` defaults to false. When set, it disables multimodal inputs by setting every modality limit to 0. That is the same as `--limit-mm-per-prompt` of 0 for every modality.
- Tag `v0.32.0` file `vllm/config/multimodal.py`: `language_model_only` defaults to false, with that same comment, and `get_limit_per_prompt` returns 0 when the flag is set.

The flag is supported. It does not, in that text, say the vision tower is left unloaded. Whether v0.32.0 skips the vision-encoder profile that ran out of memory on an 80 GB GPU in v0.8.5 is **UNKNOWN**. The first boot still sets the flag. The POC does not send images.

## 5. Launch arguments

**VERIFIED** on the v0.32.0 engine-arguments page, unless noted:

| Argument | Documented meaning | First-boot value |
| --- | --- | --- |
| `--revision` | Hugging Face branch, tag, or commit | `398eede0903cd983a2bfa0cc634e9ac1d843f375` |
| `--served-model-name` | Name returned by the API | `kimi-vl` |
| `--trust-remote-code` | Allow Hub code for this checkpoint. Default false | set |
| `--tensor-parallel-size` | Default 1 | `1` |
| `--language-model-only` | Disable multimodal inputs. Default false | set |
| `--enforce-eager` | Disable CUDA graphs and `torch.compile`. Default false | set |
| `--max-model-len` | Context length. If omitted, taken from the model config, which is 131072 for this checkpoint | `2048` |
| `--max-num-seqs` | Maximum sequences in one iteration | `1` |
| `--gpu-memory-utilization` | Fraction of GPU memory for this process. Default **0.92** | `0.90` |
| `--host` and `--port` | Accepted by `vllm serve`. The v0.32.0 server-arguments example uses `host: "127.0.0.1"` and a `port` key | `127.0.0.1` and `8000` |

`--max-model-len 2048`, `--max-num-seqs 1`, and `--gpu-memory-utilization 0.90` are POC choices. They are not measured limits. Moonshot’s example context of 32768, and the checkpoint maximum of 131072, stay out of the first boot.

The v0.32.0 server page does not print the default of `--host` when the flag is omitted. Set it. Do not rely on the template default. A template that listens on `0.0.0.0` and publishes port 8000 would expose the API.

96 GB is larger than the 80 GB card that loaded the weights and then failed in the old vision profile. That is arithmetic headroom, **ESTIMATED**, not a successful boot. Keep the conservative flags for the first start. Raise `--max-model-len` only after a start that prints a KV-cache allocation. If the process is killed for memory, stop. Do not quantize, do not remove `--language-model-only`, and do not change the model in that session.

## 6. SSH tunnel

Laravel stays on the Windows machine. vLLM stays on the pod. The browser never talks to vLLM.

**VERIFIED** from RunPod’s SSH guide ([Connect to a Pod with SSH](https://docs.runpod.io/pods/configuration/use-ssh)):

- Key authentication is the documented method. Add the public key on the RunPod credentials page before connecting.
- Basic SSH is proxied through `ssh.runpod.io`. The guide says that path does not support SCP or SFTP. It does not say whether local port forwarding (`-L`) works. Treat forwarding over the proxy as **UNKNOWN** until one attempt is made.
- Full SSH is a normal SSH daemon reached by a public IP and a mapped port. The documented command looks like `ssh root@POD_IP -p SSH_PORT -i ~/.ssh/id_ed25519`. That path can carry a local forward. It requires TCP port 22 exposed and `sshd` running. Official RunPod templates often start `sshd`. The upstream `vllm/vllm-openai` image is not one of those templates, so port 22 and `sshd` must be confirmed on this template. That confirmation is **UNKNOWN** until the template editor is read.
- RunPod’s guide also links a third-party password-setup script. This POC does not use it.

Secure configuration for this POC:

1. Change the image from `:latest` to `vllm/vllm-openai:v0.32.0`.
2. Do not add port 8000 to the pod’s exposed HTTP or TCP ports. Do not turn on a public HTTP proxy for vLLM.
3. Start vLLM with `--host 127.0.0.1 --port 8000`.
4. From Windows, after the pod prints its SSH command, forward only that loopback port. For the documented full-SSH form:

```text
ssh -N -L 8000:127.0.0.1:8000 root@POD_IP -p SSH_PORT -i PATH_TO_PRIVATE_KEY
```

Replace `POD_IP`, `SSH_PORT`, and the key path with the values on the pod’s Connect tab. If the only command offered is the proxied `ssh.runpod.io` form, try the same `-N -L 8000:127.0.0.1:8000` prefix on that command. If the proxy rejects the forward, use the documented TCP 22 path and still leave port 8000 unpublished.

5. Check from inside the pod that `127.0.0.1:8000` answers. Then check from Windows through the tunnel. Laravel’s base URL for that session is `http://127.0.0.1:8000/v1`.
6. The private key stays outside this repository.

**UNKNOWN.** Whether Windows OpenSSH is installed (`ssh -V`). Whether this template’s SSH session and the vLLM process share one network namespace. They do on a normal single-container pod. Confirm with a loopback request from the SSH session before opening the tunnel.

## 7. Weights on `/workspace`

**VERIFIED** Hugging Face cache rules:

- The default Hub cache is `~/.cache/huggingface/hub`.
- `HF_HOME` defaults to `~/.cache/huggingface`. The Hub cache is then `$HF_HOME/hub`, and the Xet cache is `$HF_HOME/xet`.
- `HF_HUB_CACHE` overrides the Hub directory.
- Those variables are read when `huggingface_hub` is imported. Set them in the pod environment before `vllm serve` starts. Setting them after the process has started does nothing.

**VERIFIED** RunPod disk rules, from the Phase 3 reading of RunPod’s pod pricing docs:

- Container disk is billed while the pod runs, erased when the pod stops, and is not billed while stopped.
- Volume disk survives a stop and is billed until the pod is deleted. The stopped rate is USD 0.20/GB/month, twice the running volume rate.
- Deleting the pod is what ends volume billing. Stop is not the end state.

On this pod the volume is already mounted at `/workspace`. A second Docker bind mount is unnecessary if the environment points at that mount:

```text
HF_HOME=/workspace/huggingface
```

That places Hub files at `/workspace/huggingface/hub` and Xet files at `/workspace/huggingface/xet`. Both are on the volume, so they survive a container restart and a pod stop. They do not survive deleting the pod.

The checkpoint is a public model. Do not put an `HF_TOKEN` in this repository. If a token is used later, it belongs only in the pod environment.

**ESTIMATED disk fit:**

| Store | Size in the plan | Checkpoint, 32,822,309,117 bytes |
| --- | --- | --- |
| Container disk | 30 GB | Does not fit. 30 GiB is 32,212,254,720 bytes, about 0.57 GiB short of the Hub byte count. A 30 GB decimal disk is shorter still. The image and the writable layer also live on this disk |
| Volume disk | 120 GB | Fits. 120 GB decimal is about 111.8 GiB. One 30.57 GiB snapshot leaves on the order of 80 GiB before Xet chunks and a partial re-download |

Leave the container disk at the smallest size the form allows. Put the model only under `/workspace`. If `HF_HOME` is left at the default, vLLM will try to cache under the container home and the download will not fit.

## 8. Image and startup command

These are the documented image and the documented arguments. They were not executed.

Template image:

```text
vllm/vllm-openai:v0.32.0
```

Pod environment:

```text
HF_HOME=/workspace/huggingface
```

Process command, after a separate spend approval and after `nvidia-smi` shows a driver that can run CUDA 13.0:

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

The official Docker example is `docker run` with `--gpus all`, a Hugging Face cache mount, `--ipc=host`, and the arguments after the image name. RunPod already starts the container, so that `docker run` is not a second command to execute on the pod. A bare RunPod start command beginning with `--model` is passed to the image entrypoint `vllm serve`. That starts vLLM and does not start SSH. The object to paste is [../deployment/runpod-start-command.json](../deployment/runpod-start-command.json), explained in [runpod-ssh-readiness.md](runpod-ssh-readiness.md). It is prepared and not deployed.

`--ipc=host` is in the official Docker example because PyTorch uses shared memory. Whether the RunPod form exposes that flag is **UNKNOWN**. Tensor parallel is 1 for this boot, which is the case the Docker note associates with that flag. If the process fails on shared memory, record the error and stop. Do not switch images to fix it inside an unplanned session.

## 9. Remaining unknowns

Resolve these before paying, or treat them as stop conditions on the first boot:

| Unknown | Why it matters |
| --- | --- |
| `nvidia-smi` Driver Version and CUDA Version lines | The CUDA 13.2 figure is reported, not pasted. The CUDA 13.0 image needs a driver >= 580.65.06 |
| `torch.version.cuda` inside `vllm/vllm-openai:v0.32.0` | The release table says CUDA 13.0. The versioned GPU page still says pre-compiled CUDA 12.9 binaries |
| Boot of this checkpoint on compute capability 12.0 | SM120 notes in v0.32.0 do not name Kimi-VL, MLA, or MoonViT head size 72 |
| Whether `--language-model-only` skips the vision profile | The flag disables multimodal inputs. It does not document skipping vision weights |
| RunPod template entrypoint, `sshd`, and exposed ports | `:latest` must be replaced. Port 8000 must stay unpublished. Port 22 is required only if the proxy rejects `-L` |
| Live Secure Cloud price, tax, and FX on the deploy form | Not copied for this SKU in the Phase 3 sheet. The form overrides any older list. The USD 12 / 5 OMR deposit cap was for the A100 plan |
| Exact Xet overhead on a 120 GB volume | One snapshot fits. A partial download plus Xet chunks was not measured |
| Windows OpenSSH | Needed for the tunnel. Check with `ssh -V` |
| Cold-start time and Arabic quality | Out of scope for a compatibility review |

## 10. What this review does not do

- It does not rent the pod, start it, or approve a card charge.
- It does not download weights.
- It does not change Laravel or any application code. None exists yet.
- It does not replace the Phase 3 recommendation. That recommendation remains a Community Cloud A100 PCIe 80 GB, conditional on checkout. This Blackwell pod is a separate configuration and needs its own written spend approval, with the GPU, the 30 GB container disk, the 120 GB volume, the hourly rate from the form, the maximum hours, and the maximum card total.

If a later approval uses this pod, the end state is still to delete the pod the same day. Stopping it keeps the volume and keeps the volume charge.

## Sources

| Source | What it settled |
| --- | --- |
| [vLLM v0.32.0 release](https://github.com/vllm-project/vllm/releases/tag/v0.32.0) | Official image tags. No CUDA 13.2 tag. SM120 kernel notes |
| [v0.32.0 supported models](https://docs.vllm.ai/en/v0.32.0/models/supported_models.html) | `KimiVLForConditionalGeneration` for this model id |
| [v0.32.0 engine arguments](https://docs.vllm.ai/en/v0.32.0/configuration/engine_args/) | Flags in the startup command, including `--language-model-only` and the 0.92 memory default |
| [v0.32.0 server arguments](https://docs.vllm.ai/en/v0.32.0/configuration/serve_args/) | `host` and `port`, with a loopback example |
| [v0.32.0 `multimodal.py`](https://github.com/vllm-project/vllm/blob/v0.32.0/vllm/config/multimodal.py) | `language_model_only` behavior in the pinned tag |
| [v0.32.0 GPU page](https://docs.vllm.ai/en/v0.32.0/getting_started/installation/gpu.html) | Compute capability >= 7.5. Stale-looking CUDA 12.9 sentence beside the release table |
| [vLLM Docker guide](https://docs.vllm.ai/en/stable/deployment/docker/) | `:latest` is an example only. Cache mount pattern. CUDA 13 wants R580+ |
| [CUDA Toolkit 13.2 release notes](https://docs.nvidia.com/cuda/cuda-toolkit-release-notes/index.html) | Driver floors and backward compatibility |
| [NVIDIA CUDA GPUs](https://developer.nvidia.com/cuda/gpus) | RTX PRO 6000 Blackwell Server and Workstation editions are compute capability 12.0 |
| [Hugging Face Hub cache](https://huggingface.co/docs/huggingface_hub/en/package_reference/environment_variables) | `HF_HOME`, `HF_HUB_CACHE`, `HF_XET_CACHE` |
| [RunPod SSH](https://docs.runpod.io/pods/configuration/use-ssh) | Key-based proxy SSH and full SSH |
| [docs/gpu-pricing-analysis.md](gpu-pricing-analysis.md) | Container disk erased on stop. Volume billed until the pod is deleted. Phase 3 approval was a different GPU |
| [docs/model-compatibility-findings.md](model-compatibility-findings.md) | Checkpoint SHA, 30.57 GiB Hub size, and the v0.8.5 80 GB vision-profile failure |
