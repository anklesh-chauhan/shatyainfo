# New company onboarding and workspace roadmap

Updated: 9 November 2026

Status: Core launch journey implemented; remaining roadmap work and acceptance are listed below.

## Implementation progress

The first implementation adds workflow selection to Company Setup, saved policy proposals, per-employee readiness, selected-team launch records, transactional policy/approval application, permission-aware dashboard actions and mobile company/HQ guidance. Direct/Flexible mobile users open assigned customers directly and see workflow-appropriate planning shortcuts.

Readiness checks cover employee activation, linked login credentials, visit/customer permissions, customer visibility, active HQ, assigned customers, required territory/patch setup and DCR approval resolution. Failed launch validation rolls back policy and approval changes. Repeated launches preserve the original launch record. Selected-team launch records readiness and activation milestones; it does not introduce a separate access-control gate. Planning and approval activation remains company-wide.

| Phase | Current delivery | Remaining |
| --- | --- | --- |
| 1 | Shared readiness service, launch history, issue-specific action links and local concurrent launch acceptance | Production transport/load acceptance remains a release gate |
| 2 | Workflow cards, preview, company timezone selection, private resumable drafts and launch review | Observed usability acceptance |
| 3 | Import correction checks, manager guidance and integrated 26-MR import-to-login/HQ/customer/DCR acceptance | Observed administrator import usability |
| 4 | Selected-team launch, workflow-aware planning navigation and dashboard switching between personal work and team management | Optional-module navigation once company module settings are available in Phase 6 |
| 5 | Guidance, checklist, manager contact, Android restart and ten-minute controlled idle persistence acceptance | Observed first-day device journey and natural overnight idle acceptance |
| 6 | Activation, prerequisite checks, mobile controls and automated module operation/approval acceptance | Observed module/device acceptance with company-specific employee eligibility configuration |
| 7 | Three-workflow journeys, mobile regressions, Android restart checks and bounded separate-database concurrency acceptance | Observed field usability, longer idle/load and production transport acceptance |

Verification during this increment: one backend run passed 68 tests / 297 assertions; a later launch/enterprise approval run passed 40 tests / 178 assertions. Suites overlap and counts are not additive. Dart analysis of changed mobile sources and new model tests passed. Flutter test execution requires SDK-cache access outside the workspace; automatic approval review could not complete because the workspace was out of credits. No company deployment or app publication was performed.

Final targeted verification: all 13 launch tests passed. A stale setup-reminder assertion expecting a navigation group was updated to the existing Organization and Company cluster; all 6 reminder tests then passed with 25 assertions. Laravel Pint and whitespace checks passed.

### Readiness actions and launch history increment

Company Setup now shows a relevant action beside each employee readiness issue. Employee/login/territory issues link to employee setup; access issues link to user management; patches and approvals link to their existing configuration screens. HQ/customer actions open the assignment page with the affected representative selected. Destination permissions still apply, and a query parameter only selects a form record; it never saves assignments.

Successful launches append a batch to company setup metadata under the existing organisation transaction lock. Each batch records the actor user ID, timestamp, policy revision/version, workflow and newly launched members with their name/HQ snapshots. Repeated launches add no duplicate batch; failed launches leave no history. History is available only to company setup administrators and is shown newest first. This increment adds no schema migration and does not backfill historical launch records.

Verification: the launch and field-team coverage suites passed 33 tests / 154 assertions. Laravel Pint and whitespace checks passed.

### Concurrent company launch acceptance

An isolated multiprocess test now exercises four simultaneous duplicate launches, followed by four overlapping team launches including a team with an unready member. All workers use the Admin panel's `web` permission guard within an in-memory tenant context; no tenant provisioning or real tenant database lifecycle runs.

Verification on 9 November 2026: 1 test / 21 assertions passed against `it_erp_testing`. Eight service calls reached a measured peak overlap of four: seven succeeded and one unready-team call was rejected. The database retained exactly one policy activation, three unique launched MRs, unchanged original history and no partial launch for the rejected team. Initial fixture failures were resolved by matching the Admin permission guard; no application change was required for this acceptance increment. Laravel Pint and whitespace checks passed.

This verifies local service/database concurrency, not HTTP transport, production capacity or tenant isolation across multiple databases. Run with `C:/Herd/grahak_field/tool/test_backend.ps1 -Concurrency -TestPaths tests/Feature/CompanyLaunchConcurrencyTest.php`; do not run competing database suites.

