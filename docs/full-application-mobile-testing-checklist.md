# Shatya Info — full application and mobile testing checklist

Prepared: 12 November 2026. Scope: `C:\Herd\it-erp` and `C:\Herd\grahak_field`.

## Status and scope

This is a source-informed test plan, not a completed test report. All cases start **Not run**. Source discovery establishes a candidate feature inventory, not proof that a screen works or that a test covers it. Verify current business rules and enabled capabilities while executing. Mark unsupported actions N/A with a reason; do not treat proposed acceptance criteria as existing functionality.

The Admin panel at `/admin` is the primary product. Include Sales, Tenant and Landlord panels where configured, public signup/support/billing routes, versioned APIs, and the Flutter field app. Android is explicitly documented as a release target; confirm iOS release support before signing off iOS. Desktop/browser Flutter runs supplement native testing and do not replace it.

For every execution record: **case ID, build/commit of both repositories, environment/company, actor/role, device/browser, preconditions/data, steps, expected result, actual result, status, evidence, defect ID, tester, date, retest result**. Statuses: Not run / Pass / Fail / Blocked / N/A. A checked box means executed with evidence, not merely reviewed.

Priority: **P0** blocks release (security, data loss, incorrect financial/stock posting, core field-day failure); **P1** required feature acceptance; **P2** usability/secondary edge cases. Execute P0 first, then P1 and P2. Every financial, stock, tenant-isolation and durable-sync case below is P0; other functional cases default to P1, presentation-only cases P2.

## 1. Environment, accounts and fixtures

- [ ] SET-01 Record backend/mobile versions, migration state, enabled modules, API configuration, timezone and date format; evidence identifies the exact tested build.
- [ ] SET-02 Prepare isolated QA companies A and B with overlapping names and IDs; testing cannot access production data.
- [ ] SET-03 Prepare super_admin, administration_admin, AccessAllRecords user, ordinary administrator, salesperson, direct manager, unrelated manager, HR, inventory, finance/reviewer, read-only and no-permission users; map business personas to actual configured roles.
- [ ] SET-04 Create assigned/unassigned accounts, active/inactive employees, reporting hierarchy, multiple OUs/divisions/territories/patches and a user without an employee mapping.
- [ ] SET-05 Prepare valid/invalid geotags, zero/low/adequate stock, multiple stock locations, products/UOM ratios, tax/customer-price rules and expiring configuration.
- [ ] SET-06 Prepare draft/submitted/approved/rejected/cancelled or posted records wherever those states exist, plus empty and high-volume datasets.
- [ ] SET-07 Prepare current, prior, future and closed reporting dates; month/year boundaries, leap day, overnight shift and timezone-boundary fixtures.
- [ ] SET-08 Record supported browsers and native OS versions. Include minimum supported Android, newest supported Android, low-memory device, tablet and an iPhone if iOS ships.
- [ ] SET-09 Prepare controllable offline/slow/intermittent network, location permission states, camera/files access, exhausted storage and app-kill scenarios.
- [ ] SET-10 Configure sandbox email/OTP/payment/AI/maps/OCR integrations and workers; failures can be simulated without charging or messaging real customers.
- [ ] SET-11 Automated database tests use MySQL `it_erp_testing` and RefreshDatabase. Never invoke destructive tenant creation in Pest; provision tenant-lifecycle scenarios only in isolated disposable infrastructure.

## 2. Shared acceptance pack — apply separately to every inventoried resource

Create separate execution records using `<resource>:GEN-xx`. Exercise only supported actions; read-only registers must remain read-only.

- [ ] GEN-01 Open list/detail/create/edit routes as permitted roles; correct labels, columns, fields, defaults, breadcrumbs and actions render without errors.
- [ ] GEN-02 Create the minimum valid record and a fully populated record; reopen to verify all persisted values and relationships.
- [ ] GEN-03 Check required/null/blank/whitespace, duplicate values, maximum lengths, numeric bounds, decimals, invalid dates and malicious text; invalid input produces useful errors and no partial save.
- [ ] GEN-04 Edit allowed fields; immutable fields and closed/approved-state restrictions hold at server level.
- [ ] GEN-05 Search, sort, filters, pagination, clear filters and empty results work together; totals use the full filtered dataset where intended.
- [ ] GEN-06 Related dropdowns respect tenant, OU, employee visibility, active status and dependent selections; changing a parent clears invalid children.
- [ ] GEN-07 Delete/archive/restore/bulk operations follow supported policy; referenced or posted records cannot silently orphan related data.
- [ ] GEN-08 Execute every row/header/bulk action, including cancel/confirm and invalid-state attempts; one user action produces one intended effect.
- [ ] GEN-09 Export/import/print where available preserves filters, scope, dates, identifiers, numeric precision and Unicode; unauthorized actions fail.
- [ ] GEN-10 Alter record IDs, hidden fields and relationship IDs through direct requests; inaccessible data cannot be read or changed.
- [ ] GEN-11 Double-click, retry after timeout and edit concurrently; no duplicate posting or silent overwrite contrary to policy.
- [ ] GEN-12 Audit author fields contain user IDs; timestamps, actor and before/after state identify the actual change.
- [ ] GEN-13 Keyboard navigation, focus, validation announcements, long labels, mobile viewport and zoom remain usable.

## 3. Authentication, authorization and company isolation

- [ ] AUTH-01 Web and mobile valid login select the intended company; invalid credentials and unknown company show safe errors.
- [ ] AUTH-02 OTP request, resend, expiry, incorrect code, repeated verification and rate limiting behave correctly; no reusable successful OTP.
- [ ] AUTH-03 Logout, token revocation/expiry, disabled account and role removal invalidate access as designed; sensitive screens cannot reopen from history.
- [ ] AUTH-04 Each panel rejects users without panel permission, including direct URLs and Livewire actions.
- [ ] AUTH-05 Owner, subordinate, unrelated OU and cross-company reads/writes match policy in forms, reports, searches, exports and APIs.
- [ ] AUTH-06 Scope bypass works for the documented elevated roles/permission without unintentionally granting cross-tenant access.
- [ ] AUTH-07 Role/permission management cannot be used by an ordinary user to promote themselves or grant forbidden permissions.
- [ ] AUTH-08 Nested URLs validate both parent and child ownership: accounts/addresses, DCRs/expenses/receipts, visits/materials and statement/source files.
- [ ] AUTH-09 Company switch and logout isolate cached accounts, tokens, files, dashboards and pending operations; another company never receives the previous company's queue.
- [ ] AUTH-10 User/employee identity mappings are correct: visits use users.id; leave/daily attendance use employees.id; employees.login_id links the login; HR employee code remains a string.

## 4. Public site, registration, onboarding and billing

- [ ] ONB-01 Public home/help/support/legal pages and DPA download load; invalid legal document names fail safely.
- [ ] ONB-02 Support validation, submission, duplicate click and throttling work; failure does not claim a successful delivery.
- [ ] ONB-03 Signup validates company address availability, required details, captcha and duplicates; concurrent requests cannot reserve the same company identity twice.
- [ ] ONB-04 Signed verification/resend/status/retry/resume/complete flows reject expired, tampered and reused credentials; valid interrupted signup can resume.
- [ ] ONB-05 Disposable-company provisioning handles queue failure/retry without duplicate companies, users or master rows; tenant context stays isolated.
- [ ] ONB-06 First login opens company setup with correct readiness indicators; company branding, contact information and date settings persist.
- [ ] ONB-07 Organization/division/territory/account/employee/hierarchy imports validate templates, duplicate codes, missing parents, bad rows and Unicode; row errors identify repair actions.
- [ ] ONB-08 Preview and commit use consistent mappings; reimport/retry has documented duplicate behavior and preserves existing operational data.
- [ ] ONB-09 Field-team onboarding validates manager hierarchy, territory/patch coverage and employee login links; cycles and unrelated-company references are rejected.
- [ ] ONB-10 Company launch blocks incomplete prerequisites, handles concurrent launch and produces a usable first field day without repeated setup rows.
- [ ] ONB-11 Optional inventory, expense, HR and CRM activation checks prerequisites and review acknowledgement; permissions remain explicit after activation.
- [ ] ONB-12 Disabled optional modules have consistent UI/API behavior; pending work created before a capability change is retained and clearly resolved.
- [ ] BILL-01 Billing invoices, periods, seat/count inputs where applicable, totals and due/status displays agree with configured terms.
- [ ] BILL-02 Sandbox order/confirmation/webhook paths verify signatures and company/invoice ownership; altered amount or payment identity is rejected.
- [ ] BILL-03 Duplicate, delayed and out-of-order callbacks do not double-charge or double-credit; failed payment can retry safely.
- [ ] BILL-04 Cancellation/trial expiry/access changes follow terms and preserve company records; unauthorized users cannot cancel another company's service.

