# Shatya Info: full ERP mobile parity plan

Prepared: 15 November 2026. Updated: 16 November 2026. Status: foundations and selected workflows implemented; remaining delivery is planned in section 10. This update changes planning documents only.

## 1. Outcome and boundaries

Extend C:/Herd/grahak_field to cover every module, page and supported business action in C:/Herd/it-erp's Admin panel. Preserve menu group order, item order, labels, nested relationships, permissions and record visibility. Adapt layouts to phones. Super Admin continues assigning roles in ERP; mobile uses the same authorization decisions.

The Admin panel is the scope baseline. Sales/Tenant/Landlord panels do not replace it. Any non-Admin workflows discovered are recorded separately, not silently added or used to exclude Admin pages. No permanent web-only exclusions without the user's decision. On 15 November 2026 the user excluded Settings & Administration from the mobile app. That web group stays in Admin and is not a mobile gap. Unsupported mobile screens during development are tracked as gaps, not counted as delivered.

Use the existing Flutter project, API client, repositories, authentication, theme and offline machinery. No new app, framework migration, generic server-driven form engine, or dependency changes as a shortcut. Keep business calculations, approvals and posting in shared Laravel services. Existing roadmap commitments remain valid; this plan adds full Admin parity and does not restart completed work.

## 2. Evidence and first checks

Inspected sources:
- ERP app/Providers/Filament/AdminPanelProvider.php: ten ordered navigation groups, Filament Shield integration.
- ERP routes/api.php: substantial authenticated /api/v1 coverage already exists.
- ERP app/Http/Resources/Api/V1/UserResource.php: planning/module/company capabilities exist; complete ordered navigation and action permissions are absent here.
- Flutter lib/app.dart, lib/models/field_user.dart, lib/screens, lib/api and test: existing native screens, session model, repositories and tests to reuse.
- Flutter roadmap.md and field-operations-roadmap.md: substantial previous delivery plus unresolved physical-device/business acceptance. Read current-status sections first, history only for the current feature.
- ERP docs/full-application-mobile-testing-checklist.md: reuse its case IDs and acceptance criteria; it is not a completed test report.

Version discrepancy: composer.lock records Laravel v13.42.0 and Filament v5.11.3; AGENTS.md names Laravel 12. Task P00 verifies installed/runtime versions and records the discrepancy before selecting version-specific documentation. Do not silently upgrade/downgrade dependencies or rewrite repository rules.

The original inspection was not a complete page/API/permission audit. The inventory and working state now contain subsequent delivery evidence. The 16 November review ran all 300 Flutter tests successfully; analysis of lib/test reported two informational curly-brace notices in test/salesperson_ai_test.dart and no errors or warnings. No backend suite or live role-by-role device acceptance was run in that review. Section 10 uses this baseline without treating test count as module completion.

## 3. Architecture decisions

### Shared navigation and authorization

Implement a versioned, additive mobile bootstrap/navigation contract in the existing /api/v1 API. P02 selects the endpoint and records one exact example; prefer a dedicated bootstrap endpoint so existing login and /me clients remain compatible.

A menu item needs a stable resource/page key, label, group/parent key, group/item ordering and a stable mobile screen key. The server filters navigation using effective user permissions, company-enabled modules and page access conditions. Global action capabilities are separate from per-record actions, which also depend on ownership, state and approval rules.

Flutter maps known screen keys to native screens. The server never supplies executable code. Unknown/new keys must not crash or grant access; retain them in the parity backlog and report unsupported destinations in development. Deploy server support before exposing a new mobile destination. A menu definition alone cannot create a Flutter screen.

Keep ordering metadata in one backend source used by web and API where practical. Extract incrementally from existing navigation definitions; do not rewrite all resources at once. Add a drift check comparing the Admin navigation metadata and mobile registry, including conditional/custom pages. Avoid permanent duplicate hardcoded group/item order in Dart.

