# Mobile parity working state

Updated 16 November 2026. ERP at `C:/Herd/it-erp`, Flutter at `C:/Herd/grahak_field`. Use the [delivery plan](mobile-parity-plan.md#10-remaining-delivery-plan--16-november-2026), [inventory](mobile-parity-inventory.md) and [API contract](mobile-parity-contract.md) for detailed scope and evidence.

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

## CRM carryover

Lead and Deal create/edit, scoped account and contact pickers, Lead territory selection, Lead conversion, Notes creation, Items read access, lead-scoped Follow-ups and Activity pagination shipped. Conversion requires a visible lead, `Update:Lead` and `Create:Deal`. It preserves the account type and code.

M01-G04-03c Activity pagination keeps rows when a later page fails, retries that page and resets to page one on refresh. The focused Follow-up and Activity file passed 33 tests. Remaining M04 work includes Notes pagination, contact-owned addresses, Lead lifecycle actions, read-only Follow-up detail, standalone Follow-up and Activity registers, and their supported actions. See [Sales & CRM inventory](mobile-parity-inventory.md#p01-g04--sales--crm-inventory).