## 5. Organization, masters and accounts

- [ ] MST-01 Organization/company/OU/division/department/designation/job role/position hierarchies persist and scope dependent records correctly.
- [ ] MST-02 Countries/states/cities/pin codes/regions/territories/patches have valid parent links and unique codes as configured.
- [ ] MST-03 Employee creation, login linkage, supervisor changes, active status, grade, department and shift changes propagate to relevant selectors and visibility.
- [ ] MST-04 Number series produces unique document numbers under simultaneous creation and correct period/company boundaries.
- [ ] MST-05 Item masters, categories, brands, packaging, measurement units and SKU conversion ratios reject invalid quantities/ratios and preserve precision.
- [ ] MST-06 Taxes, customer prices, payment terms/methods, shipping methods and terms-and-conditions apply to the correct company and document date.
- [ ] ACC-01 Create/edit doctor, chemist, stockist/customer types supported by configuration; required type-specific fields and active status work.
- [ ] ACC-02 Account assignment/reassignment, activation and reporting classifications update visibility without losing historical activity.
- [ ] ACC-03 Addresses support add/edit/delete and designated defaults; address IDs cannot be attached to unrelated accounts.
- [ ] ACC-04 GPS tagging enforces configured accuracy/radius rules; documented baseline is <=50 m accuracy and 100–250 m radius. Test exact boundaries.
- [ ] ACC-05 RCPA tier and secondary-sales stockist eligibility persist and determine downstream availability.
- [ ] ACC-06 Contacts/images/attachments validate types/sizes, remain private where intended and survive normal record editing.
- [ ] ACC-07 Business/global search finds supported records across allowed modules; names, codes, phone fields and special characters behave consistently.

## 6. Monthly tours, flexible planning and coverage

- [ ] PLAN-01 Create a monthly plan with valid employee, month, working days, patches/accounts and purpose; reopen identical values on web/mobile.
- [ ] PLAN-02 Reject invalid dates, duplicate/conflicting day entries and unavailable assignments according to configured planning policy.
- [ ] PLAN-03 Submit, approve, reject with reason and resubmit; only authorized reviewer can change status and editability follows state.
- [ ] PLAN-04 Strict/flexible planning preferences change allowed field actions consistently in backend and mobile.
- [ ] PLAN-05 Add/reschedule/cancel planned work where supported; completed visits and concurrent mobile captures retain valid links.
- [ ] PLAN-06 Today's tour shows the selected date, correct status and planned/unplanned work; refresh does not duplicate rows.
- [ ] PLAN-07 Flexible plans support configured day purposes, account selection and submission constraints; unauthorized cross-employee changes fail.
- [ ] PLAN-08 Account reach/frequency, uncovered teams and field coverage agree with independently prepared visit fixtures.
- [ ] PLAN-09 Plan changes after download produce explicit conflict/recovery behavior; stale offline capture cannot silently recreate cancelled work.
- [ ] PLAN-10 Manager inbox shows only reviewable plans and refreshes after another reviewer acts.

## 7. Visits, GPS and daily call reports

- [ ] VIS-01 Open a planned visit and an allowed direct visit; repeated opening resolves to the intended record without duplicate calls.
- [ ] VIS-02 Check-in records user, account, time, GPS and policy decision; missing permission/location and low accuracy produce actionable feedback.
- [ ] VIS-03 Check-out requires valid state and chronology; repeated tap/retry cannot create duplicate segments or completion events.
- [ ] VIS-04 Capture purpose, outcome, remarks, joint users and configured visit fields; server rejects users outside allowed assignments.
- [ ] VIS-05 Photos handle camera/picker cancel, compression, upload interruption and invalid media; linked evidence belongs to the correct visit.
- [ ] VIS-06 Inside/outside/on-boundary geofence, stale coordinates and unavailable GPS are evaluated consistently; warnings differ from enforced exceptions.
- [ ] VIS-07 Assigned ASM can approve/reject a geo exception; unrelated reviewer cannot; unresolved required exceptions block DCR lock.
- [ ] VIS-08 GPS trails preserve correct user/time/order and plausible points; restricted users cannot read another team's location history.
- [ ] DCR-01 Authenticated visit creation attaches the correct daily DCR exactly once; no joins confuse employee IDs with user IDs.
- [ ] DCR-02 DCR totals show the right visits, samples, expenses and travel; edits refresh web and mobile views.
- [ ] DCR-03 Meeting/training/travel/administrative day reporting supports the intended DCR without inventing a customer visit.
- [ ] DCR-04 Submit validates required calls, drafts, pending uploads, RCPA, geo reviews and device guards; errors identify the blocking item.
- [ ] DCR-05 Backdate/reporting-window boundaries are enforced using server policy; changing device clock cannot extend permission.
- [ ] DCR-06 Submitted/approved records reject prohibited edits; reopen/reject/resubmit operations, where supported, preserve audit history.
- [ ] DCR-07 Simultaneous submissions and delayed visit events cannot lock a partial day or create multiple final submissions.

## 8. Travel and expenses

- [ ] EXP-01 Travel segment creation links the correct visit/day, origin/destination and transport; invalid sequence and negative distance fail.
- [ ] EXP-02 Standard-fare lookup, patch fares, configured distance source and maps fallback give explainable values for known fixtures.
- [ ] EXP-03 Expense rules respect employee/grade, condition, purpose, transport and effective dates; overlapping or absent rules behave explicitly.
- [ ] EXP-04 Manual expense create/edit/delete validates amount/date/type and closed DCR rules; correct author IDs are saved.
- [ ] EXP-05 Auto-calculate twice and after an input change; expected expenses update without duplicate reimbursement.
- [ ] EXP-06 Receipt upload/download checks MIME/size, interruption/retry and ownership; files cannot attach to another DCR's expense.
- [ ] EXP-07 Approval/rejection and resulting totals match configured routing; unauthorized reviewer changes fail.
- [ ] EXP-08 Imported field-travel data validates mappings and errors; retry preserves existing travel and expense relationships.

## 9. SGIP, marketing, samples and inventory

- [ ] INV-01 Purchase order and GRN workflows validate supplier/items/UOM/quantity and permitted transitions; partial receipts follow implemented policy.
- [ ] INV-02 GRN posting increases the right location/item stock once and writes matching movements; failed posting rolls back completely.
- [ ] INV-03 Transfers debit source and credit destination atomically; insufficient stock and duplicate post are rejected.
- [ ] INV-04 Positive/negative adjustments require valid quantities, reason and permission; balance and movement register reconcile.
- [ ] INV-05 Sample request approval, issue and receipt where supported preserve requester, source/destination and fulfillment quantities.
- [ ] INV-06 Sample issue deducts stock once; simultaneous requests for the last units cannot overdraw stock.
- [ ] SGIP-01 Save draft distribution, change products/quantities/source and submit; correct doctor, visit and representative are retained.
- [ ] SGIP-02 SGIP limits, effective dates and stock availability enforce lower/exact/over-limit boundaries; rejected operations have no stock side effect.
- [ ] SGIP-03 Sample ledger matches issues minus distributions and authorized corrections, across app, ERP and report.
- [ ] SGIP-04 Campaign status/dates, product eligibility and quotas constrain distributions; simultaneous claims cannot exceed quota.
- [ ] SGIP-05 Promotional schemes apply configured scope/type/benefit and effective dates; disabled/expired schemes do not apply to new transactions.
- [ ] SGIP-06 Submitted distributions and posted inventory reject prohibited edits/deletes; any supported reversal retains traceable movements.
- [ ] INV-07 Inventory totals reconcile opening + receipts + incoming transfers + adjustments - outgoing transfers - issues/distributions, with no double deduction between issue and SGIP sources.