### Company timezone and resumable drafts

Company Setup now proposes Asia/Kolkata when no company timezone exists, accepts valid timezone identifiers and saves the selection in the existing organization profile field. This increment does not change operational scheduling, timestamp storage or field-day boundaries, which continue using the existing application timezone.

Company text fields save private drafts when the administrator leaves a field; workflow and approval selections also save drafts. Save and continue later works before company details are complete. Drafts use the existing encrypted settings store, scoped by tenant and administrator, and survive page reloads and signing in again. Saving a draft does not change confirmed organization details, activate a policy or apply approvals. The page displays saved/unsaved feedback. Back and step transitions retain progress; resumed final reviews return to approval review, and changed workflow proposals require a new review. A successful launch clears the administrator's draft.

Temporary logo uploads and import previews are not included in drafts; the form explains that uploads must be saved separately. Selected launch checkboxes are reselected during review. Observed usability acceptance remains pending.

Verification: CompanyLaunchTest and CompanySetupReminderTest passed 27 tests / 123 assertions, including timezone validation, incomplete draft restoration, per-login draft separation, deferred setup before an organization exists and authorization. Laravel Pint and whitespace checks passed. No migration or dependency change was needed.

### Import correction and manager setup

Employee preview checks the full file for missing employee codes, conflicting personal details on repeated employee codes, and unknown reporting position or expected manager codes. It shows the source row for each issue. References can come from the current company or from later rows in the same file. Keep employee codes stable when correcting a file. A repeated employee code can represent multiple assignments when the personal details agree. Keep customer external references stable on corrected uploads.

The embedded employee import now explains how an MR's primary position identifies their manager, how to check that relationship with an expected manager code, and how to verify login access and configure DCR approvals. Import confirmation continues to validate the resolved hierarchy and permissions.

The new regression cases exercised correction and rerun for 100 MRs plus a manager and a separate batch of 100 customers. The broader import run reported 89 passes and 7 failures, including import page, workbook download and setup step expectations. Pint and whitespace checks passed. Full import suite acceptance remains open.

Follow-up verification on 9 November 2026 resolved all seven reported failures. Employee workbook downloads now use the existing Livewire-compatible download response. Regression tests exercise preview before employee confirmation, the dedicated customer import entry point, current import page content and explicitly permitted field travel access. The four import suites passed 96 tests and 556 assertions. Pint and whitespace checks passed. The import regression gate is closed. Fresh-company device and business acceptance remain scheduled under Phase 7.

### Role and workflow navigation

The Admin dashboard shows personal visit and reporting shortcuts or team actions based on access. Users who have both sets of actions can switch between Personal work and Team management. The team view includes approvals and, for users with field team management access, team coverage. Users who only have personal actions see those directly.

Flexible Planning appears in the sidebar when the active workflow enables it. Direct DCR and Flexible users do not see a Tour Plans shortcut for personal work. The Tour Plans sidebar link remains available to authorised field team managers for existing plans, and in Formal MTP and Legacy workflows for authorised users. Resource permissions and record scopes continue to govern access. Optional module visibility depends on the activation settings planned in Phase 6.

Company launch and field coverage regression suites passed 38 tests and 200 assertions, including the Direct DCR and Flexible workspace switch. Pint and whitespace checks passed. The next implementation is the mobile first-day checklist.

### Mobile first-day checklist

The mobile home screen now shows a five-task checklist in company guidance, with links to customers, offline preparation and pending work. The MR confirms customer access, offline preparation, the first visit, DCR submission and review where applicable. Progress is saved on the device under the existing account owner key and restored after reopening. Task ticks represent the MR's confirmation. Pending work provides sync and submission status.

Verification on 9 November 2026 passed three Flutter tests covering company guidance and checklist restoration with separate accounts. Dart analysis passed for the new widget, home screen and test. Physical device acceptance and manager contact resolution remain pending. Next implementation is additional-module activation and prerequisites.

### Optional-module activation

Admin Settings → Onboarding & Support → Optional Modules now prepares samples/inventory, expenses, attendance/leave and sales CRM separately. Each card shows saved company information plus its initial master-data prerequisite. These are an item, an active expense type, a shift, or a customer account respectively. An administrator confirms review of employee permissions and the module's approval routes or no-approval choice using the existing configuration screens.

