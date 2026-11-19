# User acceptance test scenarios

Run these on a **staging copy** (separate database) with real master data (branches, products, prices). Do not run them in production. Each scenario lists the role to sign in with, the steps and the expected result. Tick each one, note the date and tester, and keep this file as the sign-off record.

Preparation: create one user per role (admin, branch-manager, cashier, inventory-clerk, accountant, optometrist), each assigned to **Branch A** only, plus one branch manager for **Branch B**. Enable tax in Settings if you charge VAT.

## A. Inventory

| # | Role | Steps | Expected |
|---|---|---|---|
| A1 | inventory-clerk | Products → open a frame → print labels | Labels with barcode/QR print correctly; scanning the printed barcode in the POS finds the product |
| A2 | branch-manager | Stock operations → opening stock for 3 products | Stock levels show the quantities; *Stock movements* shows one "opening" line each |
| A3 | branch-manager | Adjust a product −1 with reason "damaged" | Quantity −1; movement recorded with reason and user; the accountant sees an inventory loss entry |
| A4 | branch-manager (A) | Transfer 2 units A→B, approve, dispatch | Stock leaves A on dispatch, not before |
| A5 | branch-manager (B) | Receive the transfer | Stock arrives in B; branch A users cannot see B's stock unless they have all-branch access |
| A6 | cashier | Try to sell more than in stock | Blocked with a clear message (unless allowed negative) |

## B. Sales, returns and payments

| # | Role | Steps | Expected |
|---|---|---|---|
| B1 | cashier | POS: scan 2 items, cash payment with change | Invoice number issued; receipt prints in Arabic; stock decreases; change shown |
| B2 | cashier | Double-click *Complete sale* / refresh during save | Only one invoice exists (idempotent) |
| B3 | cashier | Partial payment without a customer | Refused: a registered customer is required |
| B4 | cashier | Sale on credit to a registered customer, then record the remaining payment | Payment status goes from partially paid to paid; the customer balance is correct |
| B5 | cashier | Discount above the allowed % | Refused; a branch manager with unlimited discount can do it |
| B6 | cashier → branch-manager | Return 1 item; manager inspects and approves | Stock comes back only after approval; refund through Returns; accounting reversal posted |
| B7 | branch-manager | Cancel an invoice with a reason | Stock restored, payment refunded, invoice marked cancelled, entry reversed |

## C. Purchasing

| # | Role | Steps | Expected |
|---|---|---|---|
| C1 | branch-manager | Create a purchase order, receive partially | Stock increases by the received quantity only; the supplier balance grows |
| C2 | accountant | Pay the supplier | Supplier balance decreases; cash/bank decreases |

## D. Accounting and finance

| # | Role | Steps | Expected |
|---|---|---|---|
| D1 | accountant | Trial balance for this month | Debits = credits |
| D2 | accountant | Income statement and balance sheet | Revenue matches the sales report for the same period (net of returns); assets = liabilities + equity |
| D3 | cashier | Open a cash shift, take cash sales, close with a counted amount | Over/short calculated and posted |
| D4 | accountant | Record and approve an expense with a receipt PDF | Receipt viewable only by authorized users; entry posted |
| D5 | accountant | Close last month | Postings dated in the closed month are refused afterwards |
| D6 | accountant | Payroll run for the month → approve → pay | Net pay correct (see H4); entries posted |

## E. Reports and analytics

| # | Role | Steps | Expected |
|---|---|---|---|
| E1 | branch-manager | Report center → sales by product, export Excel/PDF/CSV | Files open; Arabic is readable in the PDF; totals match the screen |
| E2 | branch-manager (A) | Try branch B in a report | Not available / refused |
| E3 | admin | Business insights → reorder suggestions → create drafts | Only **draft** purchase orders are created; nothing is ordered automatically |

## F. Imports

| # | Role | Steps | Expected |
|---|---|---|---|
| F1 | admin | Import a products Excel with 2 invalid rows | Valid rows are imported; the error report lists the invalid rows with reasons |
| F2 | admin | Re-import the same file | Duplicates are detected; existing stock is never overwritten |
| F3 | admin | Import from a Google Sheets link | Works for a shared sheet; links to non-allowed hosts are refused |