## 10. CRM and commercial documents

- [ ] CRM-01 Leads validate source/status/custom fields/owner; activity and follow-up history remain attached after conversion or reassignment.
- [ ] CRM-02 Follow-up date, priority, media, result and status work; overdue/completed views and reminders follow configured rules.
- [ ] CRM-03 Deal stages, values, expected dates and linked account/lead update pipeline totals correctly.
- [ ] CRM-04 Quote-to-order-to-invoice and delivery challan/create-from flows preserve intended lines and reference chains without duplicate conversion.
- [ ] CRM-05 Quick documents from a visit use correct account, employee, items, stock/price context and visit reference.
- [ ] CRM-06 Recalculate quantities, price, discount and tax against independent expected totals; rounding and tax scope match document settings.
- [ ] CRM-07 Customer-specific prices and changed catalog prices apply according to capture/posting policy; unauthorized price tampering fails.
- [ ] CRM-08 Partial quantities and repeated create-from actions cannot exceed allowed source quantities where controlled.
- [ ] CRM-09 Finalized/commercially adjusted orders cannot be overwritten by stale mobile edits; reconciliation is explicit.
- [ ] CRM-10 PDF preview/download preserves branding, number, totals, line wrapping and terms; anonymous/cross-company requests cannot obtain private documents.
- [ ] CRM-11 Document preferences and number series changes affect intended future documents without corrupting historical records.

## 11. Secondary sales, OCR, corrections and performance

- [ ] SEC-01 Download/import structured statement template with eligible stockist and explicit SKU ratios; unknown product/unit and invalid rows fail visibly.
- [ ] SEC-02 Duplicate stockist/period/source submissions follow duplicate policy; original source remains traceable.
- [ ] SEC-03 Reviewer approves/rejects statement with proper scope; only approved transactions contribute to achievement.
- [ ] SEC-04 Opening, purchases, sales, returns and closing values/quantities reconcile against known examples and unit conversions.
- [ ] SEC-05 Scan PDF/image intake stores private originals; invalid files, missing OCR binaries and extraction failures have useful status.
- [ ] SEC-06 Map uncertain OCR fields/products, validate and review before posting; low-quality recognition cannot silently become approved sales.
- [ ] SEC-07 Retry failed or abandoned extraction; late results cannot replace newer attempts. Test documented abandoned-attempt threshold of 15 minutes against configuration.
- [ ] SEC-08 Correction request captures reason and evidence; authorized approval updates balances/achievement once while retaining original history.
- [ ] SEC-09 Concurrent review/correction and repeated requests cannot double-count secondary sales.
- [ ] PERF-01 Territory targets validate period, product/value/quantity and permissions; assignment history attributes sales to the correct employee/date.
- [ ] PERF-02 Achievement, shortfall, percentage, trend and stockist/product totals match approved net secondary transactions, including corrections/returns.
- [ ] PERF-03 Zero target, no transactions and overlapping periods do not produce divide-by-zero, misleading percentages or duplicated totals.
- [ ] PERF-04 Primary approved invoices and RCPA estimates remain separately labeled; they do not inflate secondary-sales achievement.

## 12. RCPA and e-detailing

- [ ] RCPA-01 Download allowed chemists/products/segments/competitors; tiers A/B and configured competitor mappings are correct.
- [ ] RCPA-02 Capture stock, competitor presence, RX share and off-take; invalid ranges/missing required values fail without losing the draft.
- [ ] RCPA-03 Weekly A and fortnightly B due rules behave correctly at boundary dates; due visited chemists block DCR until required observations synchronize.
- [ ] RCPA-04 Retry/sync does not duplicate observations; unrelated employee/company observations remain private.
- [ ] RCPA-05 `[ESTIMATED_FIELD]` remains visible in applicable screens, analytics and exports; estimates are never presented as audited sales.
- [ ] EDET-01 Authorized library/visit material list and preview load correct active content; restricted material/file URLs reject access.
- [ ] EDET-02 Material download/open handles supported formats, corruption, missing files and network interruption without falsely recording completion.
- [ ] EDET-03 Visit detailing activity records intended material and duration/events where supported; repeated upload does not duplicate activity.
- [ ] EDET-04 Test offline material behavior as actually supported; unavailable content gives a clear message and does not imply successful delivery.

## 13. HR, attendance and leave

- [ ] HR-01 Punch-in/out links the employee correctly and records time/location; duplicate and out-of-sequence punches are rejected or normalized per policy.
- [ ] HR-02 Daily attendance handles normal/overnight shifts, missing punches, late arrival, early departure, overtime and breaks against known fixtures.
- [ ] HR-03 Holidays/week-offs/leave affect attendance as configured; recalculation does not duplicate setup or attendance rows.
- [ ] HR-04 Leave types/rules/categories validate eligibility, service dates, limits, balance, overlap and half-day rules where supported.
- [ ] HR-05 Apply/review/reject/cancel leave on web/mobile; balance debit/restoration happens at the configured lifecycle stage exactly once.
- [ ] HR-06 Signed email approval/rejection rejects tampered/expired/replayed links; wrong or already completed step cannot change final state.
- [ ] HR-07 Accrual, adjustment, lapse, carry-forward, encashment and comp-off match configured policy and ledger fixtures.
- [ ] HR-08 Leave instances, balances and payroll leave snapshots agree; historical snapshots do not drift unexpectedly after later edits.
- [ ] HR-09 HR change requests preserve old/new values and route to authorized approvers; rejected requests do not mutate employee data.
- [ ] HR-10 Missing employee mapping produces an actionable response, never another employee's attendance/leave.

## 14. Approval framework and configuration

- [ ] APR-01 Flow/settings selection by company/module/conditions produces correct ordered approval steps.
- [ ] APR-02 Submit, approve, reject, return/resubmit where supported and final dispatch perform the domain side effect once.
- [ ] APR-03 Delegation dates/scope and inactive reviewers are handled; unauthorized/self-review restrictions follow configured policy.
- [ ] APR-04 Concurrent reviewers and repeated action links cannot approve a superseded step or trigger duplicate posting.
- [ ] APR-05 My Approvals badges, lists, detail and audit history match pending tasks and final outcomes.
- [ ] APR-06 Missing approval configuration fails explicitly or uses documented fallback; it cannot silently bypass required approval.
- [ ] CFG-01 Visit/field planning/secondary-sales/date/integration/AI preferences persist and respect administrative permissions.
- [ ] CFG-02 Secrets are masked and never exported in responses/logs; failed integration checks do not overwrite working credentials unexpectedly.

## 15. Sales assistant and external services

