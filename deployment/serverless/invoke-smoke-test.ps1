# Submits one async RunPod job and polls it. Does not retry.
# Requires RUNPOD_API_KEY in the environment. Never prints that value.
param(
    [int]$PollSeconds = 10,
    [int]$MaxWaitSeconds = 600
)

$ErrorActionPreference = "Stop"
$script:ApiKey = $env:RUNPOD_API_KEY
if ([string]::IsNullOrWhiteSpace($env:RUNPOD_ENDPOINT_ID)) {
    Write-Host "RUNPOD_ENDPOINT_ID is not set. The previous endpoint n3bvj9t7q1tvam (hostile_crimson_minnow) was deleted. Set RUNPOD_ENDPOINT_ID to the next endpoint before running this script."
    exit 1
}
$endpointId = $env:RUNPOD_ENDPOINT_ID.Trim()
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$requestPath = Join-Path $root "smoke-test-request.json"
$jobFile = Join-Path $root "last-job-id.txt"

function Redact([string]$Text) {
    if ([string]::IsNullOrEmpty($Text)) { return "" }
    if ([string]::IsNullOrEmpty($script:ApiKey)) { return $Text }
    return $Text.Replace($script:ApiKey, "[REDACTED]")
}

function Write-Safe([string]$Message) {
    Write-Host (Redact $Message)
}

if ([string]::IsNullOrWhiteSpace($script:ApiKey)) {
    Write-Host "RUNPOD_API_KEY is not set. Set it in this shell only. This script does not read a key file and does not print the key."
    exit 1
}
if ($endpointId -notmatch "^[A-Za-z0-9_-]+$") {
    Write-Host "Endpoint id contains unsupported characters."
    exit 1
}
if ($PollSeconds -lt 5 -or $MaxWaitSeconds -lt $PollSeconds) {
    Write-Host "PollSeconds must be at least 5, and MaxWaitSeconds must be at least PollSeconds."
    exit 1
}

try {
    $rawBody = Get-Content -Raw -Path $requestPath
    $request = $rawBody | ConvertFrom-Json
    $chat = $request.input.openai_input
    if ($request.input.openai_route -ne "/v1/chat/completions") { throw "openai_route is not /v1/chat/completions" }
    if ($chat.model -ne "kimi-vl") { throw "model is not kimi-vl" }
    if ($chat.stream -ne $false) { throw "stream must be false" }
    if ($chat.max_tokens -ne 32) { throw "max_tokens must be 32" }
    if ($chat.messages.Count -ne 1 -or $chat.messages[0].role -ne "user") { throw "one user message is required" }
} catch {
    Write-Safe "Request file is not the prepared smoke test: $($_.Exception.Message)"
    exit 1
}

function Invoke-RunPod([string]$Method, [string]$Uri, [string]$Body) {
    $headers = @{
        Authorization = "Bearer $($script:ApiKey)"
        Accept = "application/json"
    }
    try {
        $params = @{
            Method = $Method
            Uri = $Uri
            Headers = $headers
            TimeoutSec = 30
            UseBasicParsing = $true
        }
        # Windows PowerShell 5.1 binds an omitted [string] as "", not $null.
        # A GET with that empty body raises "Cannot send a content-body with this verb-type."
        $sendBody = ($Method -eq "POST" -or $Method -eq "PUT" -or $Method -eq "PATCH") -and -not [string]::IsNullOrEmpty($Body)
        if ($sendBody) {
            $params.ContentType = "application/json; charset=utf-8"
            $params.Body = [System.Text.Encoding]::UTF8.GetBytes($Body)
        }
        $response = Invoke-WebRequest @params
        $content = [string]$response.Content
        $parsed = $null
        if (-not [string]::IsNullOrWhiteSpace($content)) {
            $parsed = $content | ConvertFrom-Json
        }
        return @{ Ok = $true; StatusCode = [int]$response.StatusCode; Body = $content; Json = $parsed }
    } catch {
        $code = $null
        $detail = $_.ErrorDetails.Message
        $webResponse = $_.Exception.Response
        if ($webResponse) {
            try { $code = [int]$webResponse.StatusCode } catch { $code = $null }
            if ([string]::IsNullOrWhiteSpace($detail)) {
                try {
                    $stream = $webResponse.GetResponseStream()
                    if ($stream) {
                        $reader = New-Object System.IO.StreamReader($stream)
                        $detail = $reader.ReadToEnd()
                        $reader.Dispose()
                    }
                } catch {
                    $detail = $null
                }
            }
        }
        if ([string]::IsNullOrWhiteSpace($detail)) { $detail = $_.Exception.Message }
        return @{ Ok = $false; StatusCode = $code; Body = (Redact $detail); Json = $null }
    }
}

function Get-OutputObject($Json) {
    $output = $Json.output
    if ($null -eq $output) { return $null }
    if ($output -is [System.Array]) {
        if ($output.Count -lt 1) { return $null }
        return $output[0]
    }
    return $output
}

$script:JobId = $null
$script:CleanExit = $false
$script:DeadlineCancelStarted = $false

function Stop-SmokeTest([int]$Code) {
    $script:CleanExit = $true
    exit $Code
}

$base = "https://api.runpod.ai/v2/$endpointId"
Write-Host "Endpoint $endpointId. One POST /run, then poll /status. This script does not call /retry."
Write-Host "Poll every ${PollSeconds}s for at most ${MaxWaitSeconds}s (10 minutes unless overridden). At that deadline the submitted job is cancelled once."
Write-Host "Ctrl+C stops this script only. It does not cancel the remote job."

