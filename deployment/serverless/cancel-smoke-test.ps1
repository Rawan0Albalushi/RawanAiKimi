# Cancels one RunPod job. Does not submit or retry a job.
# Requires RUNPOD_API_KEY in the environment. Never prints that value.
param(
    [string]$JobId = ""
)

$ErrorActionPreference = "Stop"
$script:ApiKey = $env:RUNPOD_API_KEY
if ([string]::IsNullOrWhiteSpace($env:RUNPOD_ENDPOINT_ID)) {
    Write-Host "RUNPOD_ENDPOINT_ID is not set. The previous endpoint n3bvj9t7q1tvam (hostile_crimson_minnow) was deleted. Set RUNPOD_ENDPOINT_ID to the next endpoint before running this script."
    exit 1
}
$endpointId = $env:RUNPOD_ENDPOINT_ID.Trim()
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
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
    Write-Host "RUNPOD_API_KEY is not set. Set it in this shell only. This script does not print the key."
    exit 1
}
if ($endpointId -notmatch "^[A-Za-z0-9_-]+$") {
    Write-Host "Endpoint id contains unsupported characters."
    exit 1
}
if ([string]::IsNullOrWhiteSpace($JobId) -and (Test-Path -LiteralPath $jobFile)) {
    $JobId = (Get-Content -LiteralPath $jobFile -Raw).Trim()
}
if ($JobId -notmatch "^[A-Za-z0-9_-]+$") {
    Write-Host "Pass -JobId, or run the invoke script first so last-job-id.txt exists."
    exit 1
}

Write-Host "WARNING: Cancellation does not necessarily terminate the GPU worker immediately. Prevent new workers, check the Workers tab, and verify Billing."
Write-Host "Job ID: $JobId"
$uri = "https://api.runpod.ai/v2/$endpointId/cancel/$JobId"
$headers = @{
    Authorization = "Bearer $($script:ApiKey)"
    Accept = "application/json"
}
try {
    $response = Invoke-WebRequest -Method POST -Uri $uri -Headers $headers -TimeoutSec 30 -UseBasicParsing
    $content = [string]$response.Content
    $parsed = $content | ConvertFrom-Json
    Write-Host "job=$JobId status=$($parsed.status) http=$([int]$response.StatusCode)"
    Write-Safe $content
    if ([string]$parsed.status -eq "CANCELLED") {
        Write-Host "RunPod reported CANCELLED for job $JobId. No new job was submitted. The GPU worker may still be running."
        exit 0
    }
    Write-Host "Cancel returned a non-CANCELLED status. No new job was submitted. The GPU worker may still be running."
    exit 1
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
    Write-Safe "Cancel failed. HTTP $code. No new job was submitted."
    Write-Safe $detail
    exit 1
}