- [ ] AI-01 Roadmap/capability response exposes only enabled features; unauthorized records never enter briefing/context.
- [ ] AI-02 Today's priorities, tour-plan draft, day route and pre-call briefing use the correct selected employee/date/accounts.
- [ ] AI-03 Voice-note capture/transcription/apply validates visit state; preview/cancel causes no unintended write and repeated apply is safe.
- [ ] AI-04 SGIP suggestions respect eligibility/stock/limits; final server validation still applies when suggestions become transactions.
- [ ] AI-05 Follow-up scripts and lead/deal next actions stay scoped; generated output cannot directly authorize restricted actions.
- [ ] AI-06 Provider unavailable/timeout/invalid response/rate limit gives fallback or useful error without fabricating a completed action.
- [ ] INT-01 Maps unavailable/quota exhausted/invalid address uses the configured distance behavior and exposes unresolved calculations.
- [ ] INT-02 Email/OTP/notifications use correct tenant branding/recipient and retry without duplicate business effects.
- [ ] INT-03 Queued jobs preserve tenant context, support retries and surface terminal failure; delayed jobs cannot overwrite newer state.

## 16. Reports, dashboards and exports

Run REP-01–REP-06 separately for each report in the inventory appendix, including account reach/frequency, attendance, DCR, visit coverage, punch vs tour, expense, leave, stock movement, SGIP compliance, doctor sample ledger, lead/deal pipeline, overdue follow-ups and sales document register.

- [ ] REP-01 Independently reconcile totals/counts with known source transactions, status rules, reversals and reporting dates.
- [ ] REP-02 Date/employee/OU/territory/account/status filters combine correctly; defaults and reset do not leak stale filters.
- [ ] REP-03 Direct page, underlying query, chart drilldown and export enforce the same visibility rules.
- [ ] REP-04 Empty, large, zero-denominator, missing-related-record and boundary-date datasets produce correct values and useful UI.
- [ ] REP-05 Charts, cards, table totals and exports agree; pagination does not alter aggregate results.
- [ ] REP-06 Downloads preserve Unicode, dates, decimal precision and explicit estimate labels; spreadsheet formula-like text cannot execute unexpectedly.
- [ ] DASH-01 Admin dashboard, field workspace, charts, quick links and setup reminders reflect role, readiness and selected period.
- [ ] DASH-02 Sales/Tenant/Landlord dashboards show their intended scope without replacing required Admin workflows or exposing another company's metrics.

## 17. Mobile installation, navigation and usability

- [ ] MOB-01 Fresh release install, launch and update over previous version preserve intended account/configuration and durable records.
- [ ] MOB-02 Production build has correct API/company behavior, trusted TLS, no demo login and no bundled signing secrets.
- [ ] MOB-03 Login/company selection, home, all menus, back navigation, global search and every inventoried screen work on real devices.
- [ ] MOB-04 Small screen/tablet/rotation/large text/keyboard layouts remain usable; primary actions and validation messages stay visible.
- [ ] MOB-05 Denied/permanently denied camera/files/location permission offers recovery; returning from settings refreshes state.
- [ ] MOB-06 Incoming call, screen lock, background/resume and OS process death preserve supported drafts and avoid duplicate actions.
- [ ] MOB-07 Low storage and large photo sets fail safely without corrupting queues; app restart recovers readable saved work.
- [ ] MOB-08 Loading/empty/error/stale-data states distinguish saved locally, uploaded, rejected and submitted; success is never implied prematurely.
- [ ] MOB-09 Logout with pending work and switching company follow explicit retention policy; user can understand what remains unsent.
- [ ] MOB-10 Validate device battery/network usage during a representative field day and compare against agreed budgets.

## 18. Native offline capture and synchronization — P0

Baseline cache windows documented in the mobile README: verified offline session 7 days, tours 7 days, sample ledger 24 hours, product catalog 30 days, performance snapshots 7 days. Confirm runtime configuration and test immediately before/at/after expiry. Browser offline capture is documented as disabled.

- [ ] OFF-01 Sign in online, cache a day, disable network and reopen the app; permitted session/data are usable and expiry is clearly enforced.
- [ ] OFF-02 Uncached and expired dates/masters cannot misleadingly appear current or accept prohibited capture; user gets a specific recovery path.
- [ ] OFF-03 Capture planned/direct visit open, check-in, report, photos, samples, order and check-out offline; records survive force-stop/restart.
- [ ] OFF-04 Restore network; dependent visit events replay in order and map local IDs to server IDs without orphan files/lines.
- [ ] OFF-05 Save expenses/receipts and RCPA offline; reopen/edit unfinished drafts, finish them and synchronize to the correct DCR.
- [ ] OFF-06 Autosaved sample/order/RCPA drafts restore partial values; discard removes only the draft, never an already saved sync operation.
- [ ] OFF-07 Kill app before send, during upload, after server commit before acknowledgement and during local acknowledgement; replay produces one business effect.
- [ ] OFF-08 Reuse an operation ID with changed payload/file bytes; immutable evidence is protected and conflicting replay is rejected.
- [ ] OFF-09 Intermittent network, server 429/5xx and timeouts use safe retry; validation/auth failures remain actionable rather than endlessly appearing successful.
- [ ] OFF-10 Foreground periodic retry, app-resume retry, connectivity-confirmed retry and Sync now drain eligible work without overlapping duplicate posts.
- [ ] OFF-11 Server revalidates current stock, SGIP quota, price, assignments and geofence; stale local approval cannot bypass current controls.
- [ ] OFF-12 Two devices edit the same order/sample/visit; UTC field merge or explicit conflict follows documented policy and preserves commercially locked records.
- [ ] OFF-13 Queue DCR submission offline; actual submit waits for dependent events, receipts, drafts, reviews and device guards to clear.
- [ ] OFF-14 Expired/revoked token preserves pending evidence and requires correct reauthentication; another user's login cannot send those records.
- [ ] OFF-15 Clock changes, midnight crossing and timezone changes preserve capture time and correct reporting date without bypassing closed windows.
- [ ] OFF-16 Refresh after background sync replaces local state with acknowledged server state; totals and pending counts converge without reopening the app.
- [ ] OFF-17 Upgrade the app with queued records/drafts; storage migration preserves payloads, attachments and operation identities.
- [ ] OFF-18 Browser build clearly limits unsupported offline actions; simulated browser connectivity is not accepted as native durability evidence.

## 19. Device coordination and sync recovery — P0

- [ ] REC-01 Device registration/report/close/retire validates ownership and UUID; unrelated user cannot close or retire another device.
- [ ] REC-02 Pending operations on another active device prevent unsafe DCR finalization; stale/retired-device handling follows actual policy.
- [ ] REC-03 Closed-window failed upload can request assigned authorized review with reason and original evidence; stock/geo/master failures do not become unrestricted overrides.
- [ ] REC-04 Approve/reject review on web and mobile; exact original packet is eligible within the documented seven-day approval window only.
- [ ] REC-05 Approved late upload retains original capture evidence and opens recovered DCR; submit is permitted while ordinary closed-date edits remain restricted.
- [ ] REC-06 Expired approval can be reviewed again without duplicate posting; pending/approved-unsynced recovery blocks premature DCR lock.
- [ ] REC-07 Keep-server resolution requires representative and manager reconciliation/evidence; stale reviewed server snapshot forces fresh review.
- [ ] REC-08 Sync approved keep-server packet; stock/order remain unchanged, original evidence remains and queue explicitly states failed changes were not posted.
- [ ] REC-09 Corrected sample replacement includes full distribution/source; order replacement includes changed lines, zero removes and omitted lines remain.
- [ ] REC-10 Approved replacement posts a separate linked event exactly once, preserving original evidence/time and rechecking stock/quota/document locks.
- [ ] REC-11 After replacement or keep-server resolution, later visit events continue safely and sample ledger refreshes to authoritative stock.
- [ ] REC-12 Concurrent review, retirement, DCR submission and packet retry cannot bypass state guards or lose evidence.

## 20. API contract and security pack

Apply API-01–API-07 to every route in the route appendix, separating public authentication routes from authenticated routes.