API policies/gates and existing record scopes are authoritative on every request. Hiding menus, client roles, token abilities and cached capabilities never substitute for authorization. Reuse existing Super Admin and scope-bypass semantics; do not turn AccessAllRecords into blanket action permission. Preserve company isolation even for scope bypasses. Guard related selectors, files, exports, search results and bulk actions too.

Refresh navigation at login, session restoration/resume and after authorization failures. A 403 removes stale access after refresh; a revoked/expired session follows existing reauthentication behaviour. Do not erase unsynced evidence merely because authorization fails.

### Offline and compatibility

Preserve existing field capture, durable IDs, dependency chains, retries and recovery workflows. New administrative/stock/financial mutations are online-only initially unless their resource task explicitly establishes safe offline semantics. Do not regress existing offline order/sample capture.

Scope caches and pending work by server/company/user. Switching users or companies cannot reveal another account's data. Offline clients cannot learn role revocation immediately: restrict offline access to the approved cached-data policy, refresh on reconnection and reauthorize every replay. Preserve rejected pending work for established recovery; never silently discard or replay under a different user.

Additive API changes must keep the currently supported mobile build working. Missing, malformed or unknown permission contracts must never grant newly introduced access. Test old-server/new-client behaviour explicitly, without inventing a fallback that opens all menus. An upgrade-required screen is acceptable for unsupported new navigation, with pending work retained.

### Mobile presentation

Use these nine mobile groups in this order: My Workspace; Field Operations; Customers & Territories; Sales & CRM; Secondary Sales; Marketing & Samples; Inventory & Purchasing; HR & Attendance; Reports. Settings & Administration remains web-only.

Provide an ordered Modules screen/drawer and reuse the current daily-work experience through shortcuts. Hide empty groups. Adapt wide tables to cards/detail screens, keep filters/pagination, and preserve data fields and business actions. Include loading, empty, validation, forbidden, offline and retry states. Do not force every user to land on Today's Tour if their permissions give them no field-work access.

## 4. GPT-6.1 medium / Cursor working method

Use GPT-6.1 with medium reasoning where available. This plan does not assume a particular Cursor subscription or guarantee model availability or token savings.

Open both repositories in one Cursor workspace. Keep existing AGENTS.md instructions. Use short, task-scoped Cursor rules if needed; do not paste this whole plan into an always-on rule. Cursor recommends focused, actionable, scoped rules: https://docs.cursor.com/context/rules-for-ai

Default to one agent and one task per chat. Target one endpoint/action or one screen workflow; normally 3-8 implementation/test files, excluding small state updates. This is a sizing guide, not a limit that excuses incomplete work. Split larger tasks at a clean contract boundary before coding. Backend and Flutter can be separate chats once the API contract is fixed.

Each chat reads applicable instructions, the compact state file, its task card and named source/test files. Search relevant symbols with rg; widen only when necessary. Do not reread both repositories, entire old chats, generated files, vendor, node_modules, build outputs or historical roadmap logs. Do not skip required framework documentation, permission checks or meaningful tests to save tokens.

Keep one canonical contract example and one parity inventory. Link to them rather than repeating schemas in every prompt. Record brief decisions and file paths, not a transcript of investigation. After a task, update the compact state and relevant inventory rows. Start the next chat from that handoff.

Run focused checks for a task; broader checks at integration milestones. Inspect failures before retrying. After two unsuccessful fixes of the same issue, write a short diagnostic (failing test, observed cause, attempted fixes, next hypothesis), narrow the task and continue deliberately; do not regenerate whole modules. Escalate architectural/security ambiguity for review instead of guessing permissions.

The initial discovery needs more context than later tasks. Measure actual usage over P00-P05 before choosing any numeric token budget; do not promise a percentage saving.

## 5. Ordered tasks and completion gates

These are the original task definitions, retained for traceability, not a fresh backlog. The working state records P00-P06 complete; do not restart them. Reconcile their evidence and remaining gaps through section 10. These are sequential dependencies, not week estimates. Each module wave must be broken into small task cards; never send an entire wave as one coding prompt.

