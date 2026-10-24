# GPU pricing analysis

Status: Phase 3, 21 October 2026. No account was created, no card was added, and no GPU was rented.

This note prices a future boot of `moonshotai/Kimi-VL-A3B-Instruct` on vLLM v0.32.0. It does not approve that rental. A later written spend approval is still required.

Labels:

- **ADVERTISED.** A figure published on the provider’s own site on the date below. It is not a checkout total.
- **DOCUMENTED RULE.** A billing rule from the provider’s documentation. The dollar amount of a specific future session can still change.
- **ESTIMATED.** Arithmetic on advertised rates, including the 730-hour month used to turn a monthly storage rate into a session cost.
- **UNKNOWN.** Not published as a number this research could rely on, or visible only as a live offer that was not opened at checkout.

Conversion used throughout: **1 OMR = 2.6008 USD**, the Central Bank of Oman peg ([CBO fixed peg](https://cbo.gov.om/Pages/FixedPeg.aspx)). OMR = USD / 2.6008. The hard ceiling is **below 20 OMR**, which is **below USD 52.02**. Card foreign-exchange fees are not in that peg.

## Recommendation

**CONDITIONAL GO for a future rental approval. Do not rent in this phase.**

Preferred configuration, subject to the deploy screen on the day someone pays:

| Choice | Value |
| --- | --- |
| Provider | RunPod Community Cloud |
| GPU | 1× NVIDIA A100 PCIe 80 GB, on-demand |
| Advertised compute | USD 1.19 per hour |
| Disk | 150 GB volume disk, plus the smallest container disk the form allows |
| First credit deposit | USD 12 (about 4.61 OMR), and only if the card total including tax is at most 5 OMR |
| Maximum runtime | 4 hours from deploy until the pod is **deleted** |
| Not chosen | A40 48 GB. Vast.ai, until a specific on-demand 80 GB offer is written down from its own search page |

Community Cloud is preferred because it is the lower advertised A100 rate and this POC deletes the machine the same day. Secure Cloud is the fallback if Community has no 80 GB A100, at its own higher advertised rate, and it needs a new deposit figure before anyone pays.

## Sources and dates

| Source | URL | Date on the page, or the day it was read |
| --- | --- | --- |
| RunPod GPU price list | https://www.runpod.io/pricing | Page says “Updated October 8, 2026”. Read 21 October 2026 |
| Older RunPod price list | https://www.runpod.io/gpu-instance/pricing | Body says “Updated July 27, 2026”. Read 21 October 2026. Secure-tier numbers disagree with the October page |
| RunPod pod pricing | https://docs.runpod.io/pods/pricing | Read 21 October 2026. Docs index does not print a revision date in the fetched body |
| RunPod billing | https://docs.runpod.io/accounts-billing/billing | Read 21 October 2026 |
| RunPod sales tax | https://docs.runpod.io/accounts-billing/sales-tax | Read 21 October 2026 |
| RunPod network volumes | https://docs.runpod.io/storage/network-volumes | Read 21 October 2026 |
| RunPod pod overview | https://docs.runpod.io/pods/overview | Read 21 October 2026 |
| Vast.ai pricing guide | https://docs.vast.ai/guides/instances/pricing | Read 21 October 2026 |
| Vast.ai billing reference | https://docs.vast.ai/guides/reference/billing | Read 21 October 2026 |
| Vast.ai storage types | https://docs.vast.ai/guides/instances/storage/types | Read 21 October 2026 |
| Vast.ai quickstart | https://docs.vast.ai/guides/get-started/quickstart | Read 21 October 2026. States a USD 5 minimum deposit |
| Vast.ai instance management | https://docs.vast.ai/guides/instances/manage-instances | Read 21 October 2026 |

No RunPod console checkout was opened. No Vast.ai offer was reserved. Prices below are not invoices.

## Advertised price versus checkout price

RunPod’s October price page is a public list. The documentation says the latest GPU price is the one shown in the console while deploying, and that an on-demand pod requires credits equal to at least one hour of the selected configuration. Stock, the exact disk sizes on the form, and tax are not on the list page.

The October 8 page and the July 27 page do not match on Secure Cloud:

| GPU | October 8, 2026 list | July 27, 2026 list |
| --- | --- | --- |
| A100 PCIe, Community | USD 1.19/hr | USD 1.19/hr |
| A100 PCIe, Secure | USD 1.79/hr | USD 1.39/hr |
| A100 SXM, Community | USD 1.39/hr | USD 1.39/hr |
| A100 SXM, Secure | USD 1.79/hr | USD 1.59/hr |
| A40, Community | USD 0.35/hr | USD 0.35/hr |
| A40, Secure | USD 0.59/hr | USD 0.44/hr |

Community A100 rates match on both pages. Secure rates do not. This analysis uses the **October 8** page because it is the newer list. The deploy screen overrides both. If that screen shows a higher number, stop and recalculate before paying.

Vast.ai’s own pricing guide says hosts set prices and there is no static quote. A search listing is an advertisement until the offer panel is open and the hover breakdown is copied. This note does not adopt a Vast.ai hourly GPU number.

## What each provider charges

### RunPod

| Component | Published figure | Label |
| --- | --- | --- |
| A100 PCIe 80 GB compute | Community USD 1.19/hr. Secure USD 1.79/hr | ADVERTISED, October 8 list |
| A100 SXM 80 GB compute | Community USD 1.39/hr. Secure USD 1.79/hr | ADVERTISED, October 8 list |
| H100 PCIe 80 GB, for comparison only | Community USD 1.99/hr. Secure USD 2.89/hr | ADVERTISED. More expensive than the A100 and not required |
| H100 SXM 80 GB, for comparison only | Community USD 2.69/hr. Secure USD 3.99/hr | ADVERTISED. Not the preferred card |
| A40 48 GB | Community USD 0.35/hr. Secure USD 0.59/hr | ADVERTISED. Unverified for this model. Not the first deployment |
| Container disk | USD 0.10/GB/month while running. Not charged while stopped. Erased on stop | DOCUMENTED RULE |
| Volume disk | USD 0.10/GB/month while running. USD 0.20/GB/month while stopped. Kept until the pod is deleted | DOCUMENTED RULE |
| Network volume, standard, under 1 TB | USD 0.07/GB/month while running and while stopped | DOCUMENTED RULE |
| Ingress and egress | No data-transfer fees for Pods | DOCUMENTED RULE |
| Billing unit | Per second for compute, container disk, and volume disk. Hourly for network volumes | DOCUMENTED RULE |
| Minimum credits to deploy | At least one hour of the selected configuration | DOCUMENTED RULE. Whether that hour includes disk is not spelled out |
| Smallest deposit the docs mention | “As little as USD 10” for a new evaluation | DOCUMENTED as an option, not as the required amount |
| Credits | Non-refundable. They cannot be withdrawn | DOCUMENTED RULE |
| Spend limit | Default USD 80/hour across resources | DOCUMENTED RULE. Far above this POC. Do not raise it |
| Sales tax | Charged on top of the credit purchase in some jurisdictions. The rate depends on billing address and payment method. A tax ID may exempt a registered business. The docs’ example is 18%, which is an illustration, not this project’s rate | Rate for this payment method is UNKNOWN |
| Card FX and bank fees | Not stated by RunPod | UNKNOWN |

Network volumes exist only on Secure Cloud. Community Cloud uses container disk and volume disk.

The October list’s rendered GPU cards show one price at a time because the page has a Community/Secure control. The structured data on that same page is what splits the two rates above. Treat the structured October list as the advertised pair, then confirm the pair on the deploy form.

### Vast.ai

| Component | Published figure | Label |
| --- | --- | --- |
| A100 80 GB compute | No official list price. The dashboard, CLI, or API is the quote | UNKNOWN until an offer is copied from the dashboard |
| Storage | USD per GB per hour, set by the host. Often higher while stopped. Charged for the whole life of the instance | DOCUMENTED RULE. The rate is UNKNOWN |
| Bandwidth | USD per TB, set by the host, for bytes in and out, including while stopped | DOCUMENTED RULE. The rate is UNKNOWN. A 30.57 GiB weight download would be billed |
| Instance types | On-demand, reserved, and interruptible. Interruptible is described as often 50% cheaper and may be paused | DOCUMENTED RULE. Interruptible is a poor fit for a short download-and-boot test |
| Minimum deposit | USD 5 | DOCUMENTED RULE |
| Platform tax | Not stated as a number | UNKNOWN |
| Credits at zero | Instances stop. Storage can still bill. Without a saved card, instances and data are scheduled for deletion | DOCUMENTED RULE |

Vast.ai can be cheaper on a given hour and still be the wrong first provider: the GPU rate, the disk rate, and the bandwidth rate are three separate host prices, and a host’s maximum CUDA version may be older than the CUDA 12.9 or 13.0 image pinned in Phase 2. Those facts are checked per offer, not from a price list.

## Storage math

RunPod publishes storage as a monthly rate and bills the smaller periods from it. It does not say how many hours are in the month. This note uses **730 hours**, which is 365 / 12 × 24. That divisor is an assumption. A 31-day month would make these session amounts slightly smaller.

`session storage USD = GB × monthly USD per GB × hours / 730`

| Disk | 2 hours | 4 hours | 8 hours |
| --- | --- | --- | --- |
| 100 GB at USD 0.10/GB/month (running container or volume) | USD 0.027 | USD 0.055 | USD 0.110 |
| 150 GB at the same running rate | USD 0.041 | USD 0.082 | USD 0.164 |
| 150 GB volume disk left **stopped** for 7 days at USD 0.20/GB/month | USD 6.90 | same, the GPU is off | same |
| 150 GB volume disk left stopped for a 730-hour month | USD 30.00, about 11.53 OMR | | |
| 150 GB network volume, running or stopped, for 4 hours at USD 0.07 | USD 0.058 | | |

Four hours of disk is cents. A volume left stopped for a week costs more than the GPU test. A volume left for a month consumes a large share of the 20 OMR ceiling without producing a result.

A pod bills **each** disk. A 20 GB container plus a 150 GB volume costs more than the 150 GB row. The container size on the form is UNKNOWN until the form is open. Record it. Keep it at the smallest value that still lets the image start.

## Session estimates

GPU figures use the October 8 advertised hourly rate. Storage uses one 100 GB or one 150 GB running disk. OMR uses the peg. These are estimates of advertised cost, not checkout totals. Tax and FX are excluded because they are unknown.

### A100 PCIe 80 GB, Community, USD 1.19/hr

| Session | GPU only | GPU + 100 GB | GPU + 150 GB | 150 GB total in OMR |
| --- | --- | --- | --- | --- |
| 2 hours | USD 2.38 | USD 2.41 | USD 2.42 | 0.93 OMR |
| 4 hours | USD 4.76 | USD 4.81 | USD 4.84 | 1.86 OMR |
| 8 hours | USD 9.52 | USD 9.63 | USD 9.68 | 3.72 OMR |

### A100 SXM 80 GB, Community, USD 1.39/hr

| Session | GPU only | GPU + 150 GB | OMR for the 150 GB column |
| --- | --- | --- | --- |
| 2 hours | USD 2.78 | USD 2.82 | 1.08 OMR |
| 4 hours | USD 5.56 | USD 5.64 | 2.17 OMR |
| 8 hours | USD 11.12 | USD 11.28 | 4.34 OMR |

### A100 PCIe or SXM, Secure, USD 1.79/hr

| Session | GPU only | GPU + 150 GB | OMR for the 150 GB column |
| --- | --- | --- | --- |
| 2 hours | USD 3.58 | USD 3.62 | 1.39 OMR |
| 4 hours | USD 7.16 | USD 7.24 | 2.78 OMR |
| 8 hours | USD 14.32 | USD 14.48 | 5.57 OMR |

Secure is still under 20 OMR for an 8-hour session. It is not the preferred first buy, because Community is advertised at a lower rate and this test does not need a network volume if the pod is deleted the same day.

### Other 80 GB-class cards, Community, 4 hours of GPU only

| Card | Advertised rate | 4-hour GPU | OMR |
| --- | --- | --- | --- |
| A100 PCIe | USD 1.19/hr | USD 4.76 | 1.83 |
| A100 SXM | USD 1.39/hr | USD 5.56 | 2.14 |
| H100 PCIe 80 GB | USD 1.99/hr | USD 7.96 | 3.06 |
| H100 SXM 80 GB | USD 2.69/hr | USD 10.76 | 4.14 |

H100 has more headroom and a higher hourly rate. It is not required by the Phase 2 memory note. Skip it unless an A100 80 GB is unavailable and a new approval accepts the H100 price then on screen.

### A40 48 GB, unverified alternative only

| Cloud | Advertised rate | 4-hour GPU | OMR |
| --- | --- | --- | --- |
| Community | USD 0.35/hr | USD 1.40 | 0.54 |
| Secure | USD 0.59/hr | USD 2.36 | 0.91 |

The A40 is cheaper. Phase 2 did not show that 48 GB can start this model. A failed boot still consumes the hours used to pull the image and discover the out-of-memory error. Do not choose it to save money unless a later approval explicitly accepts that experiment.

### What the hours have to cover

These durations are planning allowances, not measurements.

| Step | Allowance |
| --- | --- |
| Pull the vLLM v0.32.0 CUDA 12.9 image | 10–25 minutes |
| Download the 30.57 GiB checkpoint | 10–40 minutes |
| First start, plus one configuration retry if it fails immediately | 20–60 minutes |
| Two short text prompts and a GPU memory sample | about 15 minutes |
| Stop, delete, and confirm the balance | about 10 minutes |

The middle of that range is about two hours. The upper end, plus a slow image pull, does not fit in two hours. A four-hour cap is the first authorization. Eight hours still fits the money and is too long to leave unattended: at USD 1.19/hr, a pod left running reaches the USD 52 ceiling in about 43.7 hours, under two days.

## Initial budget, runtime, and reserve

| Control | Figure |
| --- | --- |
| Hard ceiling | Below 20 OMR (below USD 52.02), including tax and FX |
| First credit purchase | USD 12 of RunPod credits |
| Card-charge cap for that purchase | 5 OMR. If tax or FX pushes the card above 5 OMR, buy fewer credits until it does not |
| What USD 12 covers at the Community A100 PCIe rate | About 10 hours of GPU, which is one 4-hour attempt and one 4-hour retry, plus cents of disk |
| What USD 12 does not cover | Two 4-hour attempts on Secure Cloud at USD 1.79/hr (USD 14.32 of GPU). Secure requires a separate approval and a deposit of about USD 16 |
| Maximum runtime | 4 hours, then delete. Set an alarm at 3 hours |
| Reserve | Do not deposit the rest of the 20 OMR. Unfunded ceiling cannot be spent by a forgotten pod |
| Why the deposit is small | RunPod credits are non-refundable. A USD 52 deposit could be consumed by a pod left running |

At a zero balance, RunPod stops pods. A pod **without** a network volume is then terminated and its disk is not recoverable. For this first test that is a backstop, not a substitute for deleting the pod yourself. Billing is described as running every five minutes, so the stop is not guaranteed at the exact second the balance hits zero.

## Stop versus delete

| Action | GPU compute | Disk and data | When to use it |
| --- | --- | --- | --- |
| Stop a RunPod pod | Stops | Container disk is erased and is not billed. Volume disk remains and is billed at USD 0.20/GB/month, twice the running rate. A network volume remains at its normal rate | Only if a same-day resume is already approved. Not the end state of this test |
| Delete / terminate the pod | Stops | Volume disk attached to that pod is deleted with it. Data is gone | Required end of every POC session |
| Delete the network volume too | Already stopped | Network-volume billing ends only when the volume itself is deleted | Required if a Secure Cloud test created one |
| Vast.ai Stop | GPU billing stops | Storage keeps billing until the instance is destroyed. Restart can fail if another customer took the GPU | Not an end state |
| Vast.ai Destroy | All instance charges stop | Instance data is deleted. A separate volume keeps billing until that volume is deleted | Required end state on Vast.ai |

Stopping feels safe because the weights remain. On these two providers it is the action that leaves the meter running.

## Shutdown and deletion checklist

Do this before leaving the machine, including after a failed boot.

1. Copy anything that must survive: the vLLM log, the two prompt results, `nvidia-smi`, the on-screen hourly price, and the disk sizes. Copy them to the workstation, not to the pod.
2. Confirm the checkpoint SHA and vLLM version that were actually used.
3. Stop the vLLM process so the GPU is idle while you capture the last memory sample.
4. On RunPod, terminate the pod. Do not stop there.
5. Open Storage and confirm no network volume and no stopped volume remain. Delete any that do.
6. Refresh Billing. Confirm compute is no longer increasing. Write down the balance before and after.
7. Turn off auto-reload if it was enabled, so a later tax-bearing charge cannot refill the account.
8. On Vast.ai, destroy the instance and delete every volume created for it. Confirm the instance list is empty.
9. Record the wall-clock start, the delete time, and whether the session stayed inside 4 hours.
10. If the pod cannot be deleted, leave the balance at the small deposit already made and say so in the notes. Do not add funds to “keep it safe.”

## How to verify the price manually

RunPod, before any payment:

1. Open https://www.runpod.io/pricing and the deploy form. They can differ.
2. Select Community Cloud, on-demand, 1× GPU, A100 PCIe 80 GB. If it is absent, check A100 SXM 80 GB. Do not select an A40.
3. Write down the hourly price, the container-disk GB, and the volume-disk GB.
4. Reject the form if the hourly price is above USD 1.19 for PCIe or USD 1.39 for SXM, unless you explicitly approve the new number.
5. On the credit page, write down credits, tax, and the card total. Reject the payment if the card total is above 5 OMR for this first deposit.
6. Confirm the country and tax line with your own billing address. This research did not look up an Oman tax rate.

Vast.ai, only if RunPod has no acceptable A100:

1. Open the Vast.ai dashboard, not a third-party price board.
2. Filter to one on-demand A100 of **80 GB**, not 40 GB, and not interruptible.
3. Hover the price and copy GPU USD/hr, storage USD/GB/hr, and bandwidth USD/TB.
4. Multiply bandwidth by about 0.031 TB for the weight download, both directions if the host charges both.
5. Check the offer’s maximum CUDA against the vLLM v0.32.0 image. An offer capped below CUDA 12.9 is not this pin.
6. Bring those copied numbers back before paying. A price from this document is not a Vast.ai quote.

## Rental-approval conditions

A future approval can say yes only if all of these are true:

1. The deploy form shows 1× A100 80 GB, PCIe or SXM, at or below the Community rate in this note, or a newly written rate that you accept.
2. Disk is about 150 GB of volume, with the container disk kept small, and both sizes are written down.
3. The card charge for the first credits, including tax and the FX you can see, is at most 5 OMR.
4. The runtime cap is 4 hours, followed by delete, not stop.
5. Community Cloud is acceptable. If the only stock is Secure Cloud, stop and approve a new deposit near USD 16 before paying.
6. Nobody selects the A40 unless a separate approval accepts an unverified 48 GB experiment.
7. The shutdown checklist has an owner.

If the form’s A100 price makes even a 4-hour session, plus disk, tax, and FX, reach 20 OMR, that is a **no-go** for the rental. The advertised rates found here do not do that. The missing pieces are the live form, the tax line, and the card’s FX.

**This phase ends at the document. It is not permission to create the pod.**