- [ ] API-01 Valid request returns documented status/schema, pagination and identifiers; web/mobile deserialize nullable values correctly.
- [ ] API-02 Missing/expired authentication, wrong role, wrong tenant and altered parent/child IDs return safe errors with no data mutation.
- [ ] API-03 Missing/extra/invalid fields, malformed JSON, oversized uploads and unsupported content types fail clearly without a server exception.
- [ ] API-04 Client-supplied actor/tenant/approval/posted fields cannot bypass server ownership or workflow policy.
- [ ] API-05 Duplicate requests and idempotency keys behave correctly for each mutation; replay does not duplicate receipts, orders, deductions or approvals.
- [ ] API-06 Pagination boundaries, search/filter encoding, dates/timezones, money/quantity precision and empty collections remain stable.
- [ ] API-07 Rate limits, timeouts and unavailable dependencies produce usable mobile error/retry behavior without exposing tokens, secrets or stack traces.
- [ ] SECUR-01 Web CSRF/session handling, uploaded-file access, injection-like inputs and stored-script payloads are rejected/escaped appropriately.
- [ ] SECUR-02 Private PDFs, receipts, photos, OCR originals and material files cannot be guessed/downloaded across companies or without authorization.
- [ ] SECUR-03 Mobile token/cache storage and logs are reviewed; exported diagnostics exclude secrets and unnecessary customer/location data.

## 21. Performance, resilience and operations

- [ ] OPS-01 Agree measurable page/API response, concurrent-user, queue-drain, memory and field-day battery targets before acceptance; record p50/p95/p99 and error rates.
- [ ] OPS-02 Exercise large accounts/tours/reports/imports and concurrent field-day traffic; pagination and eager loading avoid unbounded memory/query growth.
- [ ] OPS-03 Stress last-stock allocation, simultaneous approvals, duplicate company launch, same-plan edits and DCR submission; invariants remain true.
- [ ] OPS-04 Run a mixed multi-hour soak with captures, uploads, reports and worker restarts; no queue starvation, growing leaks or unexplained failures.
- [ ] OPS-05 Restart workers/database connection during posting/upload; atomic transactions recover without half-posted stock or commercial records.
- [ ] OPS-06 Backup and restore disposable company DB plus private attachments; restored ledgers and document links reconcile.
- [ ] OPS-07 Deploy backend/mobile-compatible changes with old clients still syncing; migrations and roll-forward/recovery preserve queued work.
- [ ] OPS-08 Scheduler jobs for attendance/leave/billing/reminders run with correct dates/company context and safe repeat behavior where present.
- [ ] OPS-09 Error monitoring identifies failed jobs/sync operations with correlation IDs and safe diagnostic details; operations team can locate a failed transaction.

## 22. End-to-end release journeys

- [ ] E2E-01 Fresh company setup → masters/imports → employee login → approved tour → mobile punch-in → visit/check-in → SGIP/order → checkout → expense → DCR → manager review → reports reconcile.
- [ ] E2E-02 Offline full day → force-stop → resume → reconnect → exact-once posting → receipts/RCPA clear → DCR submits → ERP totals match mobile.
- [ ] E2E-03 Two representatives compete for last sample stock → one valid posting → conflict retained → authorized correction/recovery → inventory reconciles.
- [ ] E2E-04 Plan changed during offline capture → explicit conflict → review → recovered activity visible without recreating cancelled/duplicate plans.
- [ ] E2E-05 Lead → follow-up → deal → quote → order → invoice/PDF → sales register, with role and financial totals verified throughout.
- [ ] E2E-06 Leave application → manager approval → attendance calculation → ledger/payroll snapshot; cancellation restores only intended balances.
- [ ] E2E-07 OCR statement → human mapping → review → approved secondary sale → correction → target achievement recalculates without changing primary/RCPA classifications.
- [ ] E2E-08 Device A pending work + device B submission → guard → synchronize/resolve → one final DCR.
- [ ] E2E-09 Repeat core journey for company B with similar IDs; search, exports, files, approvals and mobile cache remain isolated.

## 23. Automation and sign-off

Existing test filenames are navigation aids, not coverage or pass claims. Backend suites include API/V1, Reports, Approval, Attendance, Leave, Inventory, Filament, Onboarding, Tenancy, field concurrency/performance/soak and company launch tests. Flutter suites include field flow, offline fieldwork/expenses/RCPA/DCR, sync recovery/replacement, device coordination, planning, accounts, attendance/leave, e-detailing, performance and AI.

- [ ] QA-01 Map each checklist ID to specific existing assertions or manual execution; mark missing coverage explicitly.
- [ ] QA-02 Run scoped backend tests then full applicable regression in the verified isolated test environment; record command, count, failures and skipped tests.
- [ ] QA-03 Follow repository Pest guidance: reportingUser/reportingShift/reportingLeaveType fixtures; retain VisitObserver side effects when testing DCR attachment; account for ShiftMaster setup rows.
- [ ] QA-04 Run Flutter analysis and tests, release build validation and real-device integration journeys; mocks do not establish live GPS, upload durability or server integration correctness.
- [ ] QA-05 Capture browser errors, backend exceptions, failed jobs and mobile diagnostics during actual journeys; attach evidence to defects.
- [ ] QA-06 Retest fixes and adjacent workflows against the final release builds; reassess cases affected by concurrent source changes.
- [ ] QA-07 Release only with all applicable P0 cases passing, no unresolved critical/high defects, P1 results reviewed, reconciled stock/financial/attendance totals and explicit owner acceptance of any lower-priority exceptions.
- [ ] QA-08 Record sign-off by product, QA, field operations, finance/inventory and HR owners as applicable; list blocked/N/A cases with reasons and remaining platform limitations.

## Source basis

Inventory and scenarios were informed by `routes/api.php`, `routes/web.php`, Filament resources/pages/panel providers, `app/Services`, backend test filenames, Flutter `lib/screens`, Flutter test filenames and the mobile README. The route/resource/screen appendices below are generated from this workspace snapshot. Sources may contain in-progress changes; inventory is not a runtime route-discovery or exhaustive line-by-line audit.

## Appendix A. ERP resource inventory

Apply GEN-01–GEN-13 separately to each resource. Discover supported actions in its Pages, Schemas, Tables and RelationManagers. Base/global-search infrastructure entries require their applicable search/access checks rather than invented CRUD actions.