Activation records the actor user ID and time in organization metadata under a transaction lock. Repeated activation retains the first record. Operational resource menus for these modules follow activation for companies with saved onboarding details. Configuration resources stay available for preparation. Existing resource policies and services enforce permissions and operations. Activation is a workspace configuration milestone. It is not a backend prohibition on existing authorised operations. This increment provides activation only, with no deactivation flow.

The initial increment covered master data and an administrator's explicit approval/permission review. The follow-up below expands those checks and connects mobile controls.

The follow-up adds active locations for inventory, currently effective rules linked to active expense types, active leave rules, and an item catalog for CRM documents. Activation flags are returned in the user API and parsed from the cached mobile profile. Attendance shortcuts and punch controls, offline expense shortcuts, new DCR expenses, sample capture and CRM creation controls now follow those flags. Pending work remains accessible. Existing cached profiles without the versioned flags use the previous UI behavior until refreshed. Changes become visible after the user profile refreshes online.

These checks establish company configuration readiness. Per-employee expense eligibility, shift/leave assignments, allocated stock and document-specific requirements continue to be checked by the existing operation services. Physical-device acceptance and broader module approval routing audit remain pending.

Follow-up verification passed four backend tests with 26 assertions, including future/expired expense rules and published activation flags. Four Flutter tests passed for cached module flags, company guidance and checklist persistence. Dart analysis passed for every changed mobile source and the new test. Pint and whitespace checks passed.

### Fresh-company reporting-day acceptance

FreshCompanyJourneyTest starts with no organization and exercises company information saving, workflow selection, selected-HQ launch and MR login guidance for Direct DCR, Flexible and Formal MTP. It leaves an incomplete second team in preparation, repeats launch and visit opening, checks in and out through the API, submits the first DCR and verifies that another MR cannot read it.

The journey uses isolated test fixtures for employees, permissions, geography and assigned customers. Setup authorization is substituted to avoid the tenant provisioning lifecycle. Formal MTP uses an approved plan fixture. DCR approval is explicitly disabled through onboarding for the journey. Existing enterprise approval tests run alongside it to cover sequential review and delegation. This evidence covers the service/API journey, with Admin usability, imports-to-login integration, physical devices and multiple tenant database isolation still requiring acceptance.

The combined run passed 31 tests and 227 assertions. Counts overlap earlier regression evidence.

Final login-inclusive verification passed three workflow journey tests with 57 assertions. Pint and whitespace checks passed.

### Complete mobile regression and Android build

On 9 November 2026 the complete Flutter suite passed 192 tests. Dart analysis of lib and test completed with no issues. A debug Android APK compiled successfully at C:/Herd/grahak_field/build/app/outputs/flutter-apk/app-debug.apk. Logs are saved under build/onboarding-flutter-regression.txt, build/onboarding-dart-analysis.txt and build/onboarding-android-build.txt.

The first Gradle attempt failed while establishing a Java loopback connection. Retrying with the existing repository workaround for java.io.tmpdir and jdk.net.unixdomain.tmpdir, both pointing to build/java-tmp, succeeded. The workaround was scoped to the build process environment.

ADB reported no connected Android devices. Physical device, long-idle and observed administrator/manager/MR acceptance remain open, along with production transport/load and multiple-database tenant isolation checks. The APK is a debug build for acceptance testing.

### Reporting manager contact

Company guidance now uses EmployeeReportingService to resolve the single active occupant of the MR's primary reporting position. It returns that manager's name, employee email and mobile number. If the hierarchy is vacant, ambiguous or unresolved, or the resolved manager is marked deleted, guidance uses the organization contact instead. The mobile company card shows the contact role and selectable contact details. These values are read from the same cached user profile used offline and update with an online profile refresh.

The regression covers a resolved manager, multiple occupants, an inactive manager and the administrator fallback. Mobile parsing preserves the additive contact fields and handles older cached profiles.

Verification passed 23 backend launch tests with 117 assertions, three Flutter contact/checklist tests and Dart analysis of the changed mobile sources. Pint and whitespace checks passed. Physical-device acceptance remains open.

### Android onboarding restart acceptance

On 9 November 2026 an authorised vivo I2011 was connected. The dedicated tool/device_onboarding_harness.dart target used production FileOfflineStore, OfflineSession, FieldUser parsing and FirstDayChecklist with isolated synthetic data. The device-test package com.shatyainfo.field.devicetest was verified from APK metadata before installation. The existing test APK was backed up and restored after the check.

