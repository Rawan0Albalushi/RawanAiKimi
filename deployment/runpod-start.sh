set -euo pipefail

if [ -z "${PUBLIC_KEY:-}" ]; then
  echo "PUBLIC_KEY is missing or empty. Add an SSH public key to the RunPod account before starting." >&2
  exit 1
fi

case "$PUBLIC_KEY" in
  *"PRIVATE KEY"*|*"OPENSSH PRIVATE KEY"*)
    echo "PUBLIC_KEY contains a private key. Refusing to start." >&2
    exit 1
    ;;
esac

key_ok=0
while IFS= read -r line || [ -n "${line:-}" ]; do
  line="${line%$'\r'}"
  case "$line" in
    ""|"#"*)
      continue
      ;;
  esac
  case "$line" in
    "ssh-ed25519 "*|"ssh-rsa "*|"ecdsa-sha2-nistp256 "*|"ecdsa-sha2-nistp384 "*|"ecdsa-sha2-nistp521 "*|"sk-ssh-ed25519@openssh.com "*|"sk-ecdsa-sha2-nistp256@openssh.com "*)
      ;;
    *)
      echo "PUBLIC_KEY has a line that is not an accepted SSH public key." >&2
      exit 1
      ;;
  esac
  rest="${line#* }"
  b64="${rest%% *}"
  case "$b64" in
    *[!A-Za-z0-9+/=]*|"")
      echo "PUBLIC_KEY has a malformed key body." >&2
      exit 1
      ;;
  esac
  if [ "${#b64}" -lt 20 ]; then
    echo "PUBLIC_KEY has a truncated key body." >&2
    exit 1
  fi
  key_ok=1
done <<< "$PUBLIC_KEY"

if [ "$key_ok" -ne 1 ]; then
  echo "PUBLIC_KEY did not contain an SSH public key." >&2
  exit 1
fi

export HF_HOME=/workspace/hf_home
mkdir -p "$HF_HOME"

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y --no-install-recommends openssh-server

if command -v service >/dev/null 2>&1; then
  service ssh stop || true
fi

mkdir -p /run/sshd /root/.ssh
chmod 755 /run/sshd
chmod 700 /root/.ssh
umask 077
printf '%s\n' "$PUBLIC_KEY" > /root/.ssh/authorized_keys
chmod 600 /root/.ssh/authorized_keys

cfg=/etc/ssh/sshd_kimi_poc.conf
: > "$cfg"
found_host_key=0
for key in /etc/ssh/ssh_host_ed25519_key /etc/ssh/ssh_host_rsa_key /etc/ssh/ssh_host_ecdsa_key; do
  if [ -f "$key" ]; then
    printf 'HostKey %s\n' "$key" >> "$cfg"
    found_host_key=1
  fi
done
if [ "$found_host_key" -ne 1 ]; then
  echo "openssh-server did not create host keys." >&2
  exit 1
fi

printf '%s\n' \
  "Port 22" \
  "PidFile /run/sshd.pid" \
  "AuthorizedKeysFile /root/.ssh/authorized_keys" \
  "PasswordAuthentication no" \
  "KbdInteractiveAuthentication no" \
  "ChallengeResponseAuthentication no" \
  "PubkeyAuthentication yes" \
  "AuthenticationMethods publickey" \
  "PermitRootLogin prohibit-password" \
  "PermitEmptyPasswords no" \
  "AllowUsers root" \
  "AllowAgentForwarding no" \
  "AllowTcpForwarding local" \
  "AllowStreamLocalForwarding no" \
  "GatewayPorts no" \
  "X11Forwarding no" \
  "PermitTunnel no" \
  "PermitUserEnvironment no" \
  "StrictModes yes" \
  "UsePAM yes" \
  "PrintMotd no" \
  "ClientAliveInterval 30" \
  "ClientAliveCountMax 4" \
  >> "$cfg"
chmod 644 "$cfg"

/usr/sbin/sshd -t -f "$cfg"
/usr/sbin/sshd -f "$cfg"

if [ ! -s /run/sshd.pid ]; then
  echo "sshd did not stay running." >&2
  exit 1
fi

exec vllm serve \
  --model moonshotai/Kimi-VL-A3B-Instruct \
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
