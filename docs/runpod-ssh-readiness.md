# RunPod SSH readiness

Status: startup command prepared on 21 October 2026. It has not been pasted into RunPod, and the pod has not been deployed. Nothing was installed, rented, or started. This note does not approve spend.

**Prepared, not deployed.**

The official image `vllm/vllm-openai:v0.32.0` still does not start SSH. The command in [deployment/runpod-start-command.json](../deployment/runpod-start-command.json) is the value to paste into RunPod’s Container start command. It refuses to start unless `PUBLIC_KEY` is a real SSH public key, locks `sshd` to public-key root login, then replaces the shell with `vllm serve` on `127.0.0.1:8000`.

The readable copy of that shell script is [deployment/runpod-start.sh](../deployment/runpod-start.sh). Paste the JSON file, not the shell file. If the shell file is edited, the JSON must be regenerated from it before anyone pastes it.

Labels:

- **VERIFIED.** Read from an official RunPod or vLLM document, from the v0.32.0 Dockerfile, or checked on this machine without deploying.
- **ESTIMATED.** A consequence of those sources. Not observed on a running pod.
- **UNKNOWN.** Needs the saved template or a real pod.

## Configuration this command is for

| Setting | Value | Role |
| --- | --- | --- |
| Provider | RunPod Secure Cloud | Operator-reported. Not deployed from this work |
| GPU | NVIDIA RTX PRO 6000 Blackwell, 96 GB | Unchanged. A successful load is still unproven |
| Image | `vllm/vllm-openai:v0.32.0` | Entrypoint in the image is `vllm serve`. This command overrides it |
| Model | `moonshotai/Kimi-VL-A3B-Instruct` | Revision `398eede0903cd983a2bfa0cc634e9ac1d843f375` |
| Container disk | 30 GB | Too small for the checkpoint. Holds the image and the SSH package install |
| Volume | 120 GB at `/workspace` | Checkpoint cache |
| `HF_HOME` | `/workspace/hf_home` | Set in the pod environment and again by the script |
| HTTP ports | none | Stays empty. vLLM is not published |
| TCP ports | 22 | Mapped public SSH port. Internal listener is started by this command |
| Reported rate | about USD 2.51 per hour, GPU plus storage | Operator-reported. Not a checkout quote |

Four hours at that reported rate is about USD 10.04 before tax and card fees. That arithmetic does not approve a rental. The Phase 3 approval was a different GPU.

## What was wrong with the previous command

The previous JSON installed OpenSSH, wrote `authorized_keys` with `echo`, started `sshd` with its package defaults, and then exec’d vLLM. It did not check that `PUBLIC_KEY` existed, did not reject a private key, and did not turn off password authentication. An empty `PUBLIC_KEY` would still have started a daemon.

## Startup command

Paste the entire contents of [deployment/runpod-start-command.json](../deployment/runpod-start-command.json) into the Container start command field. The object has this shape, which is the shape RunPod’s template API accepts:

```json
{"entrypoint":["/bin/bash","-c"],"cmd":["<one shell script>"]}
```

**VERIFIED** against the template API: a JSON object sets the entrypoint and the command. A bare string that begins with `--model` is only the CMD and is passed to the image entrypoint `vllm serve`, so it cannot start SSH.

The script inside `cmd`, also stored as [deployment/runpod-start.sh](../deployment/runpod-start.sh), does the following in order. `set -euo pipefail` is on, so a failed check, `apt-get`, `sshd -t`, or a missing pid file exits before `exec`.