The harness seeded a synthetic cached profile and three completed checklist tasks. After force-stop and relaunch, it restored all three tasks, the manager identity and module flags, while another account's checklist key remained empty. The actual checklist rendered with 3/5 tasks selected and was visually inspected on the device. This proves Android file persistence and process restart recovery for seeded onboarding state. It does not exercise checkbox taps, backend login/replay, GPS/camera, radio loss, reboot or longer idle conditions in this increment.

Evidence is stored in the Flutter project's build/onboarding-device-before-restart.json, build/onboarding-device-after-restart.json and build/onboarding-device-acceptance.png. The post-restart result reports passed=true, run=2 and restored_after_restart=true. Harness static analysis passed. The normal app package and its data were not replaced. No backend requests or business activity were generated by this harness.

After acceptance, the previous device-test APK was reinstalled successfully. The standard lib/main.dart debug APK was rebuilt successfully and its com.shatyainfo.field package ID verified.

### Tenant routing and setup draft isolation

The tenant routing, optional-module and new OnboardingTenantContextTest suites passed 20 tests and 90 assertions. The new regression switches between two in-memory company contexts with the same administrator and confirms separate draft reads, writes and deletion. The fixture uses the isolated it_erp_testing schema with tenant lifecycle tasks disabled. No tenant provisioning lifecycle executes in this new regression.

Existing routing tests verify company selection by domain, host precedence over a conflicting header, rejection of unknown or unavailable companies, landlord route protection and resetting the default database connection after requests. Their tenant registry fixtures bypass model events. Provisioning failure checks mock database operations.

This closes the routing/context and onboarding draft-namespacing checks. Both company contexts in the new regression use the same test database. Actual separate-database data isolation, cross-company concurrency, observed first-day usability and production transport/load acceptance remain open. The run is recorded in storage/logs/onboarding-isolation-run.txt. Pint and whitespace checks passed.

### Separate-database and cross-company concurrency

SeparateCompanyConcurrencyTest creates two randomly named MySQL schemas under the guarded it_erp_testing_iso_ prefix. It copies table definitions, indexes and foreign keys from the freshly migrated isolated test schema, then seeds synthetic organization and administrator fixtures. User IDs overlap deliberately. Tenant provisioning events never execute. Cleanup drops only schemas whose CREATE DATABASE succeeded in the current run, after the workers have stopped.

Four independent PHP processes start at a shared barrier. Each switches between both companies ten times, using the actual database-switch and permission-cache tasks. Every switch verifies the resolved database, setup draft, date format, optional-module flags and launch guidance before saving a draft and incrementing organization metadata under a row lock. Workers verify connection reset after leaving each company. The parent reconciles 40 organization updates per company, four users in each schema and no organization or user writes in the central test schema. All four worker execution intervals overlap.

The first attempt exposed retained authentication in fixture setup before the second schema's users existed. Its foreign-key constraints rejected the insert. Clearing the fixture authentication guards between companies resolved it. The temporary schemas were cleaned up on that failed run as well.

This establishes bounded service/database isolation with real separate schemas. It does not measure production transport capacity, cross-company visit/order/sample workloads, dedicated-user SQL grants or long-running worker memory behavior. Evidence is recorded in storage/logs/separate-company-run.txt. Run with tool/test_backend.ps1 -Concurrency -TestPaths tests/Feature/SeparateCompanyConcurrencyTest.php from the Flutter project. Avoid competing database suites.

Final verification passed both separate-company and company-launch concurrency tests with 37 assertions in 308.34 seconds, including schema preparation and cleanup. The separate-company workload reconciled 80 organization updates across two databases. The shared worker regression retained one policy activation and three unique launched MRs across overlapping requests. Pint, worker PHP syntax and whitespace checks passed.

### Optional-module operational and approval acceptance

The module activation, operational approval, HR approval, inventory posting, seeded expense calculation, SGIP compliance/API, private expense receipt/API and quick sales-document suites passed 53 tests with 240 assertions. They cover approval outcome handling and edit locks, sequential HR review, employee target permissions, insufficient stock and idempotent posting, sample quantity limits, owned/stale sample updates, expense validation and private receipts, and customer-prefilled quote/order creation.

OptionalModuleApprovalRoutingTest adds a four-module routing matrix for InventoryAdjustment, SalesDcr, LeaveApplication and SalesOrder. Each requires an eligible named approver when approvals are enabled, rejects a missing route or requester self-approval, and accepts the explicitly disabled-approval path. Route auditing leaves no approval records behind. All four cases passed with 16 assertions.