| ID | Scope | Completion evidence |
| --- | --- | --- |
| P00 | Baseline: versions, both git states, existing conventions, available Boost tools/test runners, applicable instructions | Exact versions and commands; relevant source map; existing changes preserved; no application changes |
| P01-G01..G10 | Inventory one Admin group per task, including clusters/custom pages and actions | One row per resource/page for G01–G09; G10 is the recorded mobile exclusion; counts and gaps derived from source |
| P02 | Design the navigation/permission contract and compatibility rules | Exact response example, stable keys, policy mapping, refresh/error behaviour and test cases recorded in `docs/mobile-parity-contract.md` |
| P03-A | Implement shared navigation metadata adapter/registry and ordering | Ordered output and Admin parity checks; existing web navigation preserved |
| P03-B | Add authenticated bootstrap and authorization filtering | Allowed/denied/no-role/company/module-disabled tests; no direct role-name logic duplicated in Flutter |
| P04-A | Parse/bootstrap/cache mobile capabilities | Contract, malformed/unknown-version, session-switch and stale-data tests |
| P04-B | Build Modules navigation, destination registry and route guards | Correct order, hidden empty groups, forbidden deep-link handling, suitable home per user |
| P05-A | Connect one existing list/detail screen as a pilot, preferably Accounts after audit | Menu to API to native detail works with existing scopes and data |
| P05-B | Connect one pilot edit/action and denial path | Authorized save, denied direct API request, validation, role removal and forbidden record checks |
| P06 | Extract only proven shared UI/API conventions from the pilot | Reusable list/error/form pieces where repeated; no speculative framework |
| M01..M10 | Complete the ten module waves below using per-resource task cards | Every inventoried page/action has implementation and test evidence |
| Q01 | Cross-module security and workflow regression | Role changes, tenant isolation, record scopes and connected business flows pass |
| Q02 | Native-device and release-candidate acceptance | Android evidence; iOS native evidence if shipping iOS; realistic data/performance checks |
| Q03 | Stage coordinated backend/mobile rollout | Compatibility and rollback rehearsed; rollout is a separate deployment action |

P00 first uses existing source maps/checklists. P01 establishes the complete inventory, but only expands detailed cards for the next resource; avoid writing hundreds of speculative tasks upfront.

Inventory columns: ID; group/item order; menu label; web source/page; child/detail routes; actions; policy/gate; data scope; existing API; existing mobile destination; gap; dependency; offline policy; backend task; mobile task; automated evidence; device evidence; status.

Status values: Unassessed / Gap / In progress / Automated verified / Device verified / Accepted. Source presence is not evidence of implementation correctness. Existing features may move to verified through targeted tests without being rewritten.

## 6. Module waves

Default delivery order follows the Admin menu, with dependencies brought forward as needed. For example, complete shared account/item lookups before document creation. Only map existing supported features; exact page names come from P01.

| Wave | Group | Focus |
| --- | --- | --- |
| M01 | My Workspace | Role-appropriate home, quick links, personal tasks and existing workspace pages |
| M02 | Field Operations | Tour plans, visits, DCR, travel/expenses, reviews, planning modes and recovery; preserve existing offline behaviour |
| M03 | Customers & Territories | Customer/account masters, addresses, assignments, hierarchy and geography with scoped selectors |
| M04 | Sales & CRM | Leads, deals, quotes, orders, invoices and existing lifecycle actions using server calculations |
| M05 | Secondary Sales | Existing intake/OCR, review, corrections and reports; preserve audit history |
| M06 | Marketing & Samples | Campaign/promotion pages, e-detailing, SGIP and sample workflows with stock checks |
| M07 | Inventory & Purchasing | Existing purchasing/GRN/transfer/adjustment/issue pages; transactional posting and duplicate protection |
| M08 | HR & Attendance | Existing punch/leave reuse plus remaining employee, attendance and HR pages/approvals |
| M09 | Reports | All inventoried reports, filters, totals, drills, exports/downloads with identical data scopes |
| M10 | Settings & Administration | Excluded from mobile on 15 November 2026. Web Admin settings, roles and privileged actions stay on the web |