- [ ] `app/Filament/Resources/AccountMasters/AccountMasterResource.php` — Not run.
- [ ] `app/Filament/Resources/AccountReportingMasters/AccountReportingMasterResource.php` — Not run.
- [ ] `app/Filament/Resources/AccountStatuses/AccountStatusResource.php` — Not run.
- [ ] `app/Filament/Resources/AddressTypes/AddressTypeResource.php` — Not run.
- [ ] `app/Filament/Resources/ApprovalDelegations/ApprovalDelegationResource.php` — Not run.
- [ ] `app/Filament/Resources/ApprovalFlows/ApprovalFlowResource.php` — Not run.
- [ ] `app/Filament/Resources/Approvals/ApprovalResource.php` — Not run.
- [ ] `app/Filament/Resources/ApprovalSettings/ApprovalSettingResource.php` — Not run.
- [ ] `app/Filament/Resources/Attachments/AttachmentResource.php` — Not run.
- [ ] `app/Filament/Resources/BaseResource.php` — Not run.
- [ ] `app/Filament/Resources/Categories/CategoryResource.php` — Not run.
- [ ] `app/Filament/Resources/Cities/CityResource.php` — Not run.
- [ ] `app/Filament/Resources/CityPinCodes/CityPinCodeResource.php` — Not run.
- [ ] `app/Filament/Resources/Companies/CompanyResource.php` — Not run.
- [ ] `app/Filament/Resources/ContactDetails/ContactDetailResource.php` — Not run.
- [ ] `app/Filament/Resources/Countries/CountryResource.php` — Not run.
- [ ] `app/Filament/Resources/CustomerPrices/CustomerPriceResource.php` — Not run.
- [ ] `app/Filament/Resources/DailyAttendances/DailyAttendanceResource.php` — Not run.
- [ ] `app/Filament/Resources/Deals/DealResource.php` — Not run.
- [ ] `app/Filament/Resources/DeliveryChallans/DeliveryChallanResource.php` — Not run.
- [ ] `app/Filament/Resources/Departments/DepartmentResource.php` — Not run.
- [ ] `app/Filament/Resources/Designations/DesignationResource.php` — Not run.
- [ ] `app/Filament/Resources/EDetailingMaterials/EDetailingMaterialResource.php` — Not run.
- [ ] `app/Filament/Resources/EmpDepartments/EmpDepartmentResource.php` — Not run.
- [ ] `app/Filament/Resources/EmpGrades/EmpGradeResource.php` — Not run.
- [ ] `app/Filament/Resources/EmpJobTitles/EmpJobTitleResource.php` — Not run.
- [ ] `app/Filament/Resources/EmployeeAttendances/EmployeeAttendanceResource.php` — Not run.
- [ ] `app/Filament/Resources/EmployeeAttendanceStatuses/EmployeeAttendanceStatusResource.php` — Not run.
- [ ] `app/Filament/Resources/Employees/EmployeeResource.php` — Not run.
- [ ] `app/Filament/Resources/ExpenseConfigurations/ExpenseConfigurationResource.php` — Not run.
- [ ] `app/Filament/Resources/ExpenseTypes/ExpenseTypeResource.php` — Not run.
- [ ] `app/Filament/Resources/FieldDevices/FieldDeviceResource.php` — Not run.
- [ ] `app/Filament/Resources/FieldSyncRecoveries/FieldSyncRecoveryResource.php` — Not run.
- [ ] `app/Filament/Resources/FollowUpMedia/FollowUpMediaResource.php` — Not run.
- [ ] `app/Filament/Resources/FollowUpPriorities/FollowUpPriorityResource.php` — Not run.
- [ ] `app/Filament/Resources/FollowUpResults/FollowUpResultResource.php` — Not run.
- [ ] `app/Filament/Resources/FollowUps/FollowUpResource.php` — Not run.
- [ ] `app/Filament/Resources/FollowUpStatuses/FollowUpStatusResource.php` — Not run.
- [ ] `app/Filament/Resources/GlobalSearchResource.php` — Not run.
- [ ] `app/Filament/Resources/GoodsReceiptNotes/GoodsReceiptNoteResource.php` — Not run.
- [ ] `app/Filament/Resources/Holidays/HolidayResource.php` — Not run.
- [ ] `app/Filament/Resources/HrChangeRequests/HrChangeRequestResource.php` — Not run.
- [ ] `app/Filament/Resources/Images/ImageResource.php` — Not run.
- [ ] `app/Filament/Resources/IndustryTypes/IndustryTypeResource.php` — Not run.
- [ ] `app/Filament/Resources/InventoryAdjustments/InventoryAdjustmentResource.php` — Not run.
- [ ] `app/Filament/Resources/InventoryMovements/InventoryMovementResource.php` — Not run.
- [ ] `app/Filament/Resources/InventoryStocks/InventoryStockResource.php` — Not run.
- [ ] `app/Filament/Resources/InventoryTransfers/InventoryTransferResource.php` — Not run.
- [ ] `app/Filament/Resources/ItemBrands/ItemBrandResource.php` — Not run.
- [ ] `app/Filament/Resources/ItemMasters/ItemMasterResource.php` — Not run.
- [ ] `app/Filament/Resources/ItemMeasurementUnits/ItemMeasurementUnitResource.php` — Not run.
- [ ] `app/Filament/Resources/JobRoles/JobRoleResource.php` — Not run.
- [ ] `app/Filament/Resources/LeadActivities/LeadActivityResource.php` — Not run.
- [ ] `app/Filament/Resources/LeadCustomFields/LeadCustomFieldResource.php` — Not run.
- [ ] `app/Filament/Resources/Leads/LeadResource.php` — Not run.
- [ ] `app/Filament/Resources/LeadSources/LeadSourceResource.php` — Not run.
- [ ] `app/Filament/Resources/LeadStatuses/LeadStatusResource.php` — Not run.
- [ ] `app/Filament/Resources/LeaveAdjustments/LeaveAdjustmentResource.php` — Not run.
- [ ] `app/Filament/Resources/LeaveApplications/LeaveApplicationResource.php` — Not run.
- [ ] `app/Filament/Resources/LeaveBalances/LeaveBalanceResource.php` — Not run.
- [ ] `app/Filament/Resources/LeaveCancellations/LeaveCancellationResource.php` — Not run.
- [ ] `app/Filament/Resources/LeaveEncashments/LeaveEncashmentResource.php` — Not run.
- [ ] `app/Filament/Resources/LeaveInstances/LeaveInstanceResource.php` — Not run.
- [ ] `app/Filament/Resources/LeaveLapseRecords/LeaveLapseRecordResource.php` — Not run.
- [ ] `app/Filament/Resources/LeaveRuleCategories/LeaveRuleCategoryResource.php` — Not run.
- [ ] `app/Filament/Resources/LeaveRules/LeaveRuleResource.php` — Not run.
- [ ] `app/Filament/Resources/LeaveTypes/LeaveTypeResource.php` — Not run.
- [ ] `app/Filament/Resources/LocationMasters/LocationMasterResource.php` — Not run.
- [ ] `app/Filament/Resources/MarketingCampaigns/MarketingCampaignResource.php` — Not run.
- [ ] `app/Filament/Resources/NumberSeries/NumberSeriesResource.php` — Not run.
- [ ] `app/Filament/Resources/OrganizationalUnits/OrganizationalUnitResource.php` — Not run.
- [ ] `app/Filament/Resources/Organizations/OrganizationResource.php` — Not run.
- [ ] `app/Filament/Resources/PackagingTypes/PackagingTypeResource.php` — Not run.
- [ ] `app/Filament/Resources/Patches/PatchResource.php` — Not run.
- [ ] `app/Filament/Resources/PaymentMethods/PaymentMethodResource.php` — Not run.
- [ ] `app/Filament/Resources/PaymentTerms/PaymentTermResource.php` — Not run.
- [ ] `app/Filament/Resources/PayrollLeaveSnapshots/PayrollLeaveSnapshotResource.php` — Not run.
- [ ] `app/Filament/Resources/Positions/PositionResource.php` — Not run.
- [ ] `app/Filament/Resources/PromotionalSchemes/PromotionalSchemeResource.php` — Not run.
- [ ] `app/Filament/Resources/PurchaseOrders/PurchaseOrderResource.php` — Not run.
- [ ] `app/Filament/Resources/Quotes/QuoteResource.php` — Not run.
- [ ] `app/Filament/Resources/RatingTypes/RatingTypeResource.php` — Not run.
- [ ] `app/Filament/Resources/RcpaCompetitors/RcpaCompetitorResource.php` — Not run.
- [ ] `app/Filament/Resources/RcpaObservations/RcpaObservationResource.php` — Not run.
- [ ] `app/Filament/Resources/Regions/RegionResource.php` — Not run.
- [ ] `app/Filament/Resources/SalesDcrExpenses/SalesDcrExpenseResource.php` — Not run.
- [ ] `app/Filament/Resources/SalesDcrs/SalesDcrResource.php` — Not run.
- [ ] `app/Filament/Resources/SalesDocumentPreferences/SalesDocumentPreferenceResource.php` — Not run.
- [ ] `app/Filament/Resources/SalesInvoices/SalesInvoiceResource.php` — Not run.
- [ ] `app/Filament/Resources/SalesOrders/SalesOrderResource.php` — Not run.
- [ ] `app/Filament/Resources/SalesTourPlans/SalesTourPlanResource.php` — Not run.
- [ ] `app/Filament/Resources/SampleIssues/SampleIssueResource.php` — Not run.
- [ ] `app/Filament/Resources/SampleRequests/SampleRequestResource.php` — Not run.
- [ ] `app/Filament/Resources/SecondaryOcrSources/SecondaryOcrSourceResource.php` — Not run.
- [ ] `app/Filament/Resources/SecondarySalesCorrections/SecondarySalesCorrectionResource.php` — Not run.
- [ ] `app/Filament/Resources/SecondarySalesStatements/SecondarySalesStatementResource.php` — Not run.
- [ ] `app/Filament/Resources/SgipDistributions/SgipDistributionResource.php` — Not run.
- [ ] `app/Filament/Resources/SgipLimits/SgipLimitResource.php` — Not run.
- [ ] `app/Filament/Resources/ShiftMasters/ShiftMasterResource.php` — Not run.
- [ ] `app/Filament/Resources/ShippingMethods/ShippingMethodResource.php` — Not run.
- [ ] `app/Filament/Resources/SkuUnitConversions/SkuUnitConversionResource.php` — Not run.
- [ ] `app/Filament/Resources/StandardFareCharts/StandardFareChartResource.php` — Not run.
- [ ] `app/Filament/Resources/States/StateResource.php` — Not run.
- [ ] `app/Filament/Resources/Taxes/TaxResource.php` — Not run.
- [ ] `app/Filament/Resources/Tenants/TenantResource.php` — Not run.
- [ ] `app/Filament/Resources/TenantUsers/TenantUserResource.php` — Not run.
- [ ] `app/Filament/Resources/TermsAndConditionsMasters/TermsAndConditionsMasterResource.php` — Not run.
- [ ] `app/Filament/Resources/Territories/TerritoryResource.php` — Not run.
- [ ] `app/Filament/Resources/TerritorySalesAssignments/TerritorySalesAssignmentResource.php` — Not run.
- [ ] `app/Filament/Resources/TerritorySalesTargets/TerritorySalesTargetResource.php` — Not run.
- [ ] `app/Filament/Resources/TransportModes/TransportModeResource.php` — Not run.
- [ ] `app/Filament/Resources/TravelSegments/TravelSegmentResource.php` — Not run.
- [ ] `app/Filament/Resources/TypeMasters/TypeMasterResource.php` — Not run.
- [ ] `app/Filament/Resources/UnitOfMeasurements/UnitOfMeasurementResource.php` — Not run.
- [ ] `app/Filament/Resources/Users/UserResource.php` — Not run.
- [ ] `app/Filament/Resources/VisitPurposes/VisitPurposeResource.php` — Not run.
- [ ] `app/Filament/Resources/Visits/VisitResource.php` — Not run.
- [ ] `app/Filament/Resources/VisitTypes/VisitTypeResource.php` — Not run.
- [ ] `app/Filament/Resources/WeekOffs/WeekOffResource.php` — Not run.