Evidence is recorded in storage/logs/optional-module-acceptance-run.txt and storage/logs/optional-module-routing-run.txt. Pint and whitespace checks passed. This closes the automated routing audit for those representative module types. Observed administrator/manager/MR use, company-specific expense/shift/stock eligibility, broader document types and physical module capture/replay remain business/device acceptance work.

### Imported team to first authenticated reporting day

ImportedCompanyFirstLoginTest passed one integrated journey with 19 assertions. It starts with a company administrator, saves company information, imports 26 medical representatives plus their manager through ResourceCsvImporter, and repeats that import without adding duplicate logins. The manager appears after the MRs in the source file. The account import service then creates 26 customers owned by the imported MR logins.

FieldTeamOnboardingService assigns an active HQ and call targets to 13 MRs. Readiness resolves those 13 as eligible, launch activates that team, and the remaining representatives stay in preparation. The imported first MR resolves the imported reporting manager, signs in using the imported password, authenticates subsequent API requests using the returned token, completes a Direct DCR visit and submits the resulting daily report successfully.

The test uses the normal import, assignment, login and reporting services with isolated synthetic records. Setup authorization is substituted to avoid tenant provisioning; the fixture administrator has explicit account import/update permissions. This integrated import journey covers Direct DCR with approvals explicitly disabled. The three-workflow and enterprise approval matrices remain separate evidence. The log is storage/logs/imported-company-first-login-run.txt. Pint and whitespace checks passed. Observed administrator usability and physical first-day field acceptance remain open.

### Ten-minute Android controlled idle recovery

On 9 November 2026 the connected vivo I2011 ran the isolated onboarding harness for a controlled deep-idle interval. Android reported IDLE at entry and every minute through 601.6 seconds. A device-state snapshot confirmed mForceIdle=true, mScreenOn=false and mCharging=false during the test. The harness used synthetic cached user guidance and the previously seeded checklist in its separate test package.

After the interval, forced idle and battery simulation were reset and the harness was force-stopped and relaunched. Its fourth launch restored all three checklist selections, manager identity and module flags, while the other account's checklist remained empty. The result reported passed=true. Cleanup verified mForceIdle=false, mCharging=true and deep/light ACTIVE, then successfully restored the previous device-test APK.

Evidence is stored in the Flutter project's build/onboarding-idle-before.json, build/onboarding-idle-observations.json, build/onboarding-idle-device-state.txt, build/onboarding-idle-after.json and build/onboarding-idle-restored.txt. This extends the seeded onboarding persistence check through ten minutes of forced deep idle. Natural overnight idle, vendor-specific scheduling, normal-app background synchronization and a real first-day offline visit/replay journey remain separate acceptance tasks. No backend activity was generated in this check.

### Observed usability session — awaiting participants

This is the next acceptance gate. An administrator, manager and MR should attempt the following tasks in a synthetic test company using the normal Admin workspace and mobile application. The observer reads the task goal, lets the participant choose the route and records any assistance. Use a separate prepared test company for each workflow so existing captured work can finish under its selected policy.

| Role | Task goal | Evidence of completion |
| --- | --- | --- |
| Administrator | Begin company setup, confirm company identity and save an incomplete draft | After signing in again, previously entered details are restored |
| Administrator | Choose the intended planning workflow and explain its effect on the MR | Explanation matches Direct DCR, optional Flexible intentions or required approved MTP |
| Administrator | Import the team and customers, correct one invalid source row and repeat the corrected import | The participant understands row errors, matching identifiers and login readiness |
| Administrator | Prepare one HQ while another HQ has an unresolved prerequisite | Eligible MRs launch, and the participant can identify who remains in preparation and why |
| Administrator | Enable one optional module | The participant understands the prerequisites, approval choice and employee permissions |
| Manager | Find the launched team's reporting status and a pending review | The participant identifies the correct team and the report awaiting a decision |
| Manager | Review a submitted DCR where approval is required | The correct next stage or final result appears, and the MR can identify it |
| Manager | Identify an MR with missing access or a coverage gap | The participant understands the issue and can identify the responsible correction |
| Combined-role user | Move between personal work and team management | The participant identifies which work they are viewing |
| MR | Sign in and identify company, HQ, reporting manager and the next task | The participant identifies the correct workspace and contact without coaching |
| MR | Prepare for offline work and record a permitted visit while disconnected | The participant recognises saved-on-device and waiting-to-sync states |
| MR | Reconnect, reconcile pending work and submit the first DCR | One completed visit and one submitted DCR persist, with the expected approval state |
| MR | Explain the first-day checklist and any incomplete task | The participant understands which tasks they confirmed and which operational status to check |

