# Implementation plan

Status: 22 October 2026. The Laravel and React application is implemented locally and tested with mocked RunPod responses. The operator reported a completed Serverless smoke test on endpoint `f6icz11anfucte`. This application has not called that endpoint. No GPU configuration was changed by the application work. Phases 8 through 10 have not started. A completed smoke test is not proof that the pinned revision was loaded.

The order below is the default. Phase 7 has no cloud cost, so it may run in parallel with Phases 2 and 3 if that parallel start is explicitly approved. Phases 4 and 5 spend money and stay blocked until their approval gates pass.

Cursor tasks are work an agent can do in this repository or as read-only research. Manual tasks require a person at the accounts, the billing page, or the SSH session. A Cursor task never creates a paid resource.

## Phase summary

| Phase | Name | Spend | Gate to leave the phase |
| --- | --- | --- | --- |
| 1 | Planning and architecture | None | Approval to start Phase 2 |
| 2 | Model compatibility verification | None | Written go, conditional go, or no-go |
| 3 | GPU provider and cost validation | None | Approval naming SKU, disk, max hours, and max spend |
| 4 | RunPod server setup | Yes, after the Phase 3 approval | Pod reachable by SSH, vLLM not required yet |
| 5 | Kimi deployment | Uses the Phase 4 budget | vLLM healthy on pod loopback |
| 6 | Direct inference verification | Uses the same budget | One English and one Arabic completion captured, then GPU stopped unless Phase 8 is immediate |
| 7 | Laravel + React application | None | Chat UI works against a fake inference client |
| 8 | Secure integration | GPU time again, inside the same ceiling | Browser turn answered through the SSH tunnel |
| 9 | Functional, security, and performance testing | Only if the pod is still inside the approval | Acceptance checks recorded |
| 10 | Demonstration and report | None after shutdown | Report accepted and billing confirmed stopped |

## Phase 1 — Project planning and architecture

**Objective.** Agree the scope, architecture, phase gates, and the checks required before any rental.

**Prerequisites.** The approved brief. An empty workspace.

**Cursor tasks.**

- Inspect the workspace.
- Write the README, requirements, architecture, this plan, the compatibility checklist, the future-expansion note, and `.gitignore`.
- Record public evidence gathered on 21 October 2026, labeled as observed or assumed.

**Manual tasks.** Read the documents and approve Phase 2, or send corrections.

**Deliverables.** The files listed in the README. No `vendor/`, `node_modules/`, weights, or cloud resources.

**Budget checkpoint.** Spend remains zero.

**Approval gate.** Explicit approval to begin Phase 2. Silence is not approval.

**Risks.**

| Risk | Mitigation |
| --- | --- |
| Planning documents drift into unapproved implementation | Phase 1 stops at documentation. |
| A desk-review number is later treated as a measured GPU fit | Every unmeasured claim is labeled Assumption. |

## Phase 2 — Official Kimi model compatibility verification

**Objective.** Decide whether `moonshotai/Kimi-VL-A3B-Instruct` has a documented vLLM path whose memory floor can fit a GPU class that Phase 3 might price under 20 OMR.

**Prerequisites.** Phase 1 approval. No RunPod account action is required.

**Cursor tasks.** Read-only:

1. Re-read the Hugging Face model card and the [MoonshotAI/Kimi-VL README](https://github.com/MoonshotAI/Kimi-VL/blob/main/README.md). Record disagreements, including dependency versions.
2. Re-fetch the model API metadata and `config.json`. Record the commit SHA, parameter count, dtype, file count, and `usedStorage`.
3. On the [vLLM supported models page](https://docs.vllm.ai/en/stable/models/supported_models/), confirm `KimiVLForConditionalGeneration` still lists `moonshotai/Kimi-VL-A3B-Instruct`. Identify a released vLLM version that contains that support. Pin that version. Do not use an unpinned main branch as the deployment target.
4. From that vLLM release’s install docs, record the CUDA and PyTorch versions it requires.
5. Record the README’s Transformers, Torch, and Python versions, and the `transformers_version` field in `config.json`.
6. Calculate the BF16 weight floor from the published parameter count. State the formula. Read attention fields in `config.json` and write a KV-cache note that respects the MLA-style fields (`kv_lora_rank`, `qk_rope_head_dim`, `v_head_dim`). If a reliable formula is not in current vLLM docs, say so and keep the weight floor as the only hard number.
7. Check the historical vLLM review notes for a memory-heavy attention fallback on this model, and state whether current vLLM docs still mention it.
8. Confirm whether Moonshot publishes quantized files for this repo. The file list observed in Phase 1 contains BF16 safetensors only.
9. List GPU VRAM classes that sit above the weight floor with spare memory for CUDA context and a short POC context. Do not name a rental SKU as verified to run.
10. Write `docs/model-compatibility-findings.md` with a recommendation: **Go** to Phase 3, **Conditional go** (a named fallback that still needs approval, such as an officially documented quantization path), or **No-go**.

**Manual tasks.** Decide the recommendation. If the recommendation is no-go, choose whether to investigate an official alternative engine, an official quantized checkpoint, or a different official Kimi checkpoint. Cursor presents alternatives and does not switch model or engine on its own.

**Deliverables.** `docs/model-compatibility-findings.md`. Updated checklist statuses.

**Budget checkpoint.** Spend remains zero. No weights are downloaded onto the workstation or a server.

**Approval gate.** Written acceptance of the Phase 2 recommendation before Phase 3 pricing is treated as actionable. A conditional go names the condition in the approval.

**Risks.**

| Risk | Mitigation |
| --- | --- |
| Documentation disagrees about whether vLLM support is merged | Pin a release and cite the file or docs page inside that release. |
| Activated-parameter count is misread as the VRAM requirement | Planning uses the full BF16 parameter bytes. MoE expert offload is unsupported unless Phase 2 finds official vLLM support for it. |
| Upstream `--max-model-len 32768` is copied into the POC | The findings recommend a short context and leave the flag unset until memory headroom is estimated. |
| Quantization is adopted silently to force a 24 GB card | Quantization is a separate approval. Official weights observed so far are BF16. |

## Phase 3 — GPU provider and cost validation

**Objective.** Produce a rental proposal that fits the Phase 2 memory floor and the budget, still without buying it.

**Prerequisites.** An accepted Phase 2 go or conditional go.

**Cursor tasks.**

- Read RunPod’s own pricing page and the SKU page for each candidate. Third-party price boards are leads, not quotes.
- Build a cost sheet: on-demand hourly GPU price, container disk, network volume, charges that continue while a pod is stopped, and any tax or minimum charge shown by RunPod.
- Convert the sheet to OMR using the CBO peg of USD 2.6008 per OMR, and show the USD total as well. Note that a card issuer may add FX fees on top.
- Estimate a first session: disk large enough for about 31 GiB of weights plus the container and CUDA image, one download, one cold start, the Phase 6 prompts, and time to shut down. The planning allowance for disk is **at least 100 GB**. That allowance is an **assumption** until RunPod’s image sizes are checked.
- Propose one primary SKU and one fallback SKU. Exclude any SKU whose VRAM is at or below the BF16 weight floor.
- Propose a first authorization that covers that session and leaves unused ceiling for a second attempt. The hard ceiling stays below 20 OMR. The first authorization should cover only the hours and disk required for Phases 4 through 6.

**Manual tasks.**

- Confirm the RunPod account, payment method, and whether Community Cloud or Secure Cloud is acceptable.
- Approve the SKU, region, disk size, maximum hours, maximum USD, and maximum OMR in writing.
- Set a billing alert at or below that maximum if RunPod provides one.

**Deliverables.** `docs/gpu-cost-validation.md` and the written spend approval.

**Budget checkpoint.** No pod, volume, or template is created in this phase. If the cheapest SKU that clears the memory floor cannot finish Phases 4 through 6 under 20 OMR including disk, stop and return to the user with options.

**Approval gate.** A spend approval that names provider, SKU, VRAM, disk, max hours, max money, and the shutdown owner. A proposal document alone is not approval.

**Risks.**

| Risk | Mitigation |
| --- | --- |
| A community price in a blog is stale at checkout | The approval uses the price visible in the RunPod UI on the approval day. |
| Disk charges continue after the GPU is stopped | The cost sheet includes stopped-state storage, and Phase 10 deletes it unless the approval keeps it. |
| FX or tax pushes the total over 20 OMR | The authorized USD cap is set low enough to absorb the fees the user expects, or the user lowers the hour cap. |

## Phase 4 — RunPod server setup

**Objective.** Have an SSH-accessible pod with enough disk, with vLLM not yet required.

**Prepared ahead of this phase, not deployed.** `deployment/runpod-start-command.json` is the Container start command for the configured Secure Cloud RTX PRO 6000 pod. It starts a locked-down `sshd` and then execs vLLM, because the official vLLM image does not start SSH. It is not pasted into RunPod. No pod has been created. Phase 4 still waits for a spend approval. The command is documented in `docs/runpod-ssh-readiness.md`.

**Serverless files are prepared, not deployed.** `deployment/serverless/` holds the official worker environment, scale settings, a text-only request, and a local validation script. `docs/serverless-first-test-plan.md` is the paid smoke test. Those files do not replace Phase 4 and do not authorize an endpoint. A Serverless test still needs its own approval. The first-test cap is USD 5, which is about 1.92 OMR at the CBO peg, inside the earlier sub-5 OMR evaluation cap. Max workers of 1 is not that spending cap. The Pod startup files stay unchanged as the fallback.

**Prerequisites.** The Phase 3 spend approval. A local SSH key pair created by the user. The private key stays outside this repository.

**Cursor tasks.**

- Write `docs/runpod-setup.md` as a runbook the operator follows: pod settings, loopback-only plan for port 8000, disk size, and the stop procedure.
- Review commands before the operator runs them.
- Record the pod id, GPU name, VRAM, disk, and hourly price the UI actually showed.

**Manual tasks.**

- Create the pod and volume inside the approved limits.
- Install the SSH public key.
- Confirm SSH login.
- Confirm the GPU name and total memory with the vendor tool on the pod.
- Start a timer. Stop the pod if setup stalls past the approved hour budget.

**Deliverables.** A reachable pod. `docs/runpod-setup.md` filled with the actual SKU and price. A record of GPU memory from the pod.

**Budget checkpoint.** Compare elapsed charge against the approval before Phase 5 starts. If the download plus a short test no longer fits, stop the pod and ask for a new approval.

**Approval gate.** Continue to Phase 5 only if SSH works and the remaining approved budget covers the download and a boot attempt.

**Risks.**

| Risk | Mitigation |
| --- | --- |
| The assigned host differs from the approved SKU | Do not start the download. Stop and re-approve. |
| A template exposes a public notebook or HTTP port | Disable public exposure of inference. SSH is the remote access path. |

## Phase 5 — Kimi model deployment

**Objective.** Load the pinned official weights into the pinned vLLM release and serve them on loopback.

**Prerequisites.** Phase 4 gate. Phase 2 commit SHA and vLLM version.

**Cursor tasks.**

- Write `docs/kimi-deploy.md` with the exact pinned versions, the bind address `127.0.0.1`, the short `--max-model-len` from Phase 2, a supported way to refuse image inputs for this text-only POC, and the stop command.
- Keep remote-code loading limited to the pinned official repository.
- Do not invent CUDA flags when the pinned release docs already specify them.

**Manual tasks.**

- Install the pinned vLLM and its documented CUDA stack on the pod.
- Download `moonshotai/Kimi-VL-A3B-Instruct` at the pinned SHA onto the pod disk.
- Start vLLM bound to `127.0.0.1`.
- Watch memory. If the process is killed or the GPU is out of memory, stop and return the log. Do not switch models.

**Deliverables.** A loopback vLLM process, the resolved commit SHA, the vLLM version, and the startup log.

**Budget checkpoint.** After a successful load, note the time spent. After a failed load, stop the pod before experimenting.

**Approval gate.** The startup log shows the model loaded and the server listening on loopback. An out-of-memory result is a stop. Alternatives go back to the user.

**Risks.**

| Risk | Mitigation |
| --- | --- |
| The weight download is slow enough to exhaust the hour cap | Prefer a persistent volume so a retry does not download twice, and include that volume’s price in the approval. |
| Flash-attention, recommended by the upstream README to reduce memory, fails to build | Record the failure. A retry uses the documented fallback only if it still fits the approved GPU. |
| The process binds to all interfaces | The runbook checks the listen address before any client test. |

## Phase 6 — Direct AI inference API verification

**Objective.** Prove the pod answers text before Laravel is connected.

**Prerequisites.** Phase 5 gate. The GPU is still running inside the approval.

**Cursor tasks.**

- Provide the requests for the operator to run on the pod against `http://127.0.0.1:8000/v1/chat/completions`.
- One prompt in English and one in Arabic. Text only.
- Capture latency from the client timing and a GPU memory sample while the model is loaded.
- Write the results into `docs/inference-verification.md`.

**Manual tasks.**

- Run the prompts on the pod.
- Record the GPU memory sample.
- Stop the GPU after the evidence is saved, unless the user explicitly continues into Phase 8 in the same session.

**Deliverables.** Two completions, or a precise upstream error. Latency and GPU memory. Confirmation that the request never left the pod.

**Budget checkpoint.** Stopping here is the default. Storage may still bill. The notes say whether the volume was kept.

**Approval gate.** The completions are from this pod’s vLLM. Then the user approves either stopping until the Laravel app is ready, or continuing straight to Phase 8.

**Risks.**

| Risk | Mitigation |
| --- | --- |
| A public proxy URL is used because it is convenient | The only accepted URL in this phase is loopback on the pod. |
| Arabic output is weak and is misread as a failed deployment | Record the output. A completed generation still verifies inference. Quality is reported separately. |

## Phase 7 — Unified Laravel and React application

**Objective.** Build the chat application in this repository so it runs without a GPU, using a fake inference client in tests.

**Prerequisites.** Phase 1 approval is enough to start the code. An accepted Phase 2 pin is useful so environment examples match the real model name, and it is not required to scaffold the UI.

**Cursor tasks.**

- Initialize Laravel 13 with the React Inertia starter in the existing repository root, preserving `README.md`, `docs/`, and `.gitignore`.
- Add `ChatController`, `StoreChatMessageRequest`, `ChatService`, `KimiService`, and `config/kimi.php` as designed in the architecture.
- Add the chat page, with empty, loading, and error states.
- Use file session and file cache.
- Add a feature test that fakes the HTTP client. The test does not call a network model.
- Run the test suite and a local browser pass of the chat screen against the fake client or a closed endpoint so the error state is visible.

**Manual tasks.**

- Confirm PHP 8.3 or newer and a current Node.js are installed on the workstation before initialization. Cursor checks versions and stops if they are missing.
- Review the UI in the browser.

**Deliverables.** The running unified application. Feature tests. `.env.example` containing the `KIMI_*` keys and no secrets.

**Budget checkpoint.** None.

**Approval gate.** The user can click through the chat UI locally, and the feature test passes without a GPU. Integration with the real model waits for Phase 8.

**Risks.**

| Risk | Mitigation |
| --- | --- |
| Project creation replaces the docs | Initialize in a way that keeps the existing root files, and review `git status` before proceeding. |
| The starter kit enables a database-backed session | Switch session and cache to the file drivers before any chat work. |
| A second frontend directory is introduced | Pages stay under `resources/js`. |

## Phase 8 — Secure integration with the AI server

**Objective.** Send a browser message through Laravel, the SSH tunnel, and vLLM, and show the reply.

**Prerequisites.** Phase 6 evidence. Phase 7 application. Remaining budget inside the written approval. A fresh approval is required if the previous hour cap was consumed.

**Cursor tasks.**

- Document the Windows OpenSSH local-forward command in `docs/secure-tunnel.md`, with the pod user and host as placeholders the operator fills.
- Set `.env` locally, never committed, to `http://127.0.0.1:8000/v1`.
- Verify the listen path end to end once the operator has the tunnel open.
- Confirm from outside the pod that port 8000 is not publicly open.

**Manual tasks.**

- Start the pod if it was stopped.
- Start vLLM on loopback.
- Open the SSH tunnel from Windows.
- Keep the session inside the approved hours.

**Deliverables.** One English and one Arabic turn from the browser. A note of the tunnel command shape, without private keys. The public-port check.

**Budget checkpoint.** Stop the GPU when the demo turns are saved if Phase 9 will happen later.

**Approval gate.** The browser reply matches a pod-side vLLM log line for that request.

**Risks.**

| Risk | Mitigation |
| --- | --- |
| Laravel on Windows cannot open SSH | Phase 8 checks `ssh` first. If it is missing, the user installs the Windows OpenSSH client before any further GPU time. |
| The tunnel drops and the UI hangs | The configured timeout returns the error state. |

## Phase 9 — Functional, security, and performance testing

**Objective.** Execute the acceptance criteria in [requirements.md](requirements.md).

**Prerequisites.** Phase 8 gate. GPU time only for the live inference cases, and only inside the approval.

**Cursor tasks.**

- Feature tests for validation, session capping, clear, and a faked upstream failure.
- A browser pass of empty, loading, success, validation error, and upstream error.
- A security pass: no secrets in the repo, CSRF present, vLLM not public, logs free of the API key.
- Record latency from the UI and a GPU sample from the host.

**Manual tasks.**

- Run the live Arabic and English session.
- Capture the GPU sample.
- Try the closed-tunnel error path.

**Deliverables.** `docs/test-notes.md` mapped to requirement ids.

**Budget checkpoint.** If the remaining approval cannot cover the live cases, run the offline tests and ask before starting the GPU.

**Approval gate.** Acceptance items pass, or the notes list a specific gap and the user decides whether that gap ends the POC.

**Risks.**

| Risk | Mitigation |
| --- | --- |
| Live tests expand into exploratory load testing | One user, a handful of turns, then shut down. |
| A shared report contains more prompt text than intended | Test notes store prompts only as far as the user wants them in the report. |

## Phase 10 — Final demonstration and technical report

**Objective.** Demonstrate the path and leave a report a reviewer can audit.

**Prerequisites.** Phase 9 notes.

**Cursor tasks.**

- Write `docs/poc-report.md`: objective, what was rented, pinned versions, architecture, evidence of self-hosting, latency, GPU memory, cost, limitations, and the shutdown record.
- Keep unsupported claims labeled. A failed GPU fit is reported as a result.

**Manual tasks.**

- Present the demo, or run the recorded path once more inside the remaining budget.
- Stop the pod.
- Delete the volume unless the approval keeps it.
- Capture the provider balance or invoice lines.

**Deliverables.** The report. Billing evidence. A stopped compute resource.

**Budget checkpoint.** Final total below 20 OMR. If it is not, the report says so plainly.

**Approval gate.** The user accepts the report. That acceptance ends the POC.

**Risks.**

| Risk | Mitigation |
| --- | --- |
| A forgotten volume keeps billing after the demo | The report’s last section is the shutdown checklist, completed by the operator. |
| The report claims a GPU result that was only a desk estimate | The report cites Phase 6 artifacts for any runtime claim. |

## Budget checkpoints

| Checkpoint | When | Pass condition |
| --- | --- | --- |
| B0 | End of Phase 1 | No account charges from this project. |
| B1 | End of Phase 2 | Still zero. A memory floor exists. |
| B2 | Phase 3 approval | Authorized spend is below 20 OMR and names SKU, disk, and hours. |
| B3 | Before Phase 5 | Remaining authorization covers download and one boot. |
| B4 | After Phase 6 | GPU stopped by default. Storage decision recorded. |
| B5 | Before Phase 8 or 9 live tests | Remaining authorization covers those tests. |
| B6 | Phase 10 | Compute stopped, unwanted disk deleted, total below 20 OMR. |

At the published peg, 20 OMR is about USD 52.02. The running total is kept in USD as RunPod bills it, and in OMR using that peg. Issuer FX fees count toward the ceiling.

## Approval gates

The user explicitly approves each of these before the next action:

1. Start Phase 2.
2. Accept the Phase 2 go, conditional go, or no-go.
3. Authorize a specific RunPod spend.
4. Continue from Phase 6 into later GPU time, or stop the GPU first.
5. Accept the final report.

Changing the model id or leaving vLLM for another engine requires a new Phase 2 style note and a new approval. That change is not made silently during a later phase.

## Exact next step

After approval of Phase 1, start **Phase 2 only**:

Write `docs/model-compatibility-findings.md` from the read-only checks listed in Phase 2. Do not run `composer create-project`. Do not install npm packages. Do not download weight files. Do not create a RunPod resource. Finish by stating go, conditional go, or no-go, and wait.