## Appendix B. ERP pages and dashboards

- [ ] `app/Filament/Landlord/Pages/Dashboard.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/AiConfiguration.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/Dashboard.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/DataImport.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/DoctorSampleLedger.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/FieldCoverage.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/FieldPlanningPreferences.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/FieldTeamOnboarding.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/FieldTravelImport.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/FlexiblePlanning.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/IntegrationSettings.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/LeaveLedger.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/MyApprovals.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/MyQuickLinks.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/Onboarding.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/OnboardingImports.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/OptionalModules.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/PunchIn.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/Reports/AccountReachFrequency.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/Reports/AttendanceRegister.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/Reports/DcrRegister.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/Reports/DealPipeline.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/Reports/DoctorSampleLedger.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/Reports/ExpenseSummary.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/Reports/LeadPipeline.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/Reports/LeaveLedger.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/Reports/OverdueFollowUps.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/Reports/PunchVsTour.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/Reports/ReportsOverview.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/Reports/SalesDocumentRegister.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/Reports/SgipCompliance.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/Reports/StockMovementRegister.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/Reports/VisitCoverage.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/SalesAssistant.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/SecondarySalesPreferences.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/TodaysTour.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Pages/VisitPreferences.php` — navigation, permission, all page actions, data accuracy and error states; Not run.
- [ ] `app/Filament/Tenant/Pages/Dashboard.php` — navigation, permission, all page actions, data accuracy and error states; Not run.

## Appendix C. Flutter screen inventory

For each screen: open as authorized/unauthorized roles; complete each available action; verify loading, empty, error, back/resume and supported offline states. Reconcile writes with ERP. A screen file may contain multiple dialogs/workflows; exercise each.

- [ ] `lib/screens/account_address_form_screen.dart` — Not run.
- [ ] `lib/screens/account_form_screen.dart` — Not run.
- [ ] `lib/screens/accounts_screen.dart` — Not run.
- [ ] `lib/screens/attendance_leave_screen.dart` — Not run.
- [ ] `lib/screens/dcr_screen.dart` — Not run.
- [ ] `lib/screens/e_detailing_screen.dart` — Not run.
- [ ] `lib/screens/field_coverage_screen.dart` — Not run.
- [ ] `lib/screens/field_devices_screen.dart` — Not run.
- [ ] `lib/screens/flexible_planning_screen.dart` — Not run.
- [ ] `lib/screens/geo_exceptions_screen.dart` — Not run.
- [ ] `lib/screens/global_search_screen.dart` — Not run.
- [ ] `lib/screens/login_screen.dart` — Not run.
- [ ] `lib/screens/mtp_plan_screen.dart` — Not run.
- [ ] `lib/screens/mtp_screen.dart` — Not run.
- [ ] `lib/screens/offline_expenses_screen.dart` — Not run.
- [ ] `lib/screens/offline_tours_screen.dart` — Not run.
- [ ] `lib/screens/pending_work_screen.dart` — Not run.
- [ ] `lib/screens/rcpa_screen.dart` — Not run.
- [ ] `lib/screens/sales_assistant_screen.dart` — Not run.
- [ ] `lib/screens/sales_performance_screen.dart` — Not run.
- [ ] `lib/screens/sync_recovery_screen.dart` — Not run.
- [ ] `lib/screens/todays_tour_screen.dart` — Not run.
- [ ] `lib/screens/visit_edit_screen.dart` — Not run.
- [ ] `lib/screens/visit_screen.dart` — Not run.
- [ ] `lib/screens/visit_sgip_screen.dart` — Not run.

## Appendix D. Declared API endpoint inventory

All listed paths are beneath `/api/v1`. Apply API-01–API-07 separately. These entries reflect literal declarations in routes/api.php; runtime middleware and authorization must be tested. Public login/OTP endpoints still require validation and rate-limit checks.