1. Exit if `PUBLIC_KEY` is unset or empty.
2. Exit if the value contains a private-key banner.
3. Accept only non-empty lines that start with `ssh-ed25519`, `ssh-rsa`, `ecdsa-sha2-nistp256`, `ecdsa-sha2-nistp384`, `ecdsa-sha2-nistp521`, `sk-ssh-ed25519@openssh.com`, or `sk-ecdsa-sha2-nistp256@openssh.com`, and whose key body is at least 20 characters of base64. Blank lines are ignored. RunPod may inject several keys, one per line.
4. Export `HF_HOME=/workspace/hf_home` and create that directory on the volume.
5. Install `openssh-server` if the image does not already have it. This image does not.
6. Stop a package-started `sshd` if `service` exists, then write `/root/.ssh/authorized_keys` as mode `600` under a directory of mode `700`.
7. Write a private `sshd` config and start `/usr/sbin/sshd -f` with that file, after `/usr/sbin/sshd -t` accepts it.
8. Exit if `/run/sshd.pid` is missing or empty.
9. `exec vllm serve` with the pinned model, revision, loopback bind, and the first-boot flags from [rtx-pro-6000-deployment-review.md](rtx-pro-6000-deployment-review.md). `exec` replaces the shell, so vLLM is the container’s main process. `sshd` is already a daemon and is left running.

The vLLM arguments are:

```text
--model moonshotai/Kimi-VL-A3B-Instruct
--revision 398eede0903cd983a2bfa0cc634e9ac1d843f375
--served-model-name kimi-vl
--trust-remote-code
--host 127.0.0.1
--port 8000
--tensor-parallel-size 1
--language-model-only
--enforce-eager
--max-model-len 2048
--max-num-seqs 1
--gpu-memory-utilization 0.90
```

## Security choices

| Control | Setting | Why |
| --- | --- | --- |
| Password login | `PasswordAuthentication no` | No password is set or stored |
| Challenge-response | `KbdInteractiveAuthentication no` and `ChallengeResponseAuthentication no` | Keyboard-interactive login is off |
| Root login | `PermitRootLogin prohibit-password` and `AllowUsers root` | Root can enter only with a public key |
| Methods | `AuthenticationMethods publickey` and `PubkeyAuthentication yes` | Public key is the only method |
| Agent forwarding | `AllowAgentForwarding no` | The client command also omits `-A` |
| TCP forwarding | `AllowTcpForwarding local` | Allows the Laravel tunnel (`ssh -L`) and refuses remote forwarding (`ssh -R`) |
| Extra forwards | `AllowStreamLocalForwarding no`, `PermitTunnel no`, `GatewayPorts no` | No sockets on other interfaces, no SSH VPN, no reverse tunnel |
| Key file | mode `700` directory, mode `600` file, `StrictModes yes` | `sshd` rejects a world-readable key file |
| vLLM | `--host 127.0.0.1 --port 8000` | The API is not on a public interface |
| HTTP ports | none | RunPod’s HTTP proxy is not used |
| Secrets in the command | none | `PUBLIC_KEY` is read from the environment at start. No private key and no password is in the file |

`UsePAM yes` stays because the Ubuntu OpenSSH package expects PAM for the session. Password and challenge-response are still off, and `AuthenticationMethods publickey` does not offer them.

The dedicated config file is passed with `sshd -f`. The command does not depend on the image’s default `sshd_config` include order.

## Windows public key

Do this on the Windows machine. Do not put the private key in this repository or in RunPod.

1. Check the client:

```powershell
ssh -V
```

Windows 10 and 11 include OpenSSH as an optional feature. If this command is not found, install the Windows OpenSSH Client feature before a future deploy. Whether it is installed on this PC was not checked.

2. If `~\.ssh\id_ed25519` does not already exist, create a key pair. A passphrase is recommended. The passphrase is local and is not sent to RunPod.

```powershell
ssh-keygen -t ed25519 -f "$env:USERPROFILE\.ssh\id_ed25519" -C "kimi-poc"
```

3. Print only the public key:

```powershell
Get-Content "$env:USERPROFILE\.ssh\id_ed25519.pub"
```

The line starts with `ssh-ed25519`. That is the value for RunPod. The file without `.pub` is the private key. Do not open it into the chat, the repository, or the RunPod form.

## Add the public key to RunPod

1. Open the RunPod account SSH public keys page.
2. Paste the single `ssh-ed25519 ...` line. If several keys are already there, each key stays on its own line.
3. Do not paste a fingerprint (`SHA256:...` by itself) and do not paste the private key.
4. Leave the pod environment without a `PUBLIC_KEY` variable. RunPod injects `PUBLIC_KEY` from the account keys. A value typed in the template replaces that injection.
5. Save the key before any future deploy. RunPod does not inject a key that is added after the container has already started.