## G. Offline POS

| # | Role | Steps | Expected |
|---|---|---|---|
| G1 | cashier | Offline POS → register the device (online) | Device appears in *Offline sync review → Devices* |
| G2 | cashier | Disconnect the network; make 2 sales | Sales are saved on the device and show "pending synchronization"; an offline receipt prints |
| G3 | cashier | Reconnect | Sales sync automatically; invoice numbers are issued; stock decreases |
| G4 | cashier | Offline: sell more than the known stock, then reconnect | Sale recorded and flagged **Requires review**, or kept out if the policy is *reject* |
| G5 | branch-manager | Resolve the conflict with a note | Conflict marked resolved with the user and note |
| G6 | admin | Revoke the device, then try to sync from it | Refused; the queued sales stay on the device |
| G7 | cashier | Let the session expire while offline, then reconnect | Asked to sign in; queue kept; syncs after sign-in |

## H. HR

| # | Role | Steps | Expected |
|---|---|---|---|
| H1 | branch-manager | Employees → open an employee | No national ID (masked); salary history tab hidden |
| H2 | accountant (hr.sensitive) | Same employee | National ID and address visible and editable; salary history visible |
| H3 | cashier (linked employee) | My attendance & leave → Check in, later Check out | Late minutes calculated from the shift start; a second check-in is refused |
| H4 | branch-manager | Mark 1 absence; approve 2 days unpaid leave; create the payroll run | The payroll line shows an attendance deduction = salary × (absent days + unpaid leave days + ½ × half days) ÷ days in the month, here salary × 3 ÷ days in the month; it can still be edited while the run is a draft |
| H5 | cashier | Request annual leave beyond the balance | Refused with the remaining balance |
| H6 | branch-manager | Approve your own leave request | Refused (another manager must approve) |
| H7 | accountant | Change a salary with an effective date and reason | Salary history shows old → new, who and when; the history cannot be edited |

## I. Eyewear services

| # | Role | Steps | Expected |
|---|---|---|---|
| I1 | optometrist | New prescription: OD −2.25 / −0.75 × 180, OS −2.00, PD 63.5 | Saved with an RX number; prints correctly |
| I2 | optometrist | Enter SPH −2.30 or CYL without AXIS | Refused with a clear message |
| I3 | inventory-clerk | Open Prescriptions | Access denied |
| I4 | optometrist | Lens compatibility check with a lens limited to ±6.00 for an Rx of −8.00 | Shows "outside the lens range" |
| I5 | cashier | POS: select the customer, link the prescription, sell a compatible lens | Invoice links the prescription; an incompatible lens is refused |
| I6 | cashier | Sell a frame with a 12-month warranty | Warranty appears in *Warranties & claims*, valid for 12 months |
| I7 | cashier → branch-manager | Open a claim; manager approves "repair" | Claim approved; the person who opened it cannot approve it |
| I8 | cashier | New repair linked to the approved claim → in progress → ready → deliver | Delivered free; claim resolved |
| I9 | cashier | Paid repair: estimate 35.00 → deliver with cash 35.00 | An invoice is created with the repair service product; cash is recorded in the shift |
| I10 | admin | Enable loyalty (1 point per unit, 0.05 per point); sell 1,000.00 to a customer | Customer earns 1,000 points |
| I11 | cashier | Redeem 400 points on the next sale | 20.00 discount; balance updated; ledger shows earn and redeem |
| I12 | branch-manager | Cancel that sale | Redeemed points restored, earned points reversed |

## J. Operations

| # | Role | Steps | Expected |
|---|---|---|---|
| J1 | admin (SSH/cron) | `php artisan erp:backup --files` | Backup and checksum files created |
| J2 | admin | `php artisan erp:restore <file> --verify-only` | "Checksum OK" |
| J3 | admin (staging only) | Full restore into staging, then `erp:integrity` | All checks pass; data identical |
| J4 | admin | Audit log | Shows who did what for B6, B7, D5, H7, I7 and G6; no national IDs or prescription values in clear text |

Sign-off: ______________________ (owner) · ______________________ (accountant) · Date: __________