For each resource, use this repeatable sequence:
1. R-A: compare web behaviour with current API/mobile implementation; freeze fields/actions/permissions and examples.
2. R-B: implement only missing read/list/detail APIs and focused authorization tests.
3. R-C: implement or connect native list/detail/filter screens and widget/API tests.
4. R-D: implement one missing mutation/approval at a time with server validation and state checks; connect the mobile action in a separate task if large.
5. R-E: verify the workflow and role/data boundaries, update parity inventory and register the completed destination.

Skip already verified work. A read-only web page does not need invented CRUD. A screen that opens but lacks its supported approvals, exports or detail actions is not complete.

## 7. Acceptance and safety-critical checks

For each resource: permitted view/action succeeds; no-permission request fails; unauthorized record and relationship IDs fail; proper loading/validation/error states; pagination/search/filter match scope; changes appear in web and mobile; supported approval/state locks hold.

For shared authorization: Super Admin, ordinary user, read-only, no permissions, owner, manager, unrelated user, scope-bypass user, disabled module, revoked role and cross-company requests. Reuse actual configured roles/permissions instead of inventing names. Verify role assignment mutations cannot escalate the actor's own privileges without existing authorization.

For postings: one intended financial/stock effect under retry, interrupted response and concurrency; authoritative totals/taxes/UOM/rounding from ERP. Test transactions and rollback on failure. For offline: app kill/restart, account switch, stale permission and rejected replay preserve evidence and prevent duplicate submission.

Follow existing Pest guidance and tests; use MySQL it_erp_testing with RefreshDatabase and reporting helpers. Never create tenants in Pest (destructive lifecycle). Run one database-resetting test process at a time. Respect user-ID versus employee-ID rules and Visit/ShiftMaster observers from AGENTS.md. Do not use root-page success as inventory coverage.

Before PHP completion, run required Pint command: vendor/bin/pint --dirty --format agent (use the working Windows/PHP invocation). Use Boost to discover Artisan parameters and version-specific docs before implementation. If Boost is unavailable, record the limitation and use installed source/official docs without claiming Boost was used. Activate repository pest-testing/tailwindcss-development skills when their work applies.

For Flutter, format changed Dart files, run focused flutter tests and relevant analysis; run complete Flutter tests/analysis at shared-navigation and release milestones. Reuse tool/test_backend.ps1 if required by the local PHP setup, inspecting its options first. Keep command results and failures honest; pending device tests remain pending.

Release gate: every Admin inventory row accepted, or an explicit user-approved exception recorded; no hidden backlog counted as parity. No unresolved security/data-loss/posting defects. Use the existing full-application-mobile-testing-checklist.md rather than duplicating it. Confirm intended platforms; Android evidence cannot sign off iOS.

## 8. Task card and reusable Cursor prompt

Task card (roughly 150-300 words):
- ID and objective: one observable outcome.
- Dependencies and inventory rows.
- Read first: precise source/test paths and contract section.
- Change boundary: intended files/behaviour; preserve existing work.
- Acceptance: 3-6 observable checks, including denied access when relevant.
- Validation: exact focused commands after P00 establishes tooling.
- Handoff: evidence, open issues and next task.

Copy this prompt for each implementation chat:

> Use GPT-6.1 medium. Work on task [ID] only for Shatya Info ERP/mobile parity. Follow applicable repository instructions. Read docs/mobile-parity-state.md in it-erp, then the named task card and only its relevant sources/contracts. Reuse existing APIs, services, Flutter repositories and offline behaviour. Implement the task completely and run its focused checks. Do not broaden scope or add dependencies. Preserve unrelated changes. Update the task status and compact handoff with changed paths, exact validation results and the next task. Report briefly: outcome, checks, unresolved issues.

