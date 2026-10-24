# RunPod Serverless cost analysis

Status: pricing read on 21 October 2026. No checkout was opened. No endpoint was created. Figures below are not an invoice.

Conversion: **1 OMR = 2.6008 USD**, the Central Bank of Oman peg. OMR = USD / 2.6008. This test’s own ceiling is **below 5 OMR**, which is **below USD 13.00**. The project ceiling of 20 OMR is unchanged and is not spent by this evaluation. Tax and card fees are **UNKNOWN** and sit on top of the credit price.

## How Serverless bills

**VERIFIED** from [Serverless pricing](https://docs.runpod.io/serverless/pricing) and [endpoint settings](https://docs.runpod.io/serverless/endpoints/endpoint-configurations):

| Component | Rule |
| --- | --- |
| Flex compute | Per second from worker start until the worker stops, rounded up to the next second. Scale to zero when active workers are 0 |
| Active workers | Bill continuously, including with an empty queue. Not used in this plan |
| Cold start | Billed. It is the time from worker start until the handler is ready. Image pull and cached-model download normally happen while scaled down and are described as not billed |
| Execution | Billed while the job runs |
| Idle timeout | Billed. The worker stays running after the last job. Default 5 seconds. The console word “Idle” means already scaled down and not billed for compute |
| Container disk | Printed as about USD 0.10/GB/month, in 5-minute intervals. Whether a scaled-down worker still accrues it is **UNKNOWN** |
| Network volume | USD 0.07/GB/month under 1 TB. A volume restricts the endpoint to one data center. It bills until the volume is deleted |
| Account spend limit | Default USD 80/hour across resources. That limit does not protect a USD 5 test |

## GPU rates on the Serverless page

**VERIFIED** rates. Hourly and OMR columns are **ESTIMATED** arithmetic (`rate × 3600`, then `/ 2.6008`).

| Row | Memory | USD / second | USD / hour | OMR / hour |
| --- | --- | --- | --- | --- |
| A6000, A40 | 48 GB | 0.00034 | 1.224 | 0.471 |
| A100 | 80 GB | 0.00076 | 2.736 | 1.052 |
| 6000s PRO | 96 GB | 0.00111 | 3.996 | 1.536 |
| H100 PRO | 80 GB | 0.00116 | 4.176 | 1.606 |

At USD 0.00111 per second, USD 13.00 buys about **11,712 seconds**, which is about **3.25 hours** of this 96 GB row while a worker is running. Five OMR is not a long session if a worker is left on.

The Pod prepared earlier was reported at about USD 2.51 per hour including storage. That number is operator-reported, not this price table. Per running hour, the 96 GB Serverless row is higher. Serverless can cost less for a short test because compute stops at scale-down. A Pod left running, or a volume left after stop, keeps charging.

The 48 GB Serverless row is cheaper and is not a candidate. Phase 2 did not show that 48 GB can finish startup. The 80 GB A100 row is cheaper than 96 GB and still has no successful boot of this model.

## What a small test costs

These rows use the 96 GB rate and assume active workers are 0 and max workers is 1. Cold-start length is **UNKNOWN**, so the minutes are scenarios, not a forecast.

| Scenario | Running time | USD | OMR |
| --- | --- | --- | --- |
| 60-second idle wait after one job | 60 s | 0.07 | 0.03 |
| 10-minute cold start, no generation | 600 s | 0.67 | 0.26 |
| 15-minute cold start, 30-second reply, 60-second idle | 990 s | 1.10 | 0.42 |
| Worker startup timeout at the recommended 900 s cap | 900 s | 1.00 | 0.38 |
| Worker left at the documented 1800 s startup timeout | 1800 s | 2.00 | 0.77 |
| Active workers set to 1 for 4 hours by mistake | 14,400 s | 15.98 | 6.15 |

The mistaken active-worker row is above 5 OMR by itself. The test plan forbids it.

Storage, if someone attaches it despite the recommendation:

| Item | Arithmetic | USD for one day |
| --- | --- | --- |
| 100 GB network volume at USD 0.07/GB/month | 100 × 0.07 × 24/730 | 0.23 |
| 20 GB container disk at USD 0.10/GB/month | 20 × 0.10 × 24/730 | 0.07 |

The month divisor of 730 hours is the same assumption as Phase 3. The default container-disk size on a Serverless endpoint was not published. A network volume left for a month at 100 GB is USD 7, about 2.69 OMR, before any GPU time. The first test should not create one.

## Test cap

The first Serverless experiment, if approved, is capped at **USD 5** (about **1.92 OMR**), inside the 5 OMR request and inside the 20 OMR project ceiling.

That cap covers two failed boots at 900 seconds and one short successful call, with margin, only while max workers stays 1 and active workers stay 0. It does not cover an always-on worker, a second GPU priority, or a forgotten network volume.

Tax, FX, and the live rate on the deploy form are **UNKNOWN**. If the form shows a higher per-second rate than USD 0.00111, stop and recalculate before paying.

## Sources

| Source | What it settled |
| --- | --- |
| [Serverless pricing](https://docs.runpod.io/serverless/pricing) | Flex versus active, cold start, idle, disk, network volume |
| [Endpoint settings](https://docs.runpod.io/serverless/endpoints/endpoint-configurations) | Per-second GPU table, including `6000s PRO` at USD 0.00111 |
| [CBO fixed peg](https://cbo.gov.om/Pages/FixedPeg.aspx) | 2.6008 USD per OMR |
| [gpu-pricing-analysis.md](gpu-pricing-analysis.md) | Pod prices. They are a different product and are not reused as Serverless rates |
