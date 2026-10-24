# Validates the prepared Serverless files. Does not call RunPod and does not deploy.
$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$failures = New-Object System.Collections.Generic.List[string]

function Add-Failure([string]$Message) {
    $script:failures.Add($Message)
}

$allowed = @(
    "MODEL_NAME",
    "MODEL_REVISION",
    "OPENAI_SERVED_MODEL_NAME_OVERRIDE",
    "TRUST_REMOTE_CODE",
    "ENFORCE_EAGER",
    "MAX_MODEL_LEN",
    "MAX_NUM_SEQS",
    "GPU_MEMORY_UTILIZATION",
    "MAX_CONCURRENCY",
    "VLLM_STARTUP_TIMEOUT",
    "VLLM_EXTRA_ARGS"
)

$forbidden = @(
    "HF_TOKEN",
    "HOST",
    "PORT",
    "API_KEY",
    "MODEL",
    "CONFIG",
    "HELP",
    "LANGUAGE_MODEL_ONLY",
    "HF_HOME",
    "VLLM_API_KEY",
    "BASE_PATH",
    "RUNPOD_API_KEY"
)

$envPath = Join-Path $root "worker.env.example"
$raw = Get-Content -Raw -Path $envPath
if ($raw -match "hf_[A-Za-z0-9]|rpa_[A-Za-z0-9]|BEGIN PRIVATE|sk-[A-Za-z0-9]") {
    Add-Failure "worker.env.example contains a secret-like value"
}

$values = @{}
foreach ($line in (Get-Content -Path $envPath)) {
    $trim = $line.Trim()
    if ($trim.Length -eq 0 -or $trim.StartsWith("#")) { continue }
    $parts = $trim.Split("=", 2)
    if ($parts.Count -ne 2 -or [string]::IsNullOrWhiteSpace($parts[0])) {
        Add-Failure "malformed env line: $trim"
        continue
    }
    $values[$parts[0].Trim()] = $parts[1].Trim()
}

foreach ($key in $values.Keys) {
    if ($allowed -notcontains $key) {
        Add-Failure "env key is not in the v2.30.0 allowlist: $key"
    }
}
foreach ($key in $forbidden) {
    if ($values.ContainsKey($key)) {
        Add-Failure "forbidden env key is set: $key"
    }
}
foreach ($key in $allowed) {
    if (-not $values.ContainsKey($key) -or [string]::IsNullOrWhiteSpace($values[$key])) {
        Add-Failure "missing required env key: $key"
    }
}

$expect = @{
    MODEL_NAME = "moonshotai/Kimi-VL-A3B-Instruct"
    MODEL_REVISION = "398eede0903cd983a2bfa0cc634e9ac1d843f375"
    OPENAI_SERVED_MODEL_NAME_OVERRIDE = "kimi-vl"
    TRUST_REMOTE_CODE = "true"
    ENFORCE_EAGER = "true"
    MAX_MODEL_LEN = "2048"
    MAX_NUM_SEQS = "1"
    GPU_MEMORY_UTILIZATION = "0.90"
    MAX_CONCURRENCY = "1"
    VLLM_STARTUP_TIMEOUT = "900"
    VLLM_EXTRA_ARGS = "--language-model-only"
}
foreach ($key in $expect.Keys) {
    if ($values.ContainsKey($key) -and $values[$key] -ne $expect[$key]) {
        Add-Failure "unexpected value for ${key}"
    }
}

$requestPath = Join-Path $root "sample-request.json"
try {
    $request = Get-Content -Raw -Path $requestPath | ConvertFrom-Json
    $body = $request.input.openai_input
    if ($request.input.openai_route -ne "/v1/chat/completions") {
        Add-Failure "openai_route is not the chat completions route"
    }
    if ($body.model -ne "kimi-vl") { Add-Failure "request model is not kimi-vl" }
    if ($body.stream -ne $false) { Add-Failure "request stream must be false" }
    if ($body.temperature -ne 0.2) { Add-Failure "request temperature is not 0.2" }
    if ($body.max_tokens -ne 64) { Add-Failure "request max_tokens is not 64" }
    if ($body.messages.Count -ne 1 -or $body.messages[0].role -ne "user") {
        Add-Failure "request must contain one user message"
    }
    if ($body.messages[0].content -notmatch "\S") {
        Add-Failure "request message content is empty"
    }
} catch {
    Add-Failure "sample-request.json did not parse: $($_.Exception.Message)"
}

$smokePath = Join-Path $root "smoke-test-request.json"
try {
    $smoke = Get-Content -Raw -Path $smokePath | ConvertFrom-Json
    $smokeBody = $smoke.input.openai_input
    if ($smoke.input.openai_route -ne "/v1/chat/completions") {
        Add-Failure "smoke openai_route is not the chat completions route"
    }
    if ($smokeBody.model -ne "kimi-vl") { Add-Failure "smoke request model is not kimi-vl" }
    if ($smokeBody.stream -ne $false) { Add-Failure "smoke request stream must be false" }
    if ($smokeBody.max_tokens -ne 32) { Add-Failure "smoke request max_tokens is not 32" }
    if ($smokeBody.messages.Count -ne 1 -or $smokeBody.messages[0].role -ne "user") {
        Add-Failure "smoke request must contain one user message"
    }
} catch {
    Add-Failure "smoke-test-request.json did not parse: $($_.Exception.Message)"
}

$settingsPath = Join-Path $root "endpoint-settings.json"
try {
    $settings = Get-Content -Raw -Path $settingsPath | ConvertFrom-Json
    if ($settings.workersMin -ne 0) { Add-Failure "workersMin is not 0" }
    if ($settings.workersMax -ne 1) { Add-Failure "workersMax is not 1" }
    if ($settings.idleTimeout -ne 60) { Add-Failure "idleTimeout is not 60" }
    foreach ($name in @("gpuTypeIds", "networkVolumeId", "templateId", "dockerArgs")) {
        if ($settings.PSObject.Properties.Name -contains $name) {
            Add-Failure "endpoint settings include an unverified field: $name"
        }
    }
} catch {
    Add-Failure "endpoint-settings.json did not parse: $($_.Exception.Message)"
}

$requiredDocs = @(
    "README.md",
    "worker-configuration.md",
    "sample-response.md",
    "laravel-integration.md",
    "smoke-test.md",
    "invoke-smoke-test.ps1",
    "cancel-smoke-test.ps1"
)
foreach ($doc in $requiredDocs) {
    if (-not (Test-Path -LiteralPath (Join-Path $root $doc))) {
        Add-Failure "missing $doc"
    }
}

if ($failures.Count -gt 0) {
    Write-Host "VALIDATION FAILED"
    foreach ($item in $failures) { Write-Host " - $item" }
    exit 1
}

Write-Host "VALIDATION PASSED"
Write-Host "Checked env keys against the v2.30.0 allowlist, sample request JSON, and endpoint scale settings."
Write-Host "This does not deploy an endpoint and does not load the model."
exit 0