Run the MR tasks for Direct DCR, Flexible and Formal MTP. For Formal MTP include both an unapproved plan and an approved plan. For Flexible include a prepared intention and a permitted unplanned call. Where approvals are disabled, ask the participant to explain the completed reporting status.

Record these fields for each attempt. Participant aliases are sufficient.

| Participant/role | Workflow | Task | Started | Finished | Result | Assistance or confusion | Issue and retest |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Awaiting session | — | — | — | — | Not run | — | — |

Results are Unassisted, Assisted or Blocked. Capture the participant's wording and the screen where confusion occurred. Fix blocking issues and repeat the affected task before recording acceptance. Record elapsed time without assuming a pass threshold. Evidence should identify the test company, role, app build and final record IDs. Screen captures should use the synthetic records from the session.

Session completion requires each role to finish its applicable tasks unassisted after any necessary fixes, with the first-day reporting outcome reconciled in Admin and mobile. Automated test results and the seeded device harness do not substitute for this observed session. Status remains awaiting participants.

Verification passed 24 module/launch tests with 121 assertions. The final targeted settings-page and navigation run passed three tests with 21 assertions, covering missing configuration, required review, authorization, repeated activation and resource menu visibility. Counts overlap. Pint and whitespace checks passed.

Build a guided launch experience for a new Indian pharma company, prioritising 26–100 medical representatives (MRs) while supporting larger organisations. A company administrator should be able to prepare one HQ, launch its team and see an MR complete the first daily call report (DCR) without developer assistance.

## Scope and decisions

- Treat the application as a new, unused product. No legacy customer migration, historical backfill or old onboarding compatibility project is required.
- Reuse the implemented Direct DCR, Flexible planning, Formal MTP, approvals, customer assignment, coverage and offline replay capabilities.
- The existing Admin panel is the company workspace. Extend its setup flow rather than introduce another company panel.
- Company setup and an individual employee's first login are separate journeys.
- Team size helps organise setup; it never selects the planning workflow automatically.
- Keep planning requirements independent from DCR approval requirements.
- Core SFA is the initial workspace. Additional modules are available through explicit configuration.
- Setup can be saved and resumed. Administrators can explore before launch, with readiness clearly shown.
- Launch can cover a selected HQ or team. Other teams can remain in preparation.
- This roadmap does not authorise clearing databases or removing existing enterprise features.

## Target company journey

Company creation → preparation status → company details → working method → team and HQs → customers and assignments → reporting rules → readiness review → launch selected team.

| Setup step | Required outcome | Optional or conditional work |
| --- | --- | --- |
| Company | Recognisable company name and contact details; confirmed timezone and date format | Logo and additional legal details; propose Asia/Kolkata for Indian companies |
| Working method | Explicitly select and preview a planning policy | Advanced policy options |
| Team and HQs | Add MRs and managers, link login identities, assign HQs and reporting relationships | Divisions and deeper organisational hierarchy |
| Customers | Import or add customers and assign them to the selected MRs | Broader customer imports outside the launch team |
| Reporting rules | Confirm DCR approval route or explicit no-approval choice; review capture rules and coverage frequency settings | Module-specific approval overrides |
| Review and launch | Show blockers, warnings, selected team and effective policy; activate through existing services | Launch remaining teams later |

Detailed territories, patches and fare routes must be requested only where the chosen workflow or enabled module needs them. Formal MTP must expose its planning prerequisites. Direct/Flexible setup must not inherit unnecessary Formal MTP prerequisites.

## Workflow selection

Ask: “How does your field team plan doctor visits?”

| Client-facing choice | Explanation | Internal workflow |
| --- | --- | --- |
| Report visits directly | MRs report customer visits without preparing a plan first. | Direct DCR |
| Plan when useful | MRs can prepare daily or weekly intentions and report unplanned visits. | Flexible planning |
| Require monthly plans | MRs work through structured monthly tour planning and configured approvals. | Formal MTP |

Do not silently activate a default. Present the effect on the MR's home screen, planning requirements, DCR approvals and activation date before confirmation. Use the existing policy revision and activation mechanisms. A future policy must not make a team appear ready under rules that are not active yet.