P00 is read-only baseline/discovery except planning artifacts. It is not permission to implement P03 or all modules.

## 9. Handoff and rollout

Keep docs/mobile-parity-state.md under about 600 words. Store one active task, completed task IDs, verified versions/commands, essential decisions, blockers and next task. Detailed inventory and contract live separately once P01/P02 produce them. Do not append full conversation logs. If one feature exposes uncertain policy, record that specific question and continue independent authorized work.

Backend releases precede mobile builds that require their endpoints. Keep a defined compatibility window, exercise old/new combinations and gate destination exposure on supported screens. Verify migrations in isolated tests/staging and plan company rollout separately. Rollback must preserve queued device work and posted business records. App signing, distribution and production changes require the corresponding authorized release task; this planning request does not execute them.

Estimate schedule only after P01 counts gaps and the pilot measures throughput. Track completed accepted resources/actions, API gaps closed, permission failures and actual model usage. Do not use number of generated files or tokens spent as completion metrics.

Supporting architecture reference: Flutter recommends clear UI/data responsibilities and repository/service separation; preserve the project's existing equivalent patterns rather than adopting new packages just to match an example: https://docs.flutter.dev/app-architecture/recommendations

## 10. Remaining delivery plan — 16 November 2026

### Target and current baseline

Deliver the existing Admin business modules in Flutter with the same group, item and nested menu order, filtered by the roles and permissions managed by Super Admin. Settings & Administration stays excluded, including for elevated mobile users. Company module settings, planning mode, record visibility and workflow state further restrict access; enabling a module does not grant a user permission.

Existing assets to extend:

- Shared backend menu registry and authenticated `/mobile-navigation`, native Modules navigation, route guards, user/company cache isolation and refresh behaviour.
- Sixteen registered menu entries mapped to fourteen screen keys. This is navigation coverage, not fourteen complete modules. Secondary Sales, Inventory, HR and Reports have no registered mobile entries yet.
- Field capture, tour planning, DCR/expenses, offline queues/recovery, account forms, leads/deals, follow-ups/activity/notes/items, RCPA, visit presentations and sales-performance views.
- The inventory's detailed action comparisons and the working state's completed cards. Historical task IDs remain valid even where their wave naming differs from the group sequence below.

Scope reconciliation: the working state records a prior user-approved web-only exception for Lead and Deal approval. Preserve that specific exception unless the user explicitly changes it; the latest request does not automatically reopen every previously accepted action exception. It does not exempt general My Approvals, Follow-up approvals or other business approvals. Record it visibly in acceptance totals. Settings & Administration is the excluded menu group. Operational pending-work/recovery screens already present in Flutter remain available under their existing permissions even where their web counterparts are grouped with administration.

### Delivery sequence and completion gates

Complete one resource workflow at a time within each wave. The order below is the default execution order. Bring forward a shared lookup or service only when needed by the current workflow; this never changes the final menu order.

