# Shatya Info: full ERP mobile parity plan

Prepared: 15 November 2026. Status: planning only; application implementation has not started under this plan.

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

This inspection is not a complete page/API/permission audit. No resource count, delivery date, passing test claim or percentage completion is inferred from file presence.

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

These are sequential dependencies, not week estimates. P01 and each module wave must be broken into small task cards; never send an entire wave as one coding prompt.

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