## Delivery phases

### Phase 1 Establish setup and readiness rules

Create one shared definition of company setup progress, per-MR readiness and team launch eligibility. Separate provisioning, setup saved, ready to launch and launched states. Track the selected launch team independently from company-wide policy activation.

- Derive readiness from current records, permissions and effective settings, rather than completed wizard steps alone.
- Return actionable blockers with an affected person, reason and destination to resolve the issue.
- Distinguish launch blockers from optional improvements and first-day tasks.
- Keep readiness queries scoped to the current company and authorised team.
- Model partial launch explicitly; a company-wide completed flag cannot represent it.
- Record who launched which team, when, and under which policy revision.

Acceptance: a company with an employee but no usable login/customer access cannot be labelled field-ready. One incomplete HQ does not block an otherwise eligible selected HQ. Repeated launch requests cannot duplicate activation effects.

### Phase 2 Build the guided Admin setup

Extend the current Company Setup page and services with the target journey above.

- Show one primary next action per step, with Back and Save and continue later.
- Prefill previously saved registration details.
- Add workflow cards and a plain-language policy preview.
- Embed or link existing HQ/customer assignment tools with a reliable return to setup.
- Hide irrelevant configuration fields according to the selected workflow.
- Show real provisioning progress and a recoverable failure state before setup becomes available.
- Preserve saved input after refresh, logout or interrupted setup.

Acceptance: a new administrator can complete and resume each of the three workflow paths without developer assistance or an unexplained detour into unrelated master screens.

### Phase 3 Make team and customer preparation reliable

Reuse existing imports and assignment services; close gaps needed for a guided launch.

- Provide templates with examples for employees, HQs and customers.
- Preview imports before committing and show row-specific correction messages.
- Detect ambiguous identities, duplicates, missing HQs and unresolved manager references.
- Show employee creation, login linkage and access readiness as separate outcomes.
- Support assigning customers to MRs and reviewing frequency settings in the setup journey.
- Make rerunning a corrected import safe under a documented duplicate-matching rule.
- Preserve the domain distinction between users.id, employees.id and HR employee codes.

Acceptance: an administrator can prepare a 26–100 MR company through imports, identify every unresolved row and confirm exactly which MRs can access their assigned customers. Imports cannot assign records across companies.

### Phase 4 Add launch review and role-specific workspaces

Present a final summary of workflow, approval choices, selected HQs, ready MRs and unresolved issues. Recheck readiness on the server when launching.

| Role | Initial home | Primary action |
| --- | --- | --- |
| Administrator before launch | Setup progress and readiness issues | Continue setup |
| Administrator after launch | Team activation, activity and operational exceptions | Resolve issues |
| Manager | Team activity, pending approvals, coverage gaps and recovery requests | Review pending work |
| Direct MR | Today, assigned customers and current DCR | Start visit |
| Flexible MR | Today's intentions, assigned customers and current DCR | Start visit, with Plan visits available |
| Formal MR | Today's plan and its status | Open approved plan, or resolve the specific planning prerequisite |

- Keep company identity visible throughout the workspace.
- Base navigation on enabled modules, active workflow and user permissions.
- Keep advanced enterprise configuration discoverable in Settings.
- Give combined roles an explicit way to switch between team management and personal work.
- Enforce access on the backend; hidden menus are not authorisation.
- If readiness later changes, show the specific issue and apply existing operation policies without erasing captured work or forcing the whole company through setup again.

Acceptance: every role lands on a useful screen. Direct users do not see mandatory MTP tasks; Formal users see why an approved plan is needed. Launching one team leaves other teams visibly in preparation.

### Phase 5 Guide the first mobile working day

Connect the Flutter app to the same readiness and workflow contract used by Admin.

- Confirm company identity, assigned HQ and manager on first login.
- Present a short checklist: view customers, prepare offline data, record a visit, submit DCR and complete review where required.
- Request GPS/camera permission at the action that needs it, with a clear recovery route when denied.
- Explain empty states: no assigned customers, no current plan, pending plan approval or setup incomplete.
- Use explicit capture states: Saved on this device, Waiting to sync, Synced and Needs manager review.
- Explain that Flexible planning edits require connectivity while prepared visit execution can work offline.
- Reuse existing journal, retry, duplicate prevention and manager recovery flows.
- Do not lose pending evidence when setup information refreshes, permissions change or the app restarts.