| Order / ID | Work to deliver | Dependency and exit gate |
| --- | --- | --- |
| 0 / R00 (complete) | Reconcile current source, inventory, contract and handoff; record versions and existing changes. Keep completed work. Correct stale contract documentation for Leads/Deals and list the remaining action gaps. Audit nested HR/report pages, user-menu links, duplicate/unset sorts and conditional navigation. | Planning baseline ready when each in-scope destination/action has a status and owner task; documented exceptions are explicit. Do not repeat P00-P06 implementation. |
| 1 / R01 | Complete role-controlled access across web, API and Flutter. Align page gates, module checks, global capabilities and per-record actions; resolve authenticated-only workspace/report access and hard-coded Punch menu eligibility. | Assigned permissions control list, detail and actions; direct requests, selectors, files and exports enforce the same scope. No-role, read-only, revoked-role, disabled-module and cross-company cases pass. |
| 2 / R02 | Make registered navigation usable. Give Punch, Visits, DCR Expenses, E-detailing Library and SGIP appropriate standalone destinations. Add a contract for cluster/child order and safe client/server compatibility. | Each exposed item opens its own meaningful workflow without requiring an unavailable record ID. Unknown destinations stay inaccessible. New register actions are completed in the relevant module wave and remain tracked as gaps until then. |
| 3 / M01 | Complete My Workspace: Dashboard periods/charts/preferences and personal/team views; Punch parity; Today’s Visits and Assistant residual gaps; My Approvals and Approval Register. | Shared approval API uses the existing current-step/assignee service. Authorized reviewers can inspect and decide supported work; unauthorized, stale and repeated decisions fail safely. Excluded settings links are not exposed. |
| 4 / M02 | Complete Field Operations: Tour Plans, Flexible Planning, Visits, DCR, Travel Segments, DCR Expenses and Customer Coverage. Extend existing screens and APIs for permitted team/register workflows. | Owner/manager scopes agree across web/API; plan revisions, report windows, derived travel, approval locks, receipts and queued capture remain correct. Every applicable register/filter/action has evidence. |
| 5 / M03 | Complete Customers & Territories: Accounts, Contacts, Companies, Territories, Patches, HQ & Customer Assignments and Territory Sales Assignments. | Standalone CRUD and assignment actions match effective Admin behaviour. Related selectors and historical assignment dates are validated; contact-owned addresses work; changes do not broaden unrelated-user visibility. |
| 6 / M04 | Complete Sales & CRM: remaining Lead/Deal actions, global Follow-ups and Lead Activities, Quotes, Sales Orders, Sales Invoices, Prices & Discounts and Territory Sales Targets. | Supported conversion/document lifecycle, pricing/tax/UOM totals, details, pagination, permissions, approvals and exports work. Targets can be managed where authorized, beyond the existing achievement view. Preserve the recorded Lead/Deal approval exception. |
| 7 / M05 | Complete Secondary Sales: Sales Statements, Corrections and OCR Sources using existing APIs where sufficient. | Native template/source upload, paginated list/detail, mappings, discrepancies, assigned review and audited reversal/replacement work. Repeated uploads/reviews do not duplicate the approved ledger; OCR failures remain recoverable. |
| 8 / M06 | Complete Marketing & Samples: Campaigns, Promotional Schemes, standalone E-detailing Library, RCPA register, Sample Requests, Sample Issues and SGIP register. | Authorized library management/presentation history and sample request-to-issue-to-distribution workflows work; stock/quota, campaign/scheme dates and approval restrictions remain server-controlled. Existing offline presentation/sample/RCPA capture still passes. |
| 9 / M07 | Complete Inventory & Purchasing: Items, Stock on Hand, Purchase Orders, GRN, Delivery Challans, Transfers, Adjustments, Stock Ledger and Locations. | Native documents use existing inventory services. Retry/concurrency produces one posting; failed posting rolls back; posted-state restrictions and location/record permissions hold. Related purchasing/fulfilment actions match Admin. |
| 10 / M08 | Complete HR & Attendance: Employees, Positions, Org Units, attendance registers, the full visible Leave Management cluster and HR Change Requests. | Personal/team boundaries, employee/login identities, approvals, leave balances and attendance calculations agree with ERP. Existing personal punch/leave flows are retained and placed in the correct navigation. |
| 11 / M09 | Complete Reports: Overview, Account Reach & Frequency, DCR, Visit Coverage, Expenses, Doctor Sample Ledger, SGIP Compliance, Stock Movements, Lead/Deal Pipelines, Sales Documents, Overdue Follow-ups, Attendance, Punch vs Tour and Leave Ledger. | All supported filters, drill-downs and exports use scoped shared report queries. Web/mobile totals match for the same actor, data and filters; pagination does not change totals. |
| 12 / Q01-Q03 | Cross-module acceptance, native device/release checks, then coordinated backend/mobile rollout preparation. | Every in-scope inventory action is accepted or has a recorded approved exception; no unresolved access, data-loss or incorrect-posting defect. Deployment/publication follows a separately authorized release task. |