$submit = Invoke-RunPod -Method "POST" -Uri "$base/run" -Body $rawBody
if (-not $submit.Ok) {
    Write-Safe "POST /run failed. HTTP $($submit.StatusCode). No second submit was sent."
    Write-Safe $submit.Body
    Write-Host "Check the endpoint Requests list before running this script again. A timed-out submit can still have queued a job."
    exit 1
}

$jobId = [string]$submit.Json.id
if ($jobId -notmatch "^[A-Za-z0-9_-]+$") {
    Write-Safe "POST /run did not return a job id. No second submit was sent."
    Write-Safe $submit.Body
    exit 1
}

Write-Host "Job ID: $jobId"
$script:JobId = $jobId
Set-Content -LiteralPath $jobFile -Value $jobId -Encoding ascii
$started = Get-Date
Write-Host "status=$($submit.Json.status) elapsed=0s"
Write-Host "Saved job id to last-job-id.txt."

try {
$terminal = @("COMPLETED", "FAILED", "CANCELLED", "TIMED_OUT", "ERROR")
$final = $submit.Json
while ($terminal -notcontains [string]$final.status) {
    $elapsed = [int]((Get-Date) - $started).TotalSeconds
    if ($elapsed -ge $MaxWaitSeconds) {
        if ($script:DeadlineCancelStarted) { Stop-SmokeTest 2 }
        $script:DeadlineCancelStarted = $true
        Write-Host "elapsed=${elapsed}s deadline reached. Cancelling job $jobId once. The job will not be resubmitted."
        & (Join-Path $root "cancel-smoke-test.ps1") -JobId $jobId
        Write-Host "Cancellation was attempted once. A cancel response does not mean the GPU worker has stopped. Follow the emergency procedure in smoke-test.md."
        Stop-SmokeTest 2
    }
    Start-Sleep -Seconds $PollSeconds
    $elapsed = [int]((Get-Date) - $started).TotalSeconds
    $polled = Invoke-RunPod -Method "GET" -Uri "$base/status/$jobId"
    if (-not $polled.Ok) {
        Write-Safe "elapsed=${elapsed}s status poll failed. HTTP $($polled.StatusCode). The job was not resubmitted and was not cancelled."
        Write-Safe $polled.Body
        Write-Host "Job ID: $jobId"
        Stop-SmokeTest 1
    }
    $final = $polled.Json
    $status = [string]$final.status
    Write-Host "elapsed=${elapsed}s status=$status delayTime=$($final.delayTime) executionTime=$($final.executionTime)"
}

$elapsed = [int]((Get-Date) - $started).TotalSeconds
$status = [string]$final.status
Write-Host "finished elapsed=${elapsed}s status=$status delayTime=$($final.delayTime) executionTime=$($final.executionTime)"
Write-Host "delayTime and executionTime are milliseconds reported by RunPod. They are not the invoice."

$payload = Get-OutputObject $final
if ($null -ne $payload) {
    Write-Host "output:"
    Write-Safe ($payload | ConvertTo-Json -Depth 20)
}

if ($status -eq "FAILED" -or $status -eq "ERROR") {
    Write-Host "Job failed. Do not run this script again and do not use RunPod Retry."
    Stop-SmokeTest 4
}
if ($status -eq "CANCELLED") {
    Write-Host "RunPod reported CANCELLED for job $jobId. This does not mean the GPU worker has stopped."
    Stop-SmokeTest 5
}
if ($status -eq "TIMED_OUT") {
    Write-Host "Job timed out. Do not run this script again and do not use RunPod Retry."
    Stop-SmokeTest 6
}
if ($status -ne "COMPLETED") {
    Write-Host "Unhandled terminal status. No new job was submitted."
    Stop-SmokeTest 7
}

$errorMessage = $null
if ($null -ne $payload -and $null -ne $payload.error) {
    $errorMessage = [string]$payload.error.message
}
if ($errorMessage) {
    Write-Safe "COMPLETED response contains an error and is a failed test: $errorMessage"
    Write-Host "Do not run this script again and do not use RunPod Retry."
    Stop-SmokeTest 3
}

$content = $null
try { $content = [string]$payload.choices[0].message.content } catch { $content = $null }
if ([string]::IsNullOrWhiteSpace($content)) {
    Write-Host "COMPLETED response has no reply text. Record the output shape. Do not submit another job."
    Stop-SmokeTest 3
}

Write-Host "Reply received. Confirm vLLM 0.32.0 and the pinned revision in the worker log before treating this as a pass."
Write-Host "Then follow the emergency procedure in smoke-test.md and read the Billing page."
Stop-SmokeTest 0
} finally {
    if ($script:DeadlineCancelStarted -and -not $script:CleanExit) {
        Write-Host "Interruption during the one cancellation attempt. This does not confirm that the remote job was cancelled or that the GPU worker stopped."
        Write-Host "Job ID: $($script:JobId)"
    } elseif (-not $script:CleanExit -and $script:JobId) {
        Write-Host "Script interrupted. The remote job was not cancelled by this interruption."
        Write-Host "Job ID: $($script:JobId)"
        Write-Host "Follow the emergency procedure in smoke-test.md."
    }
}
