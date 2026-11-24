# Mobile parity working state

Updated 24 November 2026. ERP at `C:/Herd/it-erp`, Flutter at `C:/Herd/grahak_field`. Use the [delivery plan](mobile-parity-plan.md#10-remaining-delivery-plan--16-november-2026), [inventory](mobile-parity-inventory.md) and [API contract](mobile-parity-contract.md) for detailed scope and evidence.

## Baseline and scope

R00 reconciled 16 registered items across 14 Flutter destinations. The [R00 audit](mobile-parity-inventory.md#r00-navigation-audit) records the pre-R01 behavior. P00–P06 remain complete. Admin owns menu order, authorization and record visibility. Settings & Administration is excluded. Lead and Deal approval remains a web-only exception.

ERP baseline `d4a996e`. The Flutter source was untracked and the app version was `1.0.7+10`. Flutter 3.47.4, Dart 3.13.3, PHP CLI 8.4.27, installed Laravel 13.42.0, Filament 5.11.3 and Livewire 4.4.8. Laravel and PHP differ from the repository guidance. Pest uses MySQL `it_erp_testing` on port 3307.

## R01 implementation

- Workspace, Reports and Punch use assigned permissions. Punch requires enabled Attendance and an active linked employee. The legacy marketing role receives the initial required-punch grant. Account create and edit controls use server capabilities.
- Admin DCRs, travel and expenses use visible parent reports for lists and record binding. DCR owner and travel customer selectors also validate submitted IDs. Field DCR and expense routes check action permission and ownership before processing or replay. Expense actions require the enabled module. The rollout initializes expense capture grants for roles already allowed to update DCRs.
- The Visit API checks read, create and update permissions before direct actions and queued replay. The web planner checks the user, plan, patch and customer before accessing or creating a visit. Flutter uses Visit and DCR action capabilities. Revocation of DCR detail access removes both shared menu entries and the saved dashboard card after navigation refresh.
- The Approval Register scopes records to the requester or assigned reviewers, subject to existing visibility bypass. CRM, Samples and HR module checks cover Admin resources, direct field endpoints and relevant offline events. E-detailing active files require material view permission. Draft preview also requires update permission.
- Sales document PDFs check authentication, CRM availability, document permission and record visibility. Report filters use visible rows. Queued exports and downloads recheck report permission, `Export:Reports`, ownership and the saved role and record scope. Older report exports without saved scope need regeneration.

- Global Search opens Account detail with its existing edit check. Account writes validate related Account/Contact records, explicit territory and patch assignments, and division-profile choices. Visit quick actions check the target document permission. Linked DCR expenses, quotes and orders use their own permissions and record scope.
- The [R01 selector and action audit](mobile-parity-inventory.md#r01-selector-and-action-audit) records the checks for existing workflows and identifies the ownership rules retained for field capture.

## Automated verification

The full Flutter suite passed **303 tests** after the final Visit workspace and Global Search changes. Targeted Dart analysis reported no issues. This supersedes the earlier 302-test run and the subsequent focused Visit checks.

The final focused backend run passed **39 tests** covering Account create/edit, addresses, reporting selectors, Admin report interaction and Visit quick actions. A shared test fixture now provisions its own sales role and Account permissions. Pint and diff checks passed.

The final broader backend run completed **413 tests** across API v1, Reports and related Account, approval, travel, expense, PDF, Punch, Admin and onboarding suites. **410 passed and 3 failed** (2,895 assertions). The three failures also reproduced at `d4a996e`.

- `FlexiblePlanningApiTest` expects the cross-HQ mutation to throw `ModelNotFoundException` through Livewire.
- `FreshCompanyJourneyTest` configures formal monthly planning with approval disabled.
- `ImportedCompanyFirstLoginTest` expects 13 representatives ready and receives 26.

## Live acceptance

| Track | Status | Evidence to capture |
| --- | --- | --- |
| Role | Pending | Apply permission changes in Admin. Check no-role and read-only accounts, an authorized rep, manager and elevated roles. Verify menu and action refresh, direct denial after revocation and replay behavior. |
| Company | Pending | Test two companies with different module settings and overlapping record IDs. Verify tenant isolation for selectors, detail, saved queues, previews and exports. |
| Device | Pending | On a physical device, verify login/resume navigation, offline capture, reconnection, retained queued evidence and permission refresh. Record the app build, OS and test company. |

## R02 standalone navigation

The negotiated schema 1 response adds standalone destinations for Punch, Visits, DCR Expenses, E-detailing Library and SGIP. Legacy keys remain available for older apps and for record links. Visits, expenses and SGIP initially list owned records and reopen the existing workflows. E-detailing Library previews published material. Visit presentation recording remains available from the Visit workspace.

Admin cluster metadata covers the three HR parents and All Reports. Child destinations appear as their resource waves provide working screens. The Admin inventory regression accounts for eligible destinations through a mobile binding or a documented delivery gap. [Navigation contract](mobile-parity-contract.md#permission-mapping).

The final Flutter suite passed **309 tests**. Dart analysis reported no issues. The R02 backend scope passed **56 tests** (349 assertions), covering the registry, negotiation, eligible Admin inventory, Visit, expense, SGIP, E-detailing and Attendance. Pint and diff checks passed.

The standalone registers use the existing personal field record scope. Team registers and additional actions remain in their resource waves. Live role, company and device acceptance remain pending. Rollout should make the compatible API available before releasing the new mobile build.

## M01 My Workspace

G01-01 adds Dashboard to the negotiated mobile menu for users with Dashboard access. It uses the seven Admin charts and the same four reporting periods, report visibility and per-user chart preferences. Users can choose a period, save any chart selection or restore all charts. The dashboard is online. Today’s Visits remains the field landing screen.

The focused backend run passed 25 tests (209 assertions) for Dashboard access, chart scope, per-user preferences and negotiated navigation. The full Flutter suite passed 310 tests, including period selection and chart preference save/reset. Dart analysis, Pint and diff checks passed. Build 12 predates this Dashboard work.

Dashboard now has a Personal summary and a Team switch for eligible users. Personal counts are always restricted to the signed-in user. Team counts require management access and existing record visibility. Reviewers with assigned current steps may see their own pending approval count. Chart preferences and reporting periods work independently of the summary switch. The full Flutter suite passed 310 tests and the focused Dashboard backend suite passed 8 tests (111 assertions).

My quick links is available from Modules on compatible servers. Mobile and Admin share preferences. Ordered mobile saves preserve entries outside the mobile catalog, and resetting restores shared defaults. All 311 Flutter tests passed, along with 19 mobile backend tests and seven Admin navigation tests.

The Dashboard setup reminder uses the Admin eligibility rule and shared per-user dismissal. Remind me later lasts for the current sign-in, and Continue setup opens the current company checklist in the browser. All 313 Flutter tests and 12 targeted backend tests passed.

My Approvals now opens from My Workspace and the Dashboard approval count when authorized. The inbox and detail use the current assigned step. Approve and Reject use the existing workflow service and check the exact step under the workflow lock. Lead and Deal approval stays on the web. All 314 Flutter tests and 43 targeted backend tests (265 assertions) passed. Dart analysis, Pint and diff checks passed.

Approval Register now lists scoped history, status and trash filters, review steps, and activity. It exposes the existing lifecycle actions by permission and record state, with all records checked before a bulk change is applied. Approved records retain the existing approval override requirement. All 315 Flutter tests and 45 targeted backend tests (279 assertions) passed. Dart analysis, Pint and diff checks passed. Requests still originate in their owning module. Approval decisions still use My Approvals. Lead and Deal approvals remain web-only.

The next delivery wave is M02 Field Operations. Team Coverage filters and drill-down remain in that wave. Live acceptance remains tracked separately.

## M02 Field Operations

Tour Plans now has a Team plans view when the caller can read plans belonging to other representatives. Team list and detail use the Admin Tour Plans visibility scope and separate list and view permissions. Personal plans, current-step review and existing plan changes keep their own entry points. Team plan detail is read-only. All 32 targeted backend tests passed (269 assertions), including Admin territory scope, revoked access and the separate review inbox. All 316 Flutter tests passed. Dart analysis, Pint and diff checks passed.

G02-01 now supports delegated creation, daily plan edits for visible draft and rejected plans, single and bulk submission, and permitted soft deletion from the detail and register. Delegated creation uses Admin’s position hierarchy and the selected employee’s planning options. Writes check current capabilities and revisions. Days with recorded visits stay locked. Bulk deletion validates the entire selection before applying changes. Approval decisions stay in the assigned inbox.

The targeted backend pack passed 41 tests (358 assertions), covering Tour Plans, Admin creation and mobile navigation. The full Flutter suite passed 318 tests. Dart analysis, Pint and diff checks passed.

Changing the employee on an existing plan remains an Admin workflow.

G02-02 Flexible Planning now supports online team week review in Flutter, with current HQ and representative filters shared with Admin. The existing Field Team management grant controls review within the company. Changing the week keeps the representative selected. Day, patch and status filters leave the week totals intact. Team review is read-only and stays out of offline storage. Personal planning and offline preparation retain their existing flows.

The Flexible Planning backend regression pack passed 38 tests (296 assertions), including cross-representative action denial and permission revocation. The full Flutter suite passed 320 tests. Dart analysis, Pint and diff checks passed.

G02-03 Visits now has personal and team registers. Team records use Admin visibility and open a read-only summary. The register supports document number search, approval status, representative and trash filters, and single or bulk trash actions for eligible drafts. The server rechecks every selected visit before writing and protects completed or approved visits, linked field evidence, and locked DCRs.

The Visits backend regression pack passed 44 tests (290 assertions). The full Flutter suite passed 321 tests, including scoped team detail and bulk trash confirmation. Dart analysis, Pint and diff checks passed.

The legacy Admin Complete and Send for Approval status shortcuts remain web-only. Mobile uses the existing owner checkout and DCR workflows.

G02-04 Daily Call Reports now opens a paginated personal and team register on compatible servers. It supports date range, approval status, representative and trash filters. Team reports follow Admin visibility and open read-only summaries with separately authorized visit and expense lines. My Approvals opens the report summary for a current assignee who also has report access. Approval decisions use the existing review workflow.

The DCR backend regression pack passed 45 tests (355 assertions). The full Flutter suite passed 323 tests. Dart analysis, Pint and diff checks passed.

Eligible empty drafts support individual and bulk trash actions. The server protects linked visits, expenses, travel, approval and field evidence, as well as pending sync work. Restore checks for an existing active report on the same date. Personal DCR entry, auto-calculation, offline queues and submission use the existing owner workspace. Team owner/date changes and related-record writes remain Admin workflows.

G02-05 Travel Segments now opens a paginated online register from Field Operations. Reads use the parent DCR’s Admin visibility. The register shows source, route and distance details and supports manual creation, edits and scoped individual or bulk trash actions. The form validates its DCR, visit, plan, patch and customer relationships. Repeated create requests with the same operation key reopen the original result. Edits check the current segment revision and both parent reports if the DCR changes.

The Travel Segments backend regression pack passed 60 tests (359 assertions). The full Flutter suite passed 324 tests. Dart analysis, Pint and diff checks passed.

Generated and workbook-imported rows are read-only on mobile. Workbook import uses the existing Admin Data Import workflow.

G02-06 DCR Expenses now opens an online personal and team register with search, source and trash filters. Team reads require access to both the DCR and the expense. Authorized users can add, edit or move eligible manual expenses, run the shared calculation, and apply individual or bulk trash actions. A move updates both reports’ totals in one transaction. Receipts remain owner-only. Expenses with receipts, line items or offline history stay on their original DCR and are protected from permanent deletion. Today’s expense workspace still handles offline entry and receipt uploads.

The DCR Expenses backend regression pack passed 40 tests (380 assertions). The full Flutter suite passed 325 tests, including scoped expense creation through the register and offline receipt synchronization. Pint and diff checks passed.

G02-07 Customer Coverage now supports selecting a representative’s month by HQ and representative under the same access rules as Admin. Customer search, status and historical patch filters narrow the list while the monthly summary retains the full report totals. Customer detail shows assigned days, target and expected calls, completed and unapproved calls, remaining calls and the last credited visit. Team reports and selector data stay online. Personal reports retain the seven-day offline snapshot.

The Customer Coverage backend regression pack passed 49 tests (398 assertions), including shared Admin totals, historical patches, HQ validation and permission revocation. The full Flutter suite passed 327 tests, including scoped review, customer detail and offline snapshot isolation. Dart analysis for changed files, Pint and diff checks passed.

M02 Field Operations is automated verified. Live acceptance remains pending.

## M03 Customers & Territories

G03-01 Accounts now includes a paginated register with search, type, territory, patch, specialty, therapy area, lifecycle status and trash filters. It supports individual and bulk trash, restore and eligible permanent delete. New trash operations preserve contact and address links. Field Team managers can choose an owner on create or edit, with changes recorded in assignment history.

Account reporting supports classification and lifecycle status, division profiles, monthly call targets and read-only assignment history. The detail page supports scoped contact management, address unlinking, bank account editing and related-record removal. The existing account form handles GST, credit and statutory edits. The approval engine has no Account outcome handler, so account approval submission is unavailable. Live acceptance remains pending.

The Accounts backend regression pack passed 72 tests (498 assertions), including owner assignment, linked-record scope, address preservation and approval locks. The full Flutter suite passed 329 tests, including bulk restore and monthly call target editing. Dart analysis for changed files, Pint and diff checks passed.

G03-02 Contacts opens from Customers & Territories on compatible servers. The paginated register supports search and company filtering, contact profiles and create or edit forms, scoped company and job reference choices, contact-owned address editing, and individual or bulk deletion. A batch validates every contact before deleting any. Existing workflow links and approval locks protect contacts from deletion. Standalone Contacts is online, and the compact contact picker still serves account and CRM workflows.

Contact approval submission requires an outcome handler, which the shared approval engine lacks for Contacts. Live acceptance remains pending.

The Contacts backend regression pack passed 64 tests (463 assertions). The full Flutter suite passed 330 tests, including company filtering, contact editing and saving an address without a mapped area. Dart analysis for changed files, Pint and diff checks passed.

G03-03 Companies opens from Customers & Territories on compatible servers. The register supports search, pagination, company profile edits, scoped industry selection, and individual or bulk deletion. Authorized users can also create a company from the Contact form and select it for that contact. Company address actions cover adding, editing, removing and ordering addresses owned by the company. Account addresses with a company ID stay in the Account workspace.

Company and account profile edits are independent after the initial company creation from a configured account. Deletion protects companies with account, contact, address or tax links and checks the entire selection before any change. Company approval submission requires an outcome handler, which the shared approval engine lacks for Companies. Live acceptance remains pending.

The Companies backend regression pack passed 54 tests (448 assertions), including profile scope, address ownership, ordering, tax-linked address protection and inline company creation. The full Flutter suite passed 331 tests. Dart analysis for changed files, Pint and diff checks passed.

G03-04 Territories opens from Customers & Territories on compatible servers. The online register supports search and pagination, status, type, parent and trash filters, territory detail, create and edit, and individual or bulk trash. Authorized users can manage hierarchy, divisions, positions, and city or PIN links. Territory detail opens the related patch list and editor, subject to Patch permissions and visibility.

Hierarchy validation blocks cycles. Association edits preserve existing links that fall outside the caller’s visibility, and bulk trash validates the full selection before applying changes. Territory approval submission requires an outcome handler, which the shared approval engine lacks for Territories. Live acceptance remains pending.

The focused backend suite passed 40 tests (324 assertions) across Territories, mobile navigation and Field Team Coverage. All 332 Flutter tests passed. Dart analysis, Pint and diff checks passed. The broader backend run also reported three PostgreSQL boolean comparison failures in Sales Performance and two Admin onboarding failures involving field-route rendering and approval setup.

G03-05 Patches opens from Customers & Territories on compatible servers. The online register supports search, territory and trash filters, detail, create and edit, and individual or bulk trash. Territory and PIN choices follow the actor’s assignments. Assigned customers can be searched and filtered by type. Authorized users can attach selected or all eligible customers, edit route sequence, and remove individual or selected assignments.

Authorized company admins can save a template, preview an Excel workbook, confirm the import and resume progress. Mobile imports the Patches and Patch Customers sheets with existing territory and PIN links. Standard fares and historical travel use the Admin importer. Patch approval submission requires a shared outcome handler. Live acceptance remains pending.

The backend regression suite passed 53 tests (436 assertions). All 333 Flutter tests passed. Dart analysis, Pint, diff checks and Android Kotlin compilation passed. The iOS workbook picker still needs an Xcode build.

G03-06 HQ & Customer Assignments opens from Customers & Territories. The online form lets authorized managers choose any user in the current company, see their current HQ, choose an eligible HQ, and assign or transfer selected customers. Direct DCR allows a blank HQ and keeps the existing assignment. Formal MTP requires an active HQ linked to one of the representative’s position territories. In other modes, authorized users can create an active HQ by name using the existing location number series.

The shared Admin service checks the whole selection before applying any change. Transfers preserve ownership history. Initial call targets are seeded only when no target covers the current month and end before any scheduled future target. Live acceptance remains pending.

The backend regression pack passed 67 tests (483 assertions), including permission revocation, customer scope, assignment history, HQ creation and planning modes. All 334 Flutter tests passed, including HQ creation and confirmed customer transfers. Targeted Dart analysis, Pint and diff checks for the changed files passed.

G03-07 Territory Sales Assignments opens from Customers & Territories. The online register shows dated ownership and reasons for territories in the same scope as Admin. Users with Sales Targets view access can read history. Users with Sales Targets management access can append a new assignment using searchable territory and representative selectors. The shared service closes the prior period the day before the transfer and rejects out-of-scope, duplicate-owner and out-of-order transfers.

All 335 Flutter tests passed, including read-only history, search and manager transfer. The backend run passed 28 tests, including all three new Territory Sales Assignment tests. The three previously documented Sales Performance failures reproduced on PostgreSQL in the secondary-sales boolean comparisons. Targeted Dart analysis, Pint and diff checks passed.

M03 Customers & Territories implementation is complete. Live acceptance remains pending.

## M04 Sales & CRM

Lead and Deal create/edit, scoped account and contact pickers, Lead territory selection, Lead conversion, Notes creation, Items read access, lead-scoped Follow-ups and Activity pagination shipped. Conversion requires a visible lead, `Update:Lead` and `Create:Deal`. It preserves the account type and code.

G04-01 Lead Notes now loads older pages from the existing API. A transient failure keeps the loaded notes visible and retries the same page. Refresh and successful note creation reload page one. Access still follows Lead visibility, and live acceptance remains pending.

All 336 Flutter tests and 26 focused backend tests (156 assertions) passed. Targeted Dart analysis, Pint and diff checks passed.

Lead and Deal forms now offer contact-owned addresses for standalone and account-linked contacts. The picker uses the existing Contact read permissions and visibility, including for legacy address links. Older saved values stay visible when they cannot be listed. Switching or clearing a contact clears its selected address, and account addresses are available when there is no contact. Live acceptance remains pending.

All 337 Flutter tests and 38 focused backend tests (249 assertions) passed. Targeted Dart analysis, Pint and diff checks passed.

G04-01 Lead lifecycle actions now include confirmed individual and bulk soft deletion on mobile. The server checks the full selection for Lead visibility, delete permission and the existing approval override rule before deleting any row. Linked history remains in place. Lead approval stays on the web. Live acceptance remains pending.

All 338 Flutter tests and 36 focused backend tests (240 assertions) passed. Targeted Dart analysis, Pint and diff checks passed.

G04-02 read-only Follow-up detail now opens from a non-editable row in the Lead’s Follow-ups list. It fetches current details from the existing scoped endpoint and shows scheduling, assignments, interaction and outcome. Failed requests can be retried, and denied requests show the server error. Editable rows still open the edit form. Live acceptance remains pending.

All 339 Flutter tests and 10 Follow-up API tests (69 assertions) passed. Targeted Dart analysis, Pint and diff checks passed.

G04-02 Follow-ups now opens as a standalone register under Sales & CRM. The online list searches across visible follow-ups and loads additional pages without opening a Lead first. Read-only rows open the scoped detail, and editable rows recheck permission before opening the form. Authorized users can create a follow-up by choosing a visible Lead. Lead-scoped Follow-ups still opens from Lead detail. Live acceptance remains pending.

All 341 Flutter tests and 35 focused backend tests (247 assertions) passed. Targeted Dart analysis, Pint and diff checks passed.

G04-03 Lead Activities now opens as a standalone read-only register under Sales & CRM. It searches and pages visible activity across Leads, with optional start and end dates. Lead names and references require separate Lead visibility. The timeline inside a Lead keeps its existing scope. Live acceptance remains pending.

All 342 Flutter tests and 37 focused backend tests (288 assertions) passed. Targeted Dart analysis, Pint and diff checks passed.

G04-02 Follow-up lifecycle actions add confirmed individual and bulk soft deletion to the standalone register and the Lead’s Follow-ups list. The server validates the full selection for visibility, delete permission and approval override before deleting any row. Approved legacy records require an audited override. Deletion adds a Lead Activity entry when the follow-up belongs to a live Lead. New approval submission requires a Follow-up outcome handler in the shared engine. Live acceptance remains pending.

Verification passed for all 343 Flutter tests, 39 focused backend tests (326 assertions), targeted Dart analysis, and Pint.

G04-03 Lead Activity lifecycle actions add create, edit, and confirmed individual and bulk soft deletion in the standalone register and on a Lead’s timeline. The register checks both Activity and Lead visibility and offers a scoped user picker. Timeline creation records the signed-in user, and editing there keeps the existing Lead and user. Bulk delete validates the entire selection before changing any row. Changes to legacy approved rows require an audited override. New approval submission requires a Lead Activity outcome handler. Live acceptance remains pending.

All 345 Flutter tests and 41 focused backend tests (393 assertions) passed. Targeted Dart analysis, Pint, and diff checks passed.

G04-04 Deal lifecycle actions add confirmed individual and bulk soft deletion to the mobile Deals register. The server checks Deal visibility, delete permission, and legacy approval override for the entire selection before deleting any row. Soft deletion preserves linked records and the existing Lead conversion history. New Deal approval submission requires an outcome handler in the shared approval engine. Live acceptance remains pending.

All 346 Flutter tests and 62 focused backend tests (448 assertions), including Lead conversion, passed. Targeted Dart analysis, Pint, and diff checks passed.

G04-05 Quotes adds a standalone register, detail, and editor under Sales & CRM. The API calculates and stores item pricing, discounts, GST, charges, and totals. Quote actions support individual and bulk soft deletion, approval submission through the shared engine, decisions through My Approvals, and conversion to a linked Sales Order or Sales Invoice. Repeated mobile conversion returns the existing link. PDF preview and download use the scoped Quote read permission. Visit-based quote creation still opens the existing draft workflow. Live acceptance remains pending.

All 348 Flutter tests and 40 focused backend tests (334 assertions) passed. Targeted Dart analysis, Pint, diff checks, and Android Kotlin compilation passed. iOS compilation and device acceptance are pending.

G04-06 Sales Orders opens from Sales & CRM on compatible servers. The online register supports search, pagination, filters, draft creation and edits, individual and bulk deletion or approval submission, and private PDF preview and download. Authorized users can open orders from linked Quotes and Visits. Orders with lines can convert to a linked draft invoice after any required approval. Repeat conversion returns the existing link. My Approvals handles decisions.

The server calculates prices, discounts, GST and totals. A tenant migration aligns sales document quantities with the existing three-decimal field and delivery contracts. Pending, approved, delivered and invoiced orders are locked. Captured field orders continue to use the Visit workflow for edits and replay while eligible. Invoice conversion closes the order to later replay. Stock posting remains in the delivery workflow.

All 350 Flutter tests passed. The focused Sales Order, Quote and order replay run passed 7 tests (177 assertions). The broader backend run passed 65 of 66 tests. The existing Visit feedback recovery test fails during PostgreSQL fixture setup because the feedback question is missing `answer_type`. Dart analysis, Pint and scoped diff checks passed. Live acceptance remains pending.

G04-07 Sales Invoices opens from Sales & CRM. The online register supports search, filters, pagination, invoice creation and editing, due dates and payment details, private PDFs, and individual and bulk deletion or approval submission. My Approvals handles decisions. Authorized quote and order detail links open the invoice directly.

Eligible invoices can create partial Delivery Challan drafts with an active dispatch location. Existing drafts reduce the allocatable quantity, and retrying the same operation returns the original result. Direct Admin entry uses invoice visibility and validates the lines before committing the draft. Posting in Admin checks invoice and item ownership and remaining quantities under lock. Invoices under review, approved invoices, and invoices with active challans or delivered quantities are locked against mobile edits and deletion.

All 351 Flutter tests and 50 focused backend tests (506 assertions) passed, including direct Admin challan creation and posting safeguards. Dart analysis, Pint and scoped diff checks passed. Live acceptance remains pending.

G04-08 Prices & Discounts opens from Sales & CRM. The online register supports search and pagination, scoped customer and variant selection, price and discount editing, and individual or bulk soft deletion. Admin and mobile share record and customer visibility, validation, and legacy approval locks. New submissions require a Customer Price outcome handler.

The tenant migration enforces one active customer and item pair while preserving soft-deleted rows. New commercial lines use the exact item or variant price with catalog fallback. Existing document lines keep their captured prices for the same customer and item. Offline Visit order replay uses its existing capture pricing rules.

All 352 Flutter tests and 61 backend regression tests (707 assertions) passed. The final pricing rerun passed 4 tests (124 assertions), including Admin create, edit and bulk deletion. The backend pack excluded the known Visit feedback fixture failure related to `answer_type`. Dart analysis, Pint and scoped diff checks passed. Live acceptance remains pending.

G04-09 Territory Sales Targets adds online creation and revision to Sales Performance. Managers can create monthly, quarterly and annual targets for current territories. Future revisions change value, base units and reason, with the revision checked before saving. The server retains revision history. Historical scope remains available for achievement reads, and the seven-day account-scoped fallback remains available on network failure.

The PostgreSQL achievement queries now use boolean comparisons for verified returns and breakage. Net secondary sales remains the achievement basis. Primary invoices and RCPA estimates remain separate.

All 353 Flutter tests passed. The 34 focused backend tests passed (308 assertions), along with 7 Secondary Sales regression tests (87 assertions). Targeted Dart analysis, Pint and scoped diff checks passed.

M04 Sales & CRM implementation is complete. Live acceptance remains pending. M05 Secondary Sales is next.


## M05 Secondary Sales

G05-01 Sales Statements adds a participant-scoped mobile register, invoice and stock-balance template downloads, private originals, and CSV/XLSX intake with matching OCR evidence. Detail shows paginated normalized rows, reconciliation balances and adjustments. Assigned reviewers record a note and verify physical adjustments before approval. Eligible users can request reversal or replacement from an approved statement.

All 354 Flutter tests and 41 focused backend tests (424 assertions) passed. Dart analysis, Pint, scoped diff checks and Android Kotlin compilation passed. iOS compilation and live acceptance remain pending.

G05-02 Corrections adds a mobile queue for requesters and assigned reviewers. Detail shows the request reason, original and replacement statement totals and statuses, recorded review notes, and released invoice groups and adjustment keys. Correction participants can inspect the replacement rows, stock balances and adjustments and download the private corrected file through the correction.

Assigned reviewers can approve or reject with a note. Approval requires physical verification of each replacement adjustment. The existing ledger transaction applies reversal or replacement and preserves the correction history.

All 355 Flutter tests and 44 focused backend tests (518 assertions) passed. The correction API tests covered participant scope, private evidence, permission revocation and atomic posting. Dart analysis, Pint and scoped diff checks passed. Live acceptance remains pending.

G05-03 OCR Sources adds a participant-scoped mobile register for PDF, PNG and JPEG scans. Users can download the private original and inspect extraction status, word confidence, extracted text, and the linked statement and mapping note. Authorized uploaders can retry failed or abandoned jobs through the existing extraction service and map a scan through structured statement import with a verification note.

All 356 Flutter tests and 47 focused backend tests (596 assertions) passed. Dart analysis, Pint, scoped diff checks and Android Kotlin compilation passed. iOS compilation, live queue-host OCR accuracy and device acceptance remain pending.

M05 Secondary Sales implementation is complete. Live acceptance remains pending.

## M06 Marketing & Samples

G06-01 Marketing Campaigns adds an online mobile register with search, filters, scoped selectors, and campaign creation and editing. The editor covers status, dates, budget, promotional scheme, items and territory quotas. Authorized users can delete, restore or permanently delete eligible campaigns individually or in bulk.

Admin and mobile share transactional writes and revision checks. Campaign edits preserve posted quota usage. SGIP checks the current campaign and locks it during quota validation and posting. Campaigns with linked sample requests, distributions or recorded usage are retained.

All 357 Flutter tests and 35 focused backend tests (310 assertions) passed, including Admin configuration and bulk deletion. Dart analysis, Pint and scoped diff checks passed. Live acceptance remains pending.

G06-02 Promotional Schemes adds an online mobile register for scheme type, status, validity, minimum order value, applicability and benefit tiers. Admin and mobile share scoped customer, territory and item choices, transactional saves, revision checks and individual or bulk record actions. Schemes linked to campaigns are protected from permanent deletion.

Resolver tests verify the current date and minimum-order boundaries and the returned tier configuration. The resolver has no sales posting call sites. Item applicability and quantity tiers are stored configuration, with application in sales documents still pending.

All 358 Flutter tests and 33 focused backend tests (379 assertions) passed. Dart analysis, Pint and scoped diff checks passed. Live acceptance remains pending.

G06-03 E-detailing Library adds online material management within the existing standalone library. Authorized users can upload or replace a PDF, JPG or PNG up to 10 MB, assign a scoped product, set sort order, publish or withdraw material, preview the current version and see presentation counts.

Field and management previews use authenticated, version-checked responses with private, no-store caching headers. Existing visit records retain the title and version originally presented. The mobile app reads management capability online and retains the existing offline field library and visit replay.

All 359 Flutter tests and 44 focused backend tests (330 assertions) passed. Dart analysis, Pint, scoped diff checks and Android Kotlin compilation passed. iOS compilation and live acceptance remain pending.

G06-04 RCPA Observations adds an online review register within the existing mobile RCPA screen. It supports search and date filters, read-only observation and competitor details, the rolling weekly or fortnightly chemist due list, and a filtered CSV export. The register and export label observations as field estimates.

Admin and mobile share observation visibility. Resource-policy-only review is self-scoped. Due monitoring and export use the existing RCPA business permissions. The offline capture and replay, append-only observations and DCR due checks retain their existing workflow.

All 362 Flutter tests and 36 focused backend tests (333 assertions) passed. Dart analysis, Pint, scoped diff checks and Android Kotlin compilation passed. iOS compilation and live acceptance remain pending.

G06-05 Sample Requests adds an online register with search, status and deleted-record filters, draft creation and editing, submission, per-line approval, cancellation, and permitted trash actions. Approved or partially issued requests with a remaining quantity can open the existing Admin Sample Issue form.

Admin and mobile share request visibility, scoped selections, revision checks and transactional status changes. Issue posting locks the issue and request, checks the request status and destination, and moves stock within the remaining approved quantities.

All 363 Flutter tests and 36 focused backend tests (286 assertions) passed. Scoped Dart analysis, Pint, diff checks and Android compilation passed. Repository-wide Flutter analysis reported two existing lint findings in `salesperson_ai_test.dart`. iOS compilation and live acceptance remain pending.

G06-06 Sample Issues adds an online mobile register with search, status and deleted-record filters, request-linked draft creation and editing, stock posting, and permitted individual and bulk trash actions. Approved Sample Requests can now open the native issue editor.

Admin and mobile share scoped request and active location selections, transactional saves and revision checks. Posting uses the existing locked stock workflow, validates remaining approved quantities and records paired movements. Posted and cancelled issues are retained. The existing SGIP sample ledger and offline replay remain unchanged.

All 365 Flutter tests and 46 focused backend tests passed. Scoped Dart analysis, Pint, diff checks and Android compilation passed. iOS compilation and live role, company and device acceptance remain pending.

G06-07 SGIP Distributions adds an online review register with search, status, date and deleted-record filters, draft creation/editing, compliance details and reservation/posting history. Authorized users can submit, approve/post, return submitted records to draft, and use permitted draft trash and bulk actions. Register access uses SGIP permissions independently of Visit permissions.

Admin and mobile share record visibility, scoped selections, transactional saves and revision checks. Visit-linked customer, territory, date and owner remain attached to the captured visit. Both Admin and field submission validate compliance and reserve stock for the distribution owner. Returning to draft releases the reservation; posting deducts stock and consumes campaign quota once. A changed reserved source requires resubmission. Campaign quota validation sums repeated item lines. Posted and other non-draft records are retained.

All 367 Flutter tests and 68 focused backend tests (566 assertions) passed. Scoped Dart analysis, Pint, diff checks and Android compilation passed. Existing offline capture, ledger snapshots and conflict recovery remain available. iOS compilation remains pending. The user will handle live role, company and device acceptance later.

M06 Marketing & Samples implementation is complete.


## M07 Inventory & Purchasing

G07-01 Items adds an online native register with search, item-type and expiry filters, the master editor, applicable taxes, variant creation/association/editing and permitted variant trash actions. Supplier creation and editing reuse the Account forms; lead links store quantity and price on the item/lead pivot. Stock by location is read-only and checks stock permissions, module availability, stock scope and location scope.

Admin and mobile share transactional writes, scoped selections, revision checks, unique SKU validation and explicit record/bulk permissions. Variant association prevents cyclic parenting. Item codes are generated by the server. Item maintenance does not post stock, and items remain outside the optional inventory module. Existing classified/order catalogs and offline caches retain their field behavior.

Items are currently excluded from supported approval settings, so new approval submission remains unavailable in both clients. Existing pending and approved item history still enforces maintenance locks; approved changes require an authorized override recorded with the user's ID.

All 369 Flutter tests and 45 focused backend tests passed across the regression and final relation checks. Scoped Dart analysis, Pint, scoped diff checks and Android compilation passed. iOS compilation remains pending. The user will handle live role, company and device acceptance later.

G07-02 Stock on Hand adds an online read-only native register with item/location search and filters, sorting, pagination, precise on-hand/reserved/available quantities, average cost, last movement and freshness time. Filter choices come only from readable balance rows. Archived item and location labels remain available.

Admin, API and item/location stock relations share stock creator/OU/bypass visibility intersected with location visibility. Direct routes and navigation require both stock read permissions and enabled inventory. Shared locations do not grant access to another creator's stock; system-owned balances retain existing stock scope rules. Native reads stay bound to the signed-in account and server, use private/no-store responses and clear displayed balances during refresh or failure. Stock writes, existing sample-ledger snapshots and offline capture/replay retain their established behavior.

All 371 Flutter tests and 58 focused backend tests passed across the regression run and isolated stock rerun. Scoped Dart analysis, Pint, scoped diff checks and Android compilation passed. iOS compilation remains pending. The user will handle live role, company and device acceptance later.

G07-03 Purchase Orders adds an online native register with search, status/supplier/date filters, pagination, draft creation/editing, payment terms, line quantities/prices/taxes/descriptions and server-calculated totals. Detail shows ordered, received and remaining quantities, approval state and visible receipt drafts/history. Authorized actions include configured approval submission, approval when no workflow is required, draft cancellation, deletion and atomic bulk submit/delete.

Admin and mobile share scoped selections, transactional saves/actions and revision checks. Pending, approved, received and cancelled documents remain locked; receipt history prevents deletion. GRN creation requires receipt create/read grants, locks the PO and remaining lines, copies only unreceived quantities and reuses an existing visible draft. Creation and receipt-action retries retain their operation identity. Neither PO maintenance nor draft receipt creation posts stock. Receipt editing, approval and posting are the next G07-04 workflow. PO and GRN remain outside the optional inventory toggle.

All 374 Flutter tests and 51 focused backend tests passed across the regression and final PO checks. Scoped Dart analysis, Pint, scoped diff checks and Android compilation passed. iOS compilation remains pending. The user will handle live role, company and device acceptance later.

G07-04 Goods Receipts (GRN) adds an online native register with search, status/supplier/date filters, pagination, standalone and PO-linked draft editing, received quantities/costs, batch/lot and remarks. Authorized users can submit for approval, post to stock and delete eligible drafts individually or atomically in bulk. Detail retains posting, poster and stock movement history. Purchase Order receipt cards now open the native GRN workflow.

Admin and mobile share scoped selections, transactional saves/actions and revision checks. Linked PO supplier/location/item/line associations and combined quantities are validated. Revisions include current PO receipt quantities. Posting locks the receipt, PO and PO lines before checking remaining quantities, writes stock and updates PO receipt status atomically. Posted receipts have one stock effect and remain reviewable. Pending/approved/posted/cancelled records cannot be edited or deleted. GRN remains outside the optional inventory toggle. Creation/posting retries retain operation identity; existing sample-ledger snapshots and offline replay remain unchanged.

All 378 Flutter tests and 62 focused backend tests (487 assertions) passed. Scoped Dart analysis, Pint, scoped diff checks and Android compilation passed. iOS compilation remains pending. The user will handle live role, company and device acceptance later.

G07-05 Delivery Challans adds an online native register with search, status/customer/date filters, pagination, standalone and invoice-linked draft editing, delivered quantities/costs, batch/lot and remarks. Authorized users can submit for approval, post outbound stock and delete eligible drafts individually or atomically in bulk. Detail retains posting, poster and movement history. Sales Invoice linked challans now open the native workflow; existing invoice draft creation remains available.

Admin and mobile share scoped selections, transactional saves/actions and revisions. Linked customer/item/line IDs and combined quantities are validated against the authorized invoice. Draft allocations reduce available invoice quantities; editing excludes the current draft. Revisions include invoice quantities and draft allocations. Posting rechecks approval, locks invoice lines, respects stock reservations and updates stock/invoice delivered quantities atomically once. Cancelled documents cannot post; pending/approved/posted/cancelled records cannot be edited or deleted. Dispatch location visibility now applies to the invoice handoff. Standalone management remains outside the optional inventory toggle; invoice handoff module checks and existing offline workflows remain intact.

All 381 Flutter tests passed across the regression run and isolated reruns. The full run had two offline-test timeouts during the tool/runtime interruption; both affected files passed separately. All 79 focused backend tests (701 assertions), scoped Dart analysis, Pint, scoped diff checks and Android compilation passed. iOS compilation remains pending. The user will handle live role, company and device acceptance later.

G07-06 Stock Transfers adds an online native register with search, status/item/source/destination filters, pagination, draft creation/editing, approval submission, posting and permitted deletion. Detail retains exact quantities/costs, approval/posting state, poster and paired movement history. Admin retains its modal workflow and adds read-only review.

Admin and mobile share scoped item/location choices, transactional saves/actions and revisions. Both read permissions and enabled inventory are required on navigation and direct routes. Posting rechecks current item/locations and unreserved stock, locks both balances in location-ID order and applies transfer-out/transfer-in atomically once. Drafts do not reserve stock; competing drafts are rechecked on posting. Pending/approved/posted/cancelled records stay locked. Creation/post retries retain operation identity, and stale edits require reload. Existing sample-ledger snapshots and offline replay remain unchanged.

All 384 Flutter tests and 74 focused backend tests passed across the regression run and final competing-draft check. Scoped Dart analysis, Pint, scoped diff checks and Android compilation passed. iOS compilation remains pending. The user will handle live role, company and device acceptance later.

G07-07 Stock Adjustments adds an online native register with search, status/type/item/location filters, pagination, draft creation/editing, approval submission, posting and permitted draft/bulk deletion. Receipt/Increase add stock; Issue/Decrease remove stock. Detail retains quantity/cost, required reason, approval/posting state, poster and movement history. An omitted unit cost preserves the existing current-stock-cost behavior.

Admin and mobile share scoped root-item/active-location choices, transactions, revisions and actions. Both read permissions and enabled inventory are required for navigation and direct routes. Reason is checked at save, submission and posting. Posting locks the adjustment and balance, respects stock reservations and applies one movement atomically once. Pending/approved/posted/cancelled records stay locked. Bulk deletion checks the entire selection before deleting any draft. Creation/post retries retain identity and stale edits require reload. Opening-stock imports keep the same posting service; existing sample-ledger snapshots and offline replay remain unchanged.

All 388 Flutter tests and the final 12 focused adjustment tests (126 assertions) passed. The backend regression run passed 95 tests, including adjustment, stock posting and opening-stock import coverage, with three unrelated HQ/shift import failures in OptionalOnboardingImportTest: HQ rerun detection, HQ permission enforcement and shift rerun detection. All three failures also reproduced in isolation. Scoped Dart analysis, Pint, scoped diff checks and Android compilation passed. iOS compilation remains pending.

G07-08 Stock Ledger adds an online read-only native register with search, item/location/type/inclusive-date filters, sorting, pagination and fetch time. Admin and native filters include all twelve posting types, including delivery challans, sample issues and SGIP distributions. Rows retain exact quantities, recorded balance after, nullable cost/value, movement time, source type/ID, remarks and archived master labels. Filters do not recalculate historical balances.

Admin and API share movement creator/OU/bypass visibility intersected with location visibility. Navigation and direct reads require both movement read grants and enabled inventory. Filter choices come only from readable movement rows; shared locations do not grant movement ownership. Native responses stay bound to the signed-in account/server and clear old rows during refresh or failure. There are no ledger mutation actions. Posting, sample-ledger snapshots and offline replay retain their existing behavior; the G09 report/export remains separate.

All 391 Flutter tests and 68 focused backend regression tests (637 assertions) passed. Scoped Dart analysis, Pint, scoped diff checks and Android compilation passed. iOS compilation remains pending.

G07-09 Locations adds an online native register with search/active filters, pagination, draft maintenance, parent hierarchy, type/subtype, contact, territories, signed coordinates, image selection/review, approval submission and guarded single/bulk deletion. Detail opens scoped read-only stock and parent-owned address maintenance using the existing PIN/address editor.

Admin and mobile share scoped choices, transactions, number generation, revisions and approval locks. Hierarchy writes reject self/descendant cycles; contact linking cannot transfer another record's contact. Inaccessible existing territory links are retained. Images are validated and read through a private authorized endpoint. Locations remain outside the optional inventory toggle; their stock relation keeps its own module/read requirements. The approval dispatcher and setup now support Locations, including My Approvals decisions, rejection/resubmission and audited approved overrides. A dedicated Admin address manager removes account/company side effects. Linked locations/history cannot be deleted. Native retries retain identity, permissions are checked before replay and stale editors require reload. Existing sample-ledger snapshots and offline replay retain their behavior.

All 396 Flutter tests and 73 focused backend regression tests (638 assertions) passed. The final 11 location checks (134 assertions) also passed, including Admin retention of inaccessible existing territory links. Scoped Dart analysis, Pint, scoped diff checks and Android compilation passed. iOS compilation remains pending.

G08-01 Employees adds an online native directory with search, active/deleted and employment filters, date ranges, sorting, pagination, personal/profile maintenance, approval submission, guarded delete/restore and bulk actions. Related pages cover employment, professional tax, statutory IDs, qualifications, skills, shifts, week offs, banks and assigned positions. Admin and native use the same scoped choices, transactions, revisions, lifecycle and child-record service.

Employee HR codes remain separate from database IDs; user linkage remains employees.login_id. Login linking cannot claim a user already assigned to another employee. Provisioning is explicit, permission-gated and sends credentials after commit. Profile images use a private scoped endpoint. Shift overlap, week-off duplicates, statutory uniqueness, division/OU compatibility, reporting cycles and primary-position exclusivity are validated. Existing primary OUs and inaccessible assignments are retained. Employee approval is supported by setup/dispatcher; pending and approved locks apply, with audited overrides. Protected employee/employment changes use HrChangeService and remain inactive while approval is pending. Related section grants guard sensitive data and writes. Deleted employee records stay reviewable, linked logins/HR history prevent deletion, and restore keeps the same identity. Employees remain outside the optional attendance/leave toggle. Offline capture and attendance/punch/leave identities retain their existing behavior.

All 412 Flutter tests passed. The backend regression passed 136 tests; its two failures were corrected (a missing fixture type and a boolean assertion), and the final 22 employee tests (175 assertions) plus the affected import test (26 assertions) passed. Scoped Dart analysis, Pint, scoped diff checks and Android compilation passed. iOS compilation remains pending.

A release Android APK including Employees was built on 23 November 2026 and retained at `C:/Herd/grahak_field/build/app/outputs/flutter-apk/shatyainfo-employees.apk`.

G08-02 Positions adds an online native register with search, status and organizational/classification filters, territory/OU multi-selection, sorting, pagination, creation, detail, editing, approval submission and guarded single/bulk deletion. Shared Admin/API fields cover reporting hierarchy, territories/HQ, location, division/OUs, job role/classification and assigned employees. Admin now has a registered view page.

Both clients share scoped choices, transactions, revisions and approval locks. HQ must belong to the final territories; reporting cycles and cross-division units are rejected. Inaccessible existing assignments and employee primary-position flags are retained. Employee assignment changes require employee read/update permissions and unlocked records; reporting synchronization follows the final assignments. Actual pending requests lock maintenance; approved changes require audited overrides. Legacy default-pending rows without a workflow request remain editable drafts. Position approval setup, routing, decisions, rejection/resubmission and outcome updates are supported. Employee assignments, reporting dependants, territory reporting links and expense configurations prevent deletion. Positions stay outside the optional attendance/leave toggle; field capture and offline replay retain their existing behavior.

All 419 Flutter tests and 114 focused backend tests passed across the regression run and final reruns. The final 12 position checks (103 assertions) cover Admin approval locking, legacy drafts and reporting synchronization; the updated hierarchy form check also passed (9 assertions). Scoped Dart analysis, Pint and scoped diff checks passed. The Android release APK including Positions was built successfully on 24 November 2026 and retained at `C:/Herd/grahak_field/build/app/outputs/flutter-apk/shatyainfo-positions.apk` (88.3 MB). iOS compilation remains pending.

G08-03 Org Units is skipped at the user's direction. M10 Settings & Administration remains excluded. Live role, company and device acceptance stays deferred; iOS compilation remains open.

## HR overlap review and Overdue Follow-ups - 24 November 2026

Reviewed G08-04 and G08-06-08 against Attendance, Leave and My Approvals:

| Item | Existing native flow | Remaining parity work |
| --- | --- | --- |
| G08-04 Daily Attendances | Punch and monthly attendance for the linked employee (`employees.id`). | Team/Admin register, manual maintenance, punch logs and recalculation. Admin `ViewOwn` dereferences a missing employee; Recalculate lacks explicit authorization and the resource has no view route. These are review findings, not fixes in this delivery. |
| G08-06 Leave Cancellations | Submit cancellation and display latest state in Leave. Assigned approve/reject decisions already use My Approvals and the shared HR handler. | Scoped cancellation administration/history list. My Approvals is the current assigned inbox, not this historical register. |
| G08-07 Leave Applications | Linked-employee list, apply, half-day and cancellation; assigned review decisions in My Approvals. | Team/Admin register, substitute-user selection and permitted lifecycle administration. Applicant uses `employees.id`; substitutes/reviewers use `users.id`. |
| G08-08 Leave Balances | Leave type choices display the linked employee's calculated closing balance. | Balance register and administrative maintenance, with calculator/payroll reconciliation. |

All four remain partial. Existing self-service flows are retained. G09-12 Overdue Follow-ups is prioritized as the next new native feature.

G09-12 now provides an online read-only register under Reports / All Reports, authorized by `View:OverdueFollowUps` and shared `FollowUp` visibility. Search, visible-assignee choices, inclusive due-date filters, oldest-due-first pagination and fetch time are available. The next follow-up date takes precedence, falling back to the original date only when absent. Only open rows due before today appear. All overdue dates appear initially. Admin uses the same effective-due filtering without its former default month range, preventing older overdue work from disappearing and preventing opposite bounds matching separate date fields.

Native reads stay bound to account/server, clear displayed rows during refresh/failure and receive private/no-store responses. The report exposes no mutation controls. The initial delivery left native export and direct Follow-up handoff open; the continuation below closes both gaps.

Verification: the first backend pack passed 40 tests (279 assertions). Fourteen focused Flutter tests passed, including overdue filters, pagination, refresh failure, account changes, Attendance/Leave and navigation. Scoped Dart analysis found no issues. Required Pint passed. The final backend pack passed 13 tests (115 assertions), including the Admin filter regression, HR approvals, leave balances and attendance calculation. Android compilation was not rerun for this delivery; the retained Positions APK predates this report.


### G09-12 export and Follow-up handoff continuation

Native Export CSV saves the full filtered result through the existing Android/iOS document dialog. It requires both report access and `Export:Reports`, repeats the same record/date/employee/search constraints as the register and is independent of pagination. Exports over 10,000 rows require narrower filters. CSV retains original/effective dates, assignee, related type, contact, status, priority and interaction, preserves quotes and escapes formula-like cells. No export is retained on the server. Account/server changes prevent handing downloaded bytes to the save dialog; cancellation does not report success.

Each report row advertises Follow-up access separately. Open follow-up re-fetches the existing record, checks the current module destination, opens its established read-only detail or edit form and refreshes page one with the same report filters on return. Completing or rescheduling work removes it from the overdue result. Report-only permission does not grant Follow-up reads or writes. Existing Follow-up policies, visibility and approval locking continue to govern changes. Android and iOS filename allowlists now recognize `overdue-follow-ups.csv`; the report remains read-only itself.

The backend pack passed 10 tests (82 assertions), including all-page filtered export, CSV quoting/formula handling and independent export/report/Follow-up permission revocation. Scoped Dart analysis found no issues and required Pint passed. All 45 focused Flutter tests passed, including export/save cancellation, current detail/edit handoff, access revocation, completion and report refresh, account changes and existing Follow-up regressions. Android release compilation passed. The final APK is retained at `C:/Herd/grahak_field/build/app/outputs/flutter-apk/shatyainfo-overdue-follow-ups.apk` (88.3 MB), alongside the earlier Positions build. G08 review findings, skipped Org Units, excluded M10, deferred live role/company/device acceptance and open iOS compilation retain their prior status.