### R01 — permission work package

Read the registry/service, resource policies and page gates, API Form Requests/services, Flutter destination guards and the existing capability implementations in Leads/Deals before changing behaviour.

1. Build a per-page/action permission mapping using existing effective permissions. Where a page currently permits every authenticated user, define an explicit assignable capability through the existing authorization system. Seed/register any needed permission before enforcing it; migrate intended role assignments deliberately and verify existing users are not accidentally locked out. Do not invent role names or assign every new permission broadly.
2. Apply these decisions consistently to Admin and API. A menu-only change is insufficient. Review known DCR/travel/expense scope mismatches and optional-module direct-access gaps recorded in the inventory before exposing broader mobile registers.
3. Return global create/export capabilities and record-specific edit/delete/submit/review capabilities where needed. Follow existing Lead/Deal patterns; do not infer mutation rights from list access. Fix Account create/edit visibility first.
4. Route shortcuts and child entry points through equivalent checks. Refresh on existing login/resume/403 paths; ensure a removed permission closes protected routes after refresh. Preserve queued evidence and reauthorize replay after reconnection.
5. Verify Super Admin and administration privileges through the existing gates. `AccessAllRecords` bypasses record visibility only; it must not become blanket mutation permission or cross-company access.

Completion cases: Super Admin changes a role in ERP; the app refreshes to the correct menu/actions; an unauthorized direct API request still fails; a read-only user can inspect allowed records without editing; a user with no module permissions receives no business-module access; employee linkage alone does not grant privileged HR actions. Authentication, logout, denied-access and pending-work recovery remain usable as appropriate.

### R02 — menu and destination work package

The existing schema maps Punch and Today’s Visits to one screen, and DCR and DCR Expenses to another. Visits, E-detailing and SGIP currently require a record ID that Modules does not supply. Remove these navigation gaps through dedicated screens or register entry points that reuse the existing detail/form widgets.

- Keep canonical group and item labels/order on the backend. Include nested parent relationships for HR and Reports. Resolve equal/unset sorts using a shared deterministic rule matching the effective Admin menu.
- Keep separate stable keys for standalone registers and record workspaces. Register routes only once their read APIs, authorization and loading/empty/error states work. An empty list must be a real API result, not a placeholder claiming delivery.
- Evolve the contract without silently changing the meaning of schema-1 keys for older installed clients. Specify supported-build negotiation or a versioned contract, deploy compatible backend support first, and test old/new combinations.
- Add tests that compare the complete eligible Admin inventory to implemented mobile destinations plus explicitly tracked gaps. The existing test of the sixteen registered entries alone cannot prove full parity. The release gate permits no unexplained missing destination.

### Smaller gaps to carry into their resource cards

These are existing unfinished work, not reasons to restart delivered screens:

- **Leads:** page-aware Notes with retry/refresh and the existing composer/permission guard; supported delete/restore/bulk actions after policy review; contact-owned address selection; retain completed Activity, Follow-up, Notes creation and Items work.
- **Follow-ups:** read-only details for non-editable records; standalone cross-lead register; supported delete and approval/bulk actions.
- **Lead Activities:** standalone register and supported writes; retain the delivered lead-scoped pagination.
- **Accounts and other masters:** action capability visibility, complete supported relation/record actions, scoped selectors and real pagination.
- **Field management:** team/HQ/representative filters, DCR and expense registers, manual/derived travel boundaries, MTP and flexible-plan manager parity.
- **Targets and assignments:** create/edit/lifecycle capabilities and effective-dated assignment forms; do not count the existing read-only performance screen as management parity.
- **HR inventory:** re-check visibility of leave instances, balances, cancellations, adjustments, encashments, lapse records and ledger against the current clusters. R00 lists the visible children and the three sidebar-hidden routes separately. M08 owns supported operational actions for hidden resources; preserve that distinction when adding native navigation.
- **Imports, exports and attachments:** implement the business action when it belongs to an included module. A link that merely launches an excluded Settings page does not automatically bring the whole Settings module into scope; track that boundary explicitly.