- [ ] `POST /api/v1/login` — Not run.
- [ ] `POST /api/v1/login/otp/request` — Not run.
- [ ] `POST /api/v1/login/otp/verify` — Not run.
- [ ] `POST /api/v1/field-visit-events` — Not run.
- [ ] `POST /api/v1/field-expense-events` — Not run.
- [ ] `GET /api/v1/field-sync-recoveries` — Not run.
- [ ] `GET /api/v1/field-sync-recoveries/reviewers` — Not run.
- [ ] `POST /api/v1/field-sync-recoveries/{operation}/request` — Not run.
- [ ] `PATCH /api/v1/field-sync-recoveries/{recovery}/review` — Not run.
- [ ] `GET /api/v1/field-devices` — Not run.
- [ ] `PUT /api/v1/field-devices/{device}` — Not run.
- [ ] `POST /api/v1/field-devices/{device}/close` — Not run.
- [ ] `POST /api/v1/field-devices/{registration}/retire` — Not run.
- [ ] `GET /api/v1/sample-ledger` — Not run.
- [ ] `GET /api/v1/order-catalog` — Not run.
- [ ] `GET /api/v1/sales-targets` — Not run.
- [ ] `GET /api/v1/sales-targets/territories` — Not run.
- [ ] `POST /api/v1/sales-targets` — Not run.
- [ ] `GET /api/v1/sales-targets/{target}/achievement` — Not run.
- [ ] `POST /api/v1/territory-sales-assignments` — Not run.
- [ ] `GET /api/v1/rcpa/options` — Not run.
- [ ] `GET /api/v1/rcpa-observations` — Not run.
- [ ] `POST /api/v1/rcpa-observations` — Not run.
- [ ] `GET /api/v1/secondary-sales/template` — Not run.
- [ ] `GET /api/v1/secondary-sales/reviewers` — Not run.
- [ ] `GET /api/v1/secondary-sales` — Not run.
- [ ] `POST /api/v1/secondary-ocr` — Not run.
- [ ] `GET /api/v1/secondary-ocr` — Not run.
- [ ] `GET /api/v1/secondary-ocr/{source}` — Not run.
- [ ] `GET /api/v1/secondary-ocr/{source}/file` — Not run.
- [ ] `GET /api/v1/secondary-sales/corrections` — Not run.
- [ ] `POST /api/v1/secondary-sales/corrections/{correction}/review` — Not run.
- [ ] `POST /api/v1/secondary-sales/{statement}/corrections` — Not run.
- [ ] `POST /api/v1/secondary-sales` — Not run.
- [ ] `GET /api/v1/secondary-sales/{statement}` — Not run.
- [ ] `GET /api/v1/secondary-sales/{statement}/source` — Not run.
- [ ] `POST /api/v1/secondary-sales/{statement}/review` — Not run.
- [ ] `POST /api/v1/field-dcr-events` — Not run.
- [ ] `GET /api/v1/geo-exceptions` — Not run.
- [ ] `PATCH /api/v1/geo-exceptions/{assessment}` — Not run.
- [ ] `GET /api/v1/accounts/reporting-options` — Not run.
- [ ] `GET /api/v1/e-detailing` — Not run.
- [ ] `GET /api/v1/e-detailing/{material}/file` — Not run.
- [ ] `GET /api/v1/visits/{visit}/e-detailing` — Not run.
- [ ] `GET /api/v1/visits/{visit}/e-detailing/{material}/file` — Not run.
- [ ] `POST /api/v1/visits/{visit}/e-detailing` — Not run.
- [ ] `POST /api/v1/logout` — Not run.
- [ ] `GET /api/v1/me` — Not run.
- [ ] `GET /api/v1/flexible-plans` — Not run.
- [ ] `POST /api/v1/flexible-plans` — Not run.
- [ ] `GET /api/v1/field-coverage` — Not run.
- [ ] `GET /api/v1/attendance/today` — Not run.
- [ ] `GET /api/v1/attendance` — Not run.
- [ ] `POST /api/v1/attendance/punch-in` — Not run.
- [ ] `POST /api/v1/attendance/punch-out` — Not run.
- [ ] `GET /api/v1/leave-types` — Not run.
- [ ] `GET /api/v1/leave-applications` — Not run.
- [ ] `POST /api/v1/leave-applications` — Not run.
- [ ] `POST /api/v1/leave-applications/{application}/cancel` — Not run.
- [ ] `GET /api/v1/todays-tour` — Not run.
- [ ] `POST /api/v1/tour-plan-details/{detail}/visits` — Not run.
- [ ] `POST /api/v1/tour-plan-details/{detail}/visits/action` — Not run.
- [ ] `GET /api/v1/monthly-tour-plans/options` — Not run.
- [ ] `GET /api/v1/monthly-tour-plans/inbox` — Not run.
- [ ] `GET /api/v1/monthly-tour-plans` — Not run.
- [ ] `POST /api/v1/monthly-tour-plans` — Not run.
- [ ] `GET /api/v1/monthly-tour-plans/{plan}` — Not run.
- [ ] `PUT /api/v1/monthly-tour-plans/{plan}` — Not run.
- [ ] `POST /api/v1/monthly-tour-plans/{plan}/submit` — Not run.
- [ ] `POST /api/v1/monthly-tour-plans/{plan}/approve` — Not run.
- [ ] `POST /api/v1/monthly-tour-plans/{plan}/reject` — Not run.
- [ ] `GET /api/v1/dcrs/today` — Not run.
- [ ] `GET /api/v1/dcrs/{dcr}` — Not run.
- [ ] `POST /api/v1/dcrs/{dcr}/submit` — Not run.
- [ ] `GET /api/v1/dcrs/{dcr}/expenses` — Not run.
- [ ] `POST /api/v1/dcrs/{dcr}/expenses` — Not run.
- [ ] `POST /api/v1/dcrs/{dcr}/expenses/{expense}/receipt` — Not run.
- [ ] `GET /api/v1/dcrs/{dcr}/expenses/{expense}/receipts/{receipt}` — Not run.
- [ ] `PATCH /api/v1/dcrs/{dcr}/expenses/{expense}` — Not run.
- [ ] `DELETE /api/v1/dcrs/{dcr}/expenses/{expense}` — Not run.
- [ ] `POST /api/v1/dcrs/{dcr}/expenses/auto-calculate` — Not run.
- [ ] `GET /api/v1/expense-types` — Not run.
- [ ] `GET /api/v1/items` — Not run.
- [ ] `GET /api/v1/account-types` — Not run.
- [ ] `GET /api/v1/address-types` — Not run.
- [ ] `GET /api/v1/pin-codes` — Not run.
- [ ] `GET /api/v1/accounts` — Not run.
- [ ] `POST /api/v1/accounts` — Not run.
- [ ] `GET /api/v1/accounts/{account}` — Not run.
- [ ] `PATCH /api/v1/accounts/{account}` — Not run.
- [ ] `GET /api/v1/accounts/{account}/addresses` — Not run.
- [ ] `POST /api/v1/accounts/{account}/addresses` — Not run.
- [ ] `GET /api/v1/accounts/{account}/addresses/{address}` — Not run.
- [ ] `PATCH /api/v1/accounts/{account}/addresses/{address}` — Not run.
- [ ] `DELETE /api/v1/accounts/{account}/addresses/{address}` — Not run.
- [ ] `POST /api/v1/visits/direct` — Not run.
- [ ] `GET /api/v1/visits/options` — Not run.
- [ ] `GET /api/v1/visits/{visit}` — Not run.
- [ ] `PATCH /api/v1/visits/{visit}` — Not run.
- [ ] `POST /api/v1/visits/{visit}/documents` — Not run.
- [ ] `POST /api/v1/visits/{visit}/check-in` — Not run.
- [ ] `POST /api/v1/visits/{visit}/check-out` — Not run.
- [ ] `POST /api/v1/visits/{visit}/photos` — Not run.
- [ ] `GET /api/v1/visits/{visit}/sgip` — Not run.
- [ ] `PUT /api/v1/visits/{visit}/sgip` — Not run.
- [ ] `POST /api/v1/visits/{visit}/sgip/submit` — Not run.
- [ ] `GET /api/v1/salesperson-ai/roadmap` — Not run.
- [ ] `GET /api/v1/todays-priorities` — Not run.
- [ ] `GET /api/v1/tour-plan-draft` — Not run.
- [ ] `GET /api/v1/day-route` — Not run.
- [ ] `GET /api/v1/visits/{visit}/briefing` — Not run.
- [ ] `POST /api/v1/visits/{visit}/voice-note` — Not run.
- [ ] `POST /api/v1/visits/{visit}/voice-note/apply` — Not run.
- [ ] `GET /api/v1/visits/{visit}/sgip/suggestions` — Not run.
- [ ] `GET /api/v1/follow-ups/{followUp}/script` — Not run.
- [ ] `GET /api/v1/leads/{lead}/next-action` — Not run.
- [ ] `GET /api/v1/deals/{deal}/next-action` — Not run.
- [ ] `POST /api/v1/gps-points` — Not run.