## Create the tunnel

After a future deploy, copy the SSH-over-exposed-TCP command from the pod’s Connect tab. It looks like `ssh root@POD_IP -p MAPPED_PORT -i ...`. The public port is not 22. Add the forward and refuse password and agent forwarding:

```powershell
ssh -N -L 8000:127.0.0.1:8000 -o ExitOnForwardFailure=yes -o IdentitiesOnly=yes -o PasswordAuthentication=no -o PreferredAuthentications=publickey root@POD_IP -p MAPPED_PORT -i "$env:USERPROFILE\.ssh\id_ed25519"
```

Do not add `-A`. Leave that window open. Laravel’s base URL for the session is `http://127.0.0.1:8000/v1`.

Use the basic `ssh.runpod.io` command only as a fallback shell. RunPod documents that path as a proxy without SCP or SFTP. It is not the tunnel for Laravel.

## Verify SSH and vLLM

These checks happen only after an approved deploy. They were not run.

1. The container log shows the script passing the key check, then `sshd`, then vLLM loading. A log line that says `PUBLIC_KEY is missing` means the account key was not injected. Stop there and fix the key. Do not keep the GPU up while debugging blindly.
2. From the Windows tunnel window, there is no error. `ExitOnForwardFailure` makes SSH exit if port 8000 cannot be forwarded.
3. From a second Windows terminal:

```powershell
curl http://127.0.0.1:8000/v1/models
```

A JSON body that includes `kimi-vl` means the tunnel and vLLM are both up. A connection error while the tunnel window is open means vLLM is not listening yet. The first start also downloads the checkpoint onto `/workspace/hf_home`, so this can take a long time. The download duration is unknown.

4. On the pod, after SSH is up, `sshd` should be the daemon and `vllm serve` should be the main process. The API must answer on `127.0.0.1:8000` inside the pod before the tunnel is useful.

## Troubleshooting

| What you see | What it means |
| --- | --- |
| Saved command still begins with `--model` | The JSON object was not stored. Paste the file again. Do not append it to the old arguments |
| Log: `PUBLIC_KEY is missing or empty` | No account key, or `PUBLIC_KEY` was set empty in the template environment |
| Log: `not an accepted SSH public key` | The account field has a fingerprint, a private key, or a wrapped line |
| Log: `openssh-server did not create host keys` | The package install did not finish its key setup. The container exits on purpose |
| Log: `Bad configuration option` from `sshd -t` | The image’s OpenSSH rejected one keyword. vLLM does not start. Copy the option name and stop |
| Log: `sshd did not stay running` | The daemon exited after the config test. vLLM does not start |
| TCP 22 connection refused | `sshd` is not up, or the Connect tab port is stale after a reset |
| Tunnel exits immediately | Local port 8000 is already in use, or vLLM is not listening, or the mapped SSH port changed |
| `curl` hangs and then fails | vLLM is still downloading or loading. Wait for the log. Do not publish port 8000 to “test it” |
| Password prompt | The client is not using the key file. Repeat the tunnel command with `-i` and `IdentitiesOnly=yes` |

Do not replace this command with RunPod’s published `sleep infinity` SSH snippet. That snippet can start SSH and never starts vLLM, and it does not apply this `sshd` config.

## Compatibility

| Piece | Result |
| --- | --- |
| RunPod JSON entrypoint | **VERIFIED.** `entrypoint` `/bin/bash -c` and one `cmd` string match the template API |
| Image entrypoint | **VERIFIED.** v0.32.0 `vllm-openai` is `ENTRYPOINT ["vllm", "serve"]` and `USER root`. This command replaces that entrypoint so the shell can start `sshd` first |
| OpenSSH in the image | **VERIFIED** absent from the v0.32.0 Dockerfile. The script installs it |
| Base OS | **VERIFIED** Dockerfile default `nvidia/cuda:13.0.3-base-ubuntu24.04`. The published tag’s actual base was not inspected inside a registry manifest in this step |
| RTX PRO 6000 | Compute capability 12.0 is **VERIFIED** on NVIDIA’s list. A boot of this checkpoint on that GPU is **UNKNOWN** |
| vLLM flags | **VERIFIED** on the v0.32.0 engine and server argument pages, as recorded in the deployment review |