Acceptance: an MR can identify the next action without help, complete a permitted offline visit, recover connectivity and submit one DCR without duplicate visits. A missing prerequisite provides a useful message and responsible contact.

### Phase 6 Enable additional modules progressively

Add a Settings entry for additional modules: samples/inventory, expenses, attendance/leave and sales CRM.

- Show each module's prerequisites and configuration progress before activation.
- Use the same approval and permission services as normal operations.
- Display only enabled, authorised module navigation.
- Preserve enterprise hierarchy and multi-stage approval support.
- Keep optional module setup outside the initial SFA launch unless the company explicitly needs it on day one.

Acceptance: a company can start with core SFA and enable another module later without repeating company onboarding. Enabled modules operate correctly in Admin and the mobile app where supported.

### Phase 7 Validate a fresh company launch

Run the full journey from an empty company through its first reporting day for all three workflows.

- Cover interrupted setup, corrected imports, permission failures, missing customer assignments, invalid approval routing, future policy activation and repeated launch requests.
- Cover HQ-by-HQ launch, combined roles and post-launch assignment changes.
- Verify tenant isolation, policy enforcement and safe offline replay after onboarding changes.
- Exercise the 26–100 MR target configuration and a larger hierarchy with multi-stage approvals.
- Measure setup completion, time to first ready HQ, first MR login, first submitted DCR and first completed review where applicable.
- Conduct an observed usability session with a new administrator, manager and MR. Record where assistance is needed and resolve blocking confusion.
- Keep production HTTP load, longer device idle scenarios and business acceptance from the field-operations roadmap as separate release gates.

Acceptance: a new administrator launches one HQ and its MR completes a real first-day flow without developer assistance; selected-workflow readiness, permissions and reporting remain consistent across Admin/API/mobile. Historical test counts are baseline evidence, not verification of this new journey.

## Readiness classification

| Check | Classification |
| --- | --- |
| Company provisioning incomplete or failed | Company launch blocker |
| No effective, explicitly selected field policy | Company launch blocker |
| MR lacks a usable login, required permission or valid company linkage | Affected MR blocker |
| MR lacks HQ/customer assignments required by the selected launch rules | Affected MR blocker |
| Required DCR approval route cannot resolve an eligible approver | Affected MR blocker |
| Formal planning configuration missing | Affected Formal team blocker |
| Today's plan not yet approved | First-day operation blocker under Formal policy; distinguish from company configuration |
| Optional logo, broader imports or disabled-module setup missing | Optional improvement |
| Device has not completed its first online preparation | First-day task; block only actions requiring unavailable prepared data |

Launch eligibility should not require a completed real visit or approved DCR; those are post-launch success milestones. Do not populate production activity with dummy visits to satisfy onboarding.

## Implementation anchors

Reuse and extend these existing backend entry points after checking their current contracts:

- `app/Filament/Pages/Onboarding.php`
- `resources/views/filament/pages/onboarding.blade.php`
- `app/Services/CompanyOnboardingService.php`
- `app/Filament/Pages/FieldPlanningPreferences.php`
- `app/Services/Field/FieldPlanningSettingsService.php`
- `app/Filament/Pages/FieldTeamOnboarding.php`
- `app/Services/Field/FieldTeamOnboardingService.php`

Mobile changes belong in the existing `C:/Herd/grahak_field` application. Reuse its field planning contract, customer screens, offline preparation and capture/replay components. Keep shared readiness and launch decisions on the backend.

Before implementation, consult version-specific framework documentation and applicable testing/styling skills. Use isolated backend fixtures in `it_erp_testing`; do not create tenants through the destructive tenant lifecycle in Pest. Run relevant backend/Flutter regression suites and formatter checks for the code changed in each phase.

## Delivery milestones

| Milestone | Included phases | Demonstrable result |
| --- | --- | --- |
| Guided setup | 1–3 | Administrator prepares company policy, one HQ, team and customers with actionable readiness |
| First working HQ | 4–5 | Selected team launches; manager and MR land on useful screens and complete the first DCR flow |
| Release readiness | 6–7 | Optional modules remain accessible, enterprise paths pass and fresh-company acceptance is complete |

Continue remaining items in dependency order and record completion evidence against each phase. Calendar estimates should follow inspection of remaining import/login and module activation gaps.

Related implementation baseline: [Field operations delivery roadmap](../../grahak_field/field-operations-roadmap.md).