### API and mobile delivery per resource

Each resource card names the existing web source, policy, service, endpoints, Flutter files and tests. Use this completion sequence:

1. Record supported fields, lifecycle actions, relations, filters, pagination, scope, API reuse/gaps and offline boundary.
2. Add missing read endpoints or extend them compatibly using scoped Eloquent queries/resources and the existing `/api/v1` convention. Verify unauthorized and unrelated-record reads.
3. Implement native list/detail, filters, search, pagination and recovery states; connect the menu destination.
4. Add supported mutations one at a time using Form Requests, policies and shared domain services. Return authoritative validation, calculations and capabilities; implement the matching native form/action.
5. Verify successful and denied paths, state locks, related IDs, retries and web/mobile consistency. Update the inventory row with exact evidence and remaining gaps.

Preserve established offline field capture. New management approvals, inventory postings, financial actions and master changes are online initially. Cache new read views only with an explicit user/company scope and freshness policy. Do not add offline mutation support implicitly. Preserve unsynced work through role changes, app upgrades, failed uploads and rollout rollback.

### Acceptance, operating dependencies and release

Use the existing full-application testing checklist and inventory; do not create a second unchecked checklist for every feature. Apply GEN/AUTH cases to each delivered resource. Run focused backend/Flutter tests per card, then the complete Flutter suite and relevant backend integration packs at the shared-access and final milestones. Run required PHP/Dart formatting only for implementation work; this planning update does not need application tests or formatting.

Integrated acceptance must cover:

- Tour plan → visit → DCR → expenses/travel/SGIP → assigned approval; offline restart/replay preserves original evidence and prevents duplicate effects.
- Lead → Deal → Quote → Order → Invoice, including customer pricing and supported fulfilment transitions.
- Purchase Order → GRN → stock → transfer/adjustment/sample issue → ledger reconciliation.
- Secondary statement/OCR → reconciliation → assigned review → correction → target achievement without double-counting.
- Punch → daily attendance → leave/HR changes → balances and reports using the correct user/employee identifiers.
- Super Admin role changes, read-only/no-permission users, owner/manager/unrelated users, module disablement, session/tenant switches and retained offline queues.

Carry forward existing acceptance dependencies: business-approved coverage rules and master data; actual clinic GPS checks; stockist/OCR examples and extraction accuracy; reviewers and reconciled target examples; two-device pending work; prolonged idle/battery management; reachable-backend network handover; production-like HTTP load and longer mixed stock/order/expense runs. These require their own evidence and are not satisfied by the 300 passing local tests.

For native release readiness, verify token/local-data storage protection, release API configuration, signed packages, upgrade with queued work and recovery/rollback. Android is the documented target. Confirm the intended iOS release scope before scheduling/signing off iOS native testing; do not infer iOS readiness from shared Dart tests.

Progress records distinguish **Implemented**, **Automated verified**, **Device verified** and **Accepted** according to the existing inventory statuses. Track accepted resource/actions and remaining gaps; avoid a percentage based only on menu entries. Calendar estimates follow the first completed remaining resource and confirmed delivery capacity, rather than reusing the older field-only 18–22 week estimate for this larger scope.

### First implementation handoff

R00 is complete. See the [navigation audit](mobile-parity-inventory.md#r00-navigation-audit) and [permission map](mobile-parity-inventory.md#r00-permission-map) for the baseline. The [R01 audit](mobile-parity-inventory.md#r01-selector-and-action-audit) records the permission and selector checks for existing workflows. The [handoff](mobile-parity-state.md) records regression results and separate live acceptance tracks. R02 owns standalone destinations.

This request authorizes preparing the remaining-work plan. No application code, permissions, database records, deployments or published builds are changed by this planning update.