## Local validation

Done on this machine. The pod was not started.

| Check | Result |
| --- | --- |
| JSON parses | Pass. One object, entrypoint `/bin/bash` and `-c`, one `cmd` string |
| Decoded `cmd` equals `deployment/runpod-start.sh` | Pass, after normalizing Windows newlines |
| File encoding | UTF-8 without a byte-order mark. The file is one line |
| `bash -n` on the decoded script | Pass. Syntax only. The script was not executed |
| Private keys, password assignments, `0.0.0.0` | Absent |
| Embedded public key | Absent |

PowerShell’s JSON encoder writes some characters as `\u003e` and similar escapes. They decode back to the shell script. Paste the file as it is. Do not retype it.

## Still unknown without a real pod

- `nvidia-smi` on this host, and whether vLLM loads Kimi-VL on compute capability 12.0.
- Whether `sshd -t` accepts every keyword against the OpenSSH package `apt-get` installs in this image. A rejected keyword stops the container before vLLM.
- Whether the package’s post-install script starts `sshd` before the locked config is written, and whether `service ssh stop` stops it. If the port stays busy, the locked `sshd` fails and vLLM does not start.
- Whether RunPod’s editor stores this JSON as an entrypoint override. After a save, the template must show `/bin/bash` and `-c`. A saved value that still begins with `--model` was not stored.
- Whether `PUBLIC_KEY` on a real start matches the account key. That value is not in this repository.
- Windows `ssh -V` on this PC.
- The live checkout price. USD 2.51 per hour is the figure reported for this form, including storage. Tax and the card total are still unknown.
- Download time, Arabic quality, and the vision-profile behavior of `--language-model-only`.

## Manual steps in RunPod

Do not deploy.

1. Add the Windows `id_ed25519.pub` line to the account SSH keys.
2. Leave the image at `vllm/vllm-openai:v0.32.0`.
3. Leave HTTP ports empty. Leave TCP port 22 exposed.
4. Leave the volume at `/workspace` and the environment variable `HF_HOME=/workspace/hf_home`. Do not add `PUBLIC_KEY` to the environment.
5. Replace the Container start command with the full contents of `deployment/runpod-start-command.json`.
6. Save, then read the field back. It must be the JSON object, with entrypoint `/bin/bash` and `-c`. If it is a bare `--model` list, it is not saved.
7. Stop. Deploy only after a separate approval that names this GPU, the 30 GB container disk, the 120 GB volume, the hourly rate on the form, the maximum hours, and the maximum card total.

## Sources

| Source | What it settled |
| --- | --- |
| [Create a template](https://docs.runpod.io/api-reference-v2/templates/create-a-template) | JSON entrypoint form. `PUBLIC_KEY` injection |
| [Connect to a Pod with SSH](https://docs.runpod.io/pods/configuration/use-ssh) | Proxy SSH versus exposed TCP. The published install snippet is not this command |
| [Expose ports](https://docs.runpod.io/pods/configuration/expose-ports) | TCP 22 is a mapped public port. HTTP proxy publishes the service |
| [Connect to a Pod with VS Code or Cursor](https://docs.runpod.io/pods/configuration/connect-to-ide) | Port forwarding is documented for SSH over exposed TCP |
| [v0.32.0 Dockerfile](https://github.com/vllm-project/vllm/blob/v0.32.0/docker/Dockerfile) | `ENTRYPOINT ["vllm", "serve"]`, root user, Ubuntu 24.04 CUDA 13.0 base, no OpenSSH |
| [deployment/runpod-start-command.json](../deployment/runpod-start-command.json) | The object to paste |
