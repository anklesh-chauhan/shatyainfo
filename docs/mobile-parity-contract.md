# Mobile navigation contract

16 November 2026. Schema version 1. The registry is `App\Services\Mobile\MobileNavigationRegistry`. The Flutter cache is `MobileNavigationCache`. The endpoint is `GET /api/v1/mobile-navigation`.

Endpoint: `GET /api/v1/mobile-navigation`, inside the existing Sanctum and `FieldIdempotency` group. Login and `GET /api/v1/me` stay unchanged. The current Flutter build must keep working when this route is absent.

Mobile groups, in order: `workspace`, `field`, `customers`, `sales`, `secondary`, `marketing`, `inventory`, `hr`, `reports`. The web Settings & Administration group is never included. A `settings` group in a response is discarded.

## Exact response example

`company_key` is `tenant:{id}` when a company is current and `landlord` on the landlord host. The example below is one tenant response.

```json
{
  "schema_version": 1,
  "context": {
    "user_id": 42,
    "company_key": "tenant:1"
  },
  "home": "todays-tour",
  "groups": [
    {
      "key": "workspace",
      "label": "My Workspace",
      "sort": 10,
      "items": [
        {
          "key": "g01-03",
          "label": "Today's Visits",
          "sort": 30,
          "screen_key": "todays-tour"
        },
        {
          "key": "g01-06",
          "label": "Sales Assistant",
          "sort": 60,
          "screen_key": "sales-assistant"
        }
      ]
    },
    {
      "key": "field",
      "label": "Field Operations",
      "sort": 20,
      "items": [
        {
          "key": "g02-01",
          "label": "Tour Plans",
          "sort": 10,
          "screen_key": "mtp"
        }
      ]
    },
    {
      "key": "customers",
      "label": "Customers & Territories",
      "sort": 30,
      "items": [
        {
          "key": "g03-01",
          "label": "Accounts",
          "sort": 10,
          "screen_key": "accounts"
        }
      ]
    }
  ]
}
```

`home` is `todays-tour` when that screen is present. Otherwise it is `modules`. Empty groups are omitted. Items are ordered by `sort`.

## Keys and screens

Schema 1 registers 16 inventory item keys across 14 screen keys. Only the pairs below are supported; additional inventory IDs do not extend schema 1.

| Screen key | Item keys | Existing Flutter entry |
| --- | --- | --- |
| `todays-tour` | `g01-02`, `g01-03` | Today's Tour, including punch |
| `sales-assistant` | `g01-06` | Sales Assistant |
| `mtp` | `g02-01` | Monthly tour plans |
| `flexible-planning` | `g02-02` | Flexible planning |
| `visit` | `g02-03` | Visit workspace |
| `dcr` | `g02-04`, `g02-06` | DCR and its expenses |
| `field-coverage` | `g02-07` | Field coverage |
| `accounts` | `g03-01` | Accounts |
| `leads` | `g04-01` | Lead list, detail and forms |
| `deals` | `g04-04` | Deal list, detail and forms |
| `e-detailing` | `g06-03` | E-detailing |
| `rcpa` | `g06-04` | RCPA |
| `sample-requests` | `g06-05` | Sample Requests |
| `sample-issues` | `g06-06` | Sample Issues |
| `item-masters` | `g07-01` | Items |
| `stock-on-hand` | `g07-02` | Stock on Hand |
| `purchase-orders` | `g07-03` | Purchase Orders |
| `goods-receipt-notes` | `g07-04` | Goods Receipts (GRN) |
| `delivery-challans` | `g07-05` | Delivery Challans |
| `inventory-transfers` | `g07-06` | Stock Transfers |
| `inventory-adjustments` | `g07-07` | Stock Adjustments |
| `stock-ledger` | `g07-08` | Stock Ledger |
| `location-masters` | `g07-09` | Locations |
| `employees` | `g08-01` | Employees |
| `positions` | `g08-02` | Positions |
| `sgip` | `g06-07` | Visit SGIP |
| `sales-performance` | `g04-09` | Sales performance |

Unbound inventory items are omitted. The table preserves the legacy schema 1 contract. New clients request `GET /api/v1/mobile-navigation?destinations=standalone`. The server can add `standalone_screen_key` alongside the original `screen_key`. A client validates both against the item key. Internal record links continue to use the original screen keys. A newer client on a legacy server uses the legacy bindings until the server supports the request.

| Item | Standalone destination | Read and record actions |
| --- | --- | --- |
| G01-02 | `punch` | Attendance Today and existing Punch in/out actions. A Punch-only sign-in opens Modules. |
| G02-03 | `visit-register` | `GET /api/v1/visits?register=1` opens the personal register and advertises team review where available. Active personal rows open the existing Visit workspace. Team and trash rows open the register summary. |
| G02-06 | `expense-register` | `GET /api/v1/dcr-expenses?register=1` opens the personal and authorized team register. Today's shortcut opens the existing owner expense workspace. |
| G06-03 | `e-detailing-library` | Published catalog and versioned file preview through `/api/v1/e-detailing`. Recording remains in the Visit workspace. |
| G06-07 | `sgip-register` | `GET /api/v1/sgip-distributions` lists the caller's distributions attached to their Visits. A row opens the existing Visit SGIP workflow. |

The registers return paginated source rows and enforce the relevant permission and module checks. A menu route is checked against the specific item key on refresh, including entries that share a legacy screen key.

In the negotiated response, a registered child of an Admin cluster can include `parent: {key, label, sort}`. HR uses `employee-management`, `attendance` and `leave-management`. Reports uses `all-reports`. Labels and sorts come from Admin. The server uses Admin's `-1` for unset sorts and registration order for ties. Flutter preserves the server sequence when sort values tie. It renders a parent heading only when at least one child destination is available.

`GET /me` continues to own `field_planning`, `optional_modules`, and `company_launch`. This payload does not grant create, update, delete, approval, export, or per-record actions.

## Permission mapping

An item is included only when all of these pass:

1. Its key/screen pair is in the schema 1 table above.
2. The Admin page or resource would register navigation for this user. The service checks `shouldRegisterNavigation()`, then resource `canViewAny()` or page `canAccess()`. The Lead resource and API both check list access. The Punch item additionally requires an active linked employee. Today’s Visits and Sales Assistant use explicit page permissions.
3. `OptionalModuleService::navigationEnabled()` passes. Inventory stock, ledger, transfers, and adjustments stay hidden while the inventory module is disabled. Items, locations, purchase orders, receipts, and challans are outside that module list. CRM, expenses, and the listed HR resources follow the same service.
4. Page conditions already in source still apply, including `supports_mtp` and `supports_flexible_plans`.

`super_admin`, `administration_admin`, and `AccessAllRecords` keep their current record-scope bypass. `AccessAllRecords` does not add menu items or action permission. Hiding a menu is not authorization. Each later read or write repeats the existing policy and record scope.

No report is registered in schema 1. Admin report pages require an assigned page permission or an elevated administration role. Account Reach & Frequency retains its AccountMaster list gate. `AccessAllRecords` alone grants neither report page nor Workspace access.

## Refresh, cache, and errors

Fetch this route after login, after a successful `/me` session restore, when the app resumes online, and after a 401 or 403 from any mobile API call. Cache the body per server, `context.company_key`, and `context.user_id`.

On a network error, reuse that cache only inside the existing seven-day offline session and only for the same server, company, and user. A 401 follows the current logout and reauthentication path. Pending field evidence stays queued. A 403 on a resource refetches navigation and removes destinations that are no longer returned. A 403 on this route clears the cached menu. Neither response deletes queued evidence or replays it for another user.

A missing route, missing `schema_version`, non-array `groups`, or any malformed body produces no menu. The client must not open every screen as a fallback. `schema_version` greater than the app supports shows an upgrade-required screen and retains pending work. Older clients that do not call this route keep using `/me`. The current client calls both routes.

## Test cases

- The example user receives the three groups in order and does not receive `settings`.
- Schema 1 emits no Inventory entries. When Inventory destinations are introduced, verify the module hides stock on hand, transfers, adjustments and stock ledger as defined by the module service.
- A user with no eligible screens receives `groups: []` and `home: "modules"`.
- `AccessAllRecords` does not add an update action or a settings item.
- A malformed body and a response without `schema_version` grant no destinations.
- Schema version 2 on this app shows upgrade-required and keeps the pending queue.
- HTTP 401 uses the existing reauthentication path and keeps the queue.
- HTTP 403 on accounts refetches navigation and drops `accounts` when it is absent.
- A network error restores only the matching server, company, and user cache within seven days.
- Switching user or company cannot display the previous menu or queue.
- An unknown `screen_key` is not opened.
- Login and `/me` responses remain valid for the current Flutter build.

## Dashboard

The negotiated menu can include G01-01 with screen key `dashboard`. The API requires `View:Dashboard` through the same business-page access rule as Admin.

- `GET /api/v1/dashboard/overview?period=last_7` returns the selected period, allowed period labels, chart catalog, saved chart keys and scoped series for the selected charts. Allowed periods are `today`, `last_7`, `this_month` and `last_30`. Each series pairs chart labels with numeric values.
- `PATCH /api/v1/dashboard/preferences` accepts a list of chart keys in `widgets`. An empty list hides all charts. A null value clears the override and restores the catalog defaults. Changes affect the signed-in user’s Admin and native Dashboard.

`GET /api/v1/dashboard/workspace?view=personal` returns the selected view, `can_view_team` and authorized counts. A request for `view=team` requires the field management gate or Dashboard and My Approvals access with at least one current assigned step. Team operational counts also require resource permissions and use the caller’s visible hierarchy, subject to existing record-scope bypass. A reviewer without field management receives only their approval count. Missing count fields represent unavailable metrics. The workspace summary is independent of the visual report period.

The overview also returns `setup_reminder` for an eligible company administrator while setup is incomplete and the user has not dismissed it. `setup_url` opens that company’s Admin checklist. `DELETE /api/v1/dashboard/setup-reminder` stores the same per-user dismissal used by Admin. Remind me later only hides the card for the current app sign-in.

## My quick links

Negotiated navigation advertises `quick_links: true`. Authenticated `GET /api/v1/mobile-quick-links` returns options, selected menu item keys and the available limit. `PATCH` saves distinct ordered keys within the current mobile catalog and preserves other stored entries. Admin and mobile share up to eight links. A null selection restores shared defaults.

## My Approvals

The negotiated menu can expose G01-04 as `my-approvals`. `GET /api/v1/my-approvals` returns 25 current assigned steps per page. `GET /api/v1/my-approvals/{step}` returns the assigned step’s review summary. Lead and Deal approvals remain web-only.

`POST /api/v1/my-approvals/{step}/decision` accepts `decision: approve|reject` and requires nonblank `comments` on rejection. The server checks Dashboard-independent My Approvals access, assignment and the exact current step under the approval workflow lock. An unrelated step returns 404. An assigned step that is stale, already decided or out of order returns 409. The app reloads the inbox after a decision.

## Approval Register

Negotiated navigation exposes G01-05 as `approval-register` for users with Approval list permission. `GET /api/v1/approval-register` returns 25 records per page for the requester or a reviewer on the workflow, subject to existing record visibility bypass. The optional `status` filter accepts approval statuses. `trash=active|with|only` defaults to active. `GET /api/v1/approval-register/{approval}` additionally requires record view permission and returns the selected review steps, activity, and allowed lifecycle actions.

`POST /api/v1/approval-register/lifecycle` accepts `action: delete|restore|forceDelete` and one to 25 distinct approval IDs. Multiple records also require the corresponding bulk permission. Each record must be visible, have the individual action permission, and be in the required trash state. Approved records also require `OverrideApproval:Approval`. The entire batch is checked under lock before mutation. Permanent deletion removes the approval and its child history. Requests originate in their owning module, and decisions remain in My Approvals. Lead and Deal approvals remain web-only.

## Monthly Tour Plans team view

`GET /api/v1/monthly-tour-plans/options` returns `can_view_team` when the actor can browse existing team plans or start an eligible delegated plan. Authorized creators receive `team_employees` from the Admin position hierarchy. IDs are `users.id`. Add `scope=team` to the monthly list or plan detail to use Admin record visibility. Both requests require `ViewAny:SalesTourPlan` and `View:SalesTourPlan` with monthly planning enabled. The team list excludes the caller’s own plans.

Use `GET /monthly-tour-plans/options/employees/{employee}` for delegated creation options and `GET /monthly-tour-plans/{plan}/team-options` for a scoped editable plan’s options. Delegated creation sends `scope: "team"` and `user_id`; team updates and submissions send `scope: "team"` and the latest revision. The team record advertises `can_edit_team` and `can_submit_team`. Decisions remain in the assigned approval inbox. Existing clients continue to use the personal `can_edit` capability and payload.

`POST /monthly-tour-plans/register-actions` accepts an `action` of `submit` or `delete`, a `scope` of `personal` or `team`, and one to 50 `plans` with distinct `id` and `revision` pairs. Submit uses `can_submit_from_register`, applies the shared workflow per plan, and returns `completed`, `skipped`, and the first error when applicable. Delete uses `can_delete` and soft-deletes the selection in a single transaction after validating access, state, and revisions.

### Flexible Planning team review

`GET /api/v1/flexible-plans` defaults to the signed-in user’s week. Callers with the existing Field Team management grant can supply another company user’s `user_id`. With `include_team=1`, those callers receive `team_review.hqs` and `team_review.representatives`, including each representative’s current `hq_id`. The other representative’s week is read-only. `can_act_on_intentions` is true only for an eligible personal week when flexible planning is enabled and the caller can create visits.

Flutter keeps other representatives’ weeks and the picker options online. The existing personal cache stays scoped to the signed-in user and company.

### Visits register

`GET /api/v1/visits?register=1` returns a paginated register with document number `search`, `approval_status`, and `trash` filters. `scope=team` applies Admin Visit visibility to visits belonging to other representatives. That scope supports `employee_id` using `users.id` and supplies visible representative choices. An unqualified `GET /api/v1/visits` still returns the legacy owner list.

`GET /api/v1/visits/{visit}/register` uses the same `scope` to return a summary. Team and trashed visits use this read-only route. Personal capture, photos, child routes, and offline replay keep their existing owner checks.

`POST /api/v1/visits/register-actions` accepts `scope`, an `action` of `delete`, `restore`, or `forceDelete`, and up to 50 distinct `ids`. Each action requires its record policy. Bulk restore and permanent deletion also require their bulk permissions. The online transaction checks each selected draft, its linked evidence, and its DCR and recovery state before applying any change.

### Daily Call Reports register

`GET /api/v1/dcrs` lists existing personal reports by default. `scope=team` uses Admin DCR visibility for other representatives. It accepts `user_id` from `users.id`, `from`, `to`, `approval_status`, `trash`, and `page`. It advertises the team filter, today’s report shortcut, and bulk capabilities. Navigation negotiates `dcr-register` for G02-04. Older apps retain the `dcr` destination and the existing today flow.

`GET /api/v1/dcrs/{dcr}/register` uses the same scope for read-only report summaries. Visit and expense lines enforce their own read permissions and visibility. Expense lines also require the expense module. A current My Approvals step may return `dcr_scope` to link to a report the reviewer can already read. Owner capture, expenses, and submission retain their owner-only endpoints and offline contracts.

`POST /api/v1/dcrs/register-actions` accepts `scope`, `action` (`delete`, `restore`, or `forceDelete`), and up to 50 distinct report `ids`. The transaction checks every record’s policy, draft state, reporting window, evidence and pending sync state. Restore checks for an active report for the same representative and date. Bulk restore and permanent deletion require the corresponding bulk permissions.

### Travel Segments register

`GET /api/v1/travel-segments` paginates segments on visible parent DCRs. It accepts `sales_dcr_id`, `search` for DCR number, customer or town, `origin` for manual, derived or imported, and `trash`. `GET /api/v1/travel-segments/{segment}` uses the same parent scope and includes revision and action capabilities. Negotiated navigation advertises G02-05 only when the caller has the travel list and detail permissions.

`GET /api/v1/travel-segments/options` searches paginated choices by `kind`. Visit, plan detail, patch, customer, town and transport choices take `sales_dcr_id`. Customer choices may also use `patch_id`. Manual saves use `POST /travel-segments` or `PATCH /travel-segments/{segment}` with server-validated relationships and `distance_km`. Creates support an `Idempotency-Key`. Updates require the current `revision` and recheck the source and destination DCR if reassigned.

`POST /api/v1/travel-segments/lifecycle` accepts `delete`, `restore`, or `forceDelete` and up to 50 distinct IDs. All selected rows must be manual and have editable parent DCRs. Per-record action permissions apply, with the corresponding bulk permission for multi-row restore and permanent deletion. Generated and workbook-imported rows are visible for review.

### DCR Expenses register

`GET /api/v1/dcr-expenses?register=1` accepts `scope`, `trash`, `origin`, `sales_dcr_id`, `search`, and `page`. Personal scope uses the caller’s DCRs. Team scope requires visibility of both the other representative’s DCR and the expense. `GET /api/v1/dcr-expenses/{expense}` uses the same scope and returns the expense revision and available actions. Personal detail can include receipt names and IDs for the existing authenticated owner download. An unqualified list request still returns the legacy owner payload.

`GET /api/v1/dcr-expenses/options` returns paginated `reports`, `types`, or `modes`. Report choices respect the selected scope, approval state, and reporting window. `POST /api/v1/dcr-expenses` supports an `Idempotency-Key`. `PATCH /api/v1/dcr-expenses/{expense}` requires the current revision. An eligible manual expense can move directly between two writable DCRs in the chosen scope, with both totals updated in the same transaction. Every expense requires a parent DCR.

`POST /api/v1/dcr-expenses/lifecycle` takes `scope`, an action of `delete`, `restore`, or `forceDelete`, and up to 50 distinct IDs. The transaction validates the entire selection before changing rows. Multi-row restore and permanent deletion require the corresponding bulk permission. Moves and permanent deletion protect expenses with receipts, line items, or offline history. `POST /api/v1/dcr-expenses/recalculate` takes `scope` and `sales_dcr_id` and runs the shared calculation on an eligible DCR. Register actions are online. The owner workspace retains offline entry and receipt synchronization.

### Customer Coverage

`GET /api/v1/field-coverage` defaults to the signed-in representative and the current month. All reports require `ViewAny:AccountMaster`. Reading another representative’s month requires the existing Field Team management grant. With `include_team=1`, eligible callers receive top-level `team_review.hqs` and `team_review.representatives`. Each representative includes their current `hq_id`. An optional `hq_id` on a report request must match the selected `user_id`’s current HQ. Admin and mobile use the same permission, selectors, and monthly calculation.

`data.patches` and each customer’s `patch_ids` come from the representative’s assignment intervals for the selected month. Search, patch and status filters narrow the customer rows while the summary still covers the selected representative’s full month. Customer detail shows the report’s assignment and call figures. Team reports and selectors stay online. Personal snapshots remain scoped to the signed-in account and month and are available for seven days on network failure.

## Account action capabilities

The paginated `GET /api/v1/accounts` response includes top-level `can_create`. Account responses include record-specific `data.can_update` on detail. Flutter treats missing flags as false. The existing policies and record visibility still authorize direct writes. Global Search opens the same detail view. Account writes validate changed related records and explicit assignments. Retained assignment values can accompany an edit to other fields.

`GET /api/v1/accounts?register=1` adds trash, specialty, therapy area and lifecycle status filters to search, type, territory and patch. `GET /api/v1/accounts/{account}?register=1` allows a scoped trash detail. The legacy list and detail continue to show active records. Register results include per-record lifecycle actions and applicable bulk permissions. `POST /api/v1/accounts/register-actions` accepts `delete`, `restore`, or `forceDelete` and up to 50 distinct account IDs. It checks the full batch under lock. Permanent deletion is limited to accounts without linked records or business history. Trashing an account preserves its linked contacts and addresses for restore.

`GET /api/v1/accounts/register-options` includes filter choices. `GET /api/v1/accounts/owner-options` provides searchable, paginated company users to callers with Field Team management and account list access. The same grant is required to send `assign_owner_id` on an authorized account create or update. Account ownership changes use the existing assignment history. The existing `owner_id` response field remains read-only.

Account reporting uses the existing `reporting-options` and account `PATCH` contract for classification, lifecycle status, division profiles and call targets. Assignment history is read-only. Account contacts can be created, edited, attached and unlinked through the nested `/accounts/{account}/contacts` routes. The contact selector uses the existing scoped contact list. `POST /api/v1/accounts/{account}/related-actions` accepts `group`, `action` and up to 50 distinct related IDs. It supports unlink for addresses and contacts and delete for addresses, contacts, bank, credit, GST and statutory records. It validates membership for the whole batch and protects contacts with other links. Account approval submission depends on a supported outcome handler. The current approval engine has no handler for `AccountMaster`.

## Contacts register

`GET /api/v1/contacts?register=1` adds company filtering and register capabilities to the existing paginated contact search. Negotiated navigation binds `g03-02` to `contacts` when the caller has contact list access. `GET /contacts/{contact}` and `PATCH /contacts/{contact}` recheck the Admin contact visibility scope and record policies. `POST /contacts` creates a standalone contact. Profile updates can omit unchanged fields, including values that predate the current create requirements.

`GET /contacts/options/{kind}` searches scoped `companies`, `designations`, or `departments`. `POST /contacts/options/{kind}` accepts `name` for designation and department creation with the matching create permission. Company creation uses the Companies workflow. Contact address create, update and delete use `/contacts/{contact}/addresses` and its numeric child paths. The server checks the owning contact and derives mapped geography from the selected PIN lookup. An existing address without an area can retain its legacy geography.

`POST /contacts/register-actions` accepts one to 50 distinct `ids` for deletion. The transaction validates visibility, permissions, approval state and linked records for the entire selection. The legacy contact picker returns compact rows through `GET /contacts`. Account attach and unlink retain their account-specific contract. Contact submission depends on a shared approval outcome handler, which is currently unavailable for Contacts.

## Companies register

Negotiated navigation binds `g03-03` to `companies` for callers with company list access. `GET /api/v1/companies` returns visible companies with search and pagination. `GET /companies/{company}`, `POST /companies` and `PATCH /companies/{company}` use the Company policy. `GET /companies/options/industries` provides paginated industry choices within the caller’s industry permissions and visibility. Company responses include linked account details when the caller can view that account.

`POST /contacts/options/companies` uses the inline company form rules and requires company creation and contact create or update access. It returns a scoped company option for the contact form. The contact stores the link when the contact form is saved. The two operations use their respective resource permissions.

`POST /companies/{company}/addresses` and `PATCH` or `DELETE /companies/{company}/addresses/{address}` resolve only company-owned addresses. `POST /companies/{company}/address-order` takes the full ordered list of current address `ids`. Company-owned addresses retain their parent type. Account addresses stamped with the company ID remain accessible through their account parent. Company profile edits keep the linked account independent.

`POST /companies/register-actions` takes one to 50 distinct company `ids` for deletion. The transaction checks visibility, action permissions, approval locks and external references for the entire batch. Company approval submission depends on a shared outcome handler, which is currently unavailable for Companies.

## Territories register

Negotiated navigation binds `g03-04` to `territories` for callers with Territory list access. `GET /api/v1/territories` supports `search`, `status`, `parent_territory_id`, `type_master_id`, `trash` (`current`, `all`, `trashed`) and pagination. Detail, create and edit use `GET /territories/{territory}`, `POST /territories` and `PATCH /territories/{territory}`. The API checks the Territory policy and record scope. The Sales Targets, planning and visit projections serve their existing workflows separately.

`GET /territories/options/{kind}` returns paginated `parents`, `types`, `regions`, `divisions`, `positions` or `city-pin-codes`. The shared city and PIN catalog supports matching by city, area or PIN. Other choices use the corresponding visibility rules and permissions. Parent options accept the edited `territory_id` to exclude the record and its descendants. Creates require `city_pin_code_ids` and require `division_ids` when divisions are enabled. Edits preserve associations outside the caller’s visibility and preserve division links when divisions are disabled. The server validates hierarchy changes before saving.

`POST /territories/register-actions` accepts one to 50 distinct `ids` for soft deletion. It validates the entire selection and protects approval-locked territories and territories with live hierarchy, assignment, or field and sales links. `GET /territories/{territory}/patches` and `PATCH /territories/{territory}/patches/{patch}` apply Patch permissions and visibility within the selected territory. Edits can change the name, code, city or area, color and description. Territory approval submission requires a shared outcome handler.

## Patches register

Negotiated navigation binds `g03-05` to `patches`. `GET /api/v1/patches` supports search, `territory_id`, the `trash` states `current`, `all` and `trashed`, and pagination. Detail, create and update use `GET /patches/{patch}`, `POST /patches` and `PATCH /patches/{patch}`. `GET /patches/options/territories` applies position assignments or division fallback. `GET /patches/options/territories/{territory}/pin-codes` returns linked city and PIN choices. `POST /patches/register-actions` takes `action: trash` and up to 50 IDs. It checks each patch and rejects the entire batch if a patch is out of scope, approval locked, or linked to customer or field records.

`GET /patches/{patch}/customers` returns scoped assignments with search, type filter, sequence and distance. The adjacent `customer-types` and `customer-options` routes list filter choices and unassigned eligible customers. `POST /patches/{patch}/customer-actions` supports `attach`, `attach_all`, `detach` and `sequence`. The sequence action takes one assigned customer ID and a positive `sequence_no`, or null to clear it. Eligibility requires a Customer account or immediate subtype and an address in the patch PIN or, when the patch has no PIN, in its territory. The server checks the caller’s patch and account permissions and revalidates each selected account.

Company admins with Patch access can download `GET /patches/import/template` and send a multipart `file` to `POST /patches/import/preview`. Mobile accepts up to 5,000 rows and 10 MB in the Patches and Patch Customers sheets. Geography must already be linked to the territory. The preview returns a company- and user-scoped token when all rows pass. `GET /patches/import/latest` resumes the latest workbook, `GET /patches/import/{token}` returns preview pages or progress, and `POST /patches/import/{token}` queues the reviewed workbook. The database worker rechecks scope, permissions, approvals and the preview fingerprint before applying changes atomically.

## HQ & Customer Assignments

Negotiated destination `g03-06` uses screen key `field-team-assignments` under Customers & Territories. All endpoints require `Manage:FieldTeam`, `super_admin`, or `administration_admin` in the current company.

- `GET /field-team-assignments/representatives` lists company users. `GET /field-team-assignments/representatives/{representative}` returns the current HQ, its eligibility and form capabilities.
- `GET /field-team-assignments/representatives/{representative}/hqs` lists active HQs eligible for that user. Formal MTP requires a territory link to one of the user’s positions.
- `GET /field-team-assignments/customers` requires Account update permission and uses Admin account visibility. All three selectors accept `search` and `page`, and return `id` and `label`.
- `POST /field-team-assignments/hqs` creates an active HQ from `name` with Location Master create permission. The location number series generates its code. Formal MTP requires creating and linking HQs in Admin.
- `POST /field-team-assignments` accepts `user_id`, `location_master_id`, `account_ids` (up to 500 distinct IDs), and `calls_per_month` (0–1000). In Direct DCR an omitted or null HQ preserves the current assignment. HQ and selected customer changes are transactional. The result returns `hq_id` and `assigned_customers`. The workflow is online.

## Territory Sales Assignments

Negotiated destination `g03-07` uses screen key `territory-sales-assignments` under Customers & Territories. History requires `View:SalesTargets` or `Manage:SalesTargets`, with the same territory scope as Admin. Selectors and writes require `Manage:SalesTargets`.

- `GET /territory-sales-assignments` accepts territory-name `search` and `page`. Rows are newest first and return `id`, `territory`, `representative`, `effective_from`, nullable `effective_to`, and `reason`. The response also provides pagination and `register.can_assign` and `register.today`.
- `GET /territory-sales-assignments/territories` and `GET /territory-sales-assignments/representatives` accept `search` and `page` and return `id` and `label`. The territory selector is scoped. The representative selector lists users in the current company.
- The existing `POST /territory-sales-assignments` accepts `territory_id`, `user_id`, `effective_from` in YYYY-MM-DD format, and `reason`. The shared service appends the assignment and closes the earlier period atomically. The first entry can be historical. A transfer must start today or later and after the latest assignment, with a different representative. Reads and writes require a connection.

## Visit document and reporting access

Visit workspace responses include `can_create_quote`, `can_create_sales_order` and `can_update_sales_order`. New offline orders require both Sales Order create and update. The order catalog and draft edits require Sales Order update. Linked documents, field order state and summaries include only records the caller can read. A document link supplies an editor URL only when the caller can update the record. Linked DCR and expense details require the corresponding report access. The server rechecks authorization for direct requests and queued writes.

## DCR action capabilities

DCR payloads return an additive `data.capabilities` object with `submit`, `create_expense`, `update_expense` and `delete_expense`. The values represent authorization. The app still checks report state, dates, pending work and other workflow conditions. The server authorizes each request and checks permissions again before returning a replayed DCR or expense result. Field reporting uses the signed-in representative’s DCR. Admin uses the existing territory, organizational unit and record visibility rules.

## Existing Sales & CRM action contract

Lead and Deal address selectors use `GET /contacts/{contact}/addresses` when a contact is selected. It requires `ViewAny:ContactDetail`, `View:ContactDetail`, and visibility of the contact. It returns only that contact’s active addresses, including legacy `contact_detail_id` links. When there is no contact, the forms use the selected account’s addresses. Lead and Deal writes continue to validate address ownership against the selected contact or account.

Paths are relative to `/api/v1`. These actions are separate from navigation eligibility.

| Resource | Shipped contract | Remaining mobile work (M04) |
| --- | --- | --- |
| Leads | `GET/POST /leads`, `GET/PATCH /leads/{lead}`, `GET /leads/options`. Options supply `can_create`; records supply `can_update`, `can_convert` and `can_delete`. `POST /leads/{lead}/convert` requires visible lead, `Update:Lead`, `Create:Deal` and eligible state. It preserves account type/code and only returns full Deal details if visible. `DELETE /leads/{lead}` and `POST /leads/bulk-delete` soft delete visible leads. Bulk requires 1–50 distinct `ids`. Both return `data.deleted` and check `Delete:Lead` and approval override where required before applying changes. | Custom fields and remaining related actions. Lead approval is a recorded web-only exception. |
| Lead children | Flutter pages Activity and Notes using `page`, `per_page`, and the response’s `links.next`. Notes read follows Lead visibility on every page. `POST /leads/{lead}/notes` requires Lead update and mobile reloads the first page on success. `GET /leads/{lead}/items` requires a visible Lead and `ViewAny:ItemMaster`. Lead-scoped Activity also requires `ViewAny:LeadActivity`. | Supported Activity writes, plus supported item and address relationship actions. |
| Lead Activities | Negotiated `g04-03` opens `lead-activities` under Sales & CRM with CRM enabled and `ViewAny:LeadActivity` plus `View:LeadActivity`. `GET /lead-activities` uses Admin Activity visibility and accepts `search`, inclusive `from` and `to` dates (YYYY-MM-DD), `page`, and `per_page`. Lead metadata and Lead search are scoped to visible Leads. The register and `/leads/{lead}/activities` expose list `can_create` and row `can_update` and `can_delete`. Both support POST, PATCH and DELETE, plus POST to `/bulk-delete` with 1–50 distinct IDs. Deletes return `data.deleted`. Register creation requires a visible Lead and an available user from `GET /lead-activities/users`. Timeline creation uses the parent Lead and authenticated user. | New approval submission requires a Lead Activity outcome handler. Live acceptance pending. |
| Follow-ups | Negotiated `g04-02` opens `follow-ups` under Sales & CRM with `ViewAny:FollowUp` and `View:FollowUp`. The standalone register uses `GET /follow-ups` with `search` and `page`. Lead-scoped lists also send `lead_id`. Standalone search includes interaction, outcome, contact names, Lead reference, media, result, priority and status. List and detail return `can_update` and `can_delete`. Standalone creation uses `can_create` and `can_select_lead` from `GET /follow-ups/options` and the scoped Lead picker. `DELETE /follow-ups/{followUp}` and `POST /follow-ups/bulk-delete` return `data.deleted`. Bulk accepts 1–50 distinct `ids`. The full selection must pass Follow-up visibility and delete permissions. Approved records require an audited override. | Approval submission requires a Follow-up outcome handler. |
| Deals | `GET/POST /deals`, `GET/PATCH/DELETE /deals/{deal}`, `GET /deals/options`, and `POST /deals/bulk-delete`. Options supply `can_create`. Records supply `can_update` and `can_delete`. Bulk delete accepts 1–50 distinct IDs and validates the entire selection before soft deleting. Both delete endpoints return `data.deleted`. Deleting legacy approved records requires an audited override. Incoming conversion is owned by the Lead endpoint. | New Deal approval submission requires an outcome handler. Live acceptance pending. |
| Quotes | Negotiated `g04-05` opens `quotes` with Sales CRM enabled and both Quote read permissions. `GET/POST /quotes`, `GET/PATCH/DELETE /quotes/{quote}`, plus `GET /quotes/options`, `/quotes/items`, `/quotes/sales-people`, and `/quotes/filter-options/{field}`. The register supports search, date, status, customer, contact, salesperson, sent and amount filters. Writes use linked customer/contact/addresses and calculate pricing and GST on the server. Line quantities support up to three decimal places. `POST /quotes/actions` accepts `delete` and `submit` for 1–50 distinct IDs, and `sales_order` or `sales_invoice` for one ID. Conversion requires target create permission and applicable approval. `GET /quotes/{quote}/pdf` serves a private preview or an attachment with `download=1`. Approvers decide using My Approvals. | Live acceptance pending. Mobile terms editing uses plain text. Visible linked orders can open the Sales Orders register detail. Visible linked invoices can open Sales Invoice detail. |
| Sales Orders | Negotiated `g04-06` opens `sales-orders` with Sales CRM enabled and both Sales Order read permissions. `GET/POST /sales-orders`, `GET/PATCH/DELETE /sales-orders/{id}`, and the same `options`, `items`, `sales-people`, and `filter-options/{field}` subroutes as Quotes. Search also matches the order reference. Writes validate the customer, its contact and addresses, and support delivery and confirmation dates and quantities with up to three decimal places. Pricing, discounts, GST and totals are calculated on the server. `POST /sales-orders/actions` supports `delete` and `submit` for 1–50 distinct IDs and `sales_invoice` for one order. Orders with lines convert after required approval and with Invoice create permission. Repeat conversion returns the existing invoice link when visible. PDF preview and download use `GET /sales-orders/{id}/pdf` with optional `download=1`. | Live acceptance pending. My Approvals handles decisions. Pending, approved, delivered and invoiced orders are locked against register edits and deletion. Orders recorded offline remain editable through the Visit workflow while eligible for replay. Conversion finalizes that draft for future replay. Order capture and order-to-invoice conversion create documents. Stock posting remains in the delivery workflow. |
| Sales Invoices | Negotiated `g04-07` opens `sales-invoices` with Sales CRM enabled and both Sales Invoice read permissions. `GET/POST /sales-invoices`, `GET/PATCH/DELETE /sales-invoices/{id}`, and the shared sales document options, item, salesperson and filter selectors. The editor handles due date, payment status/date, reference, and priced lines with GST. `POST /sales-invoices/actions` accepts `delete` and `submit` for 1–50 distinct IDs. PDF preview and download use `GET /sales-invoices/{id}/pdf` with optional `download=1`. Visible links connect quotes, orders and invoices. | With Inventory enabled, Invoice read access and Delivery Challan create permission, eligible invoices expose `GET /sales-invoices/{id}/delivery-locations` and `POST /sales-invoices/{id}/delivery-challans`. Draft creation requires an `Idempotency-Key`, active dispatch location, date and invoice line quantities. Existing drafts reduce available quantities. Posting remains in Admin and rechecks invoice and line ownership, quantities and approval under lock. Live acceptance pending. |
| Prices & Discounts | Negotiated `g04-08` opens `customer-prices` with Sales CRM enabled and both Customer Price read permissions. `GET /customer-prices` supports search, customer and item filters and pagination. `POST /customer-prices` creates a price. `GET/PATCH/DELETE /customer-prices/{id}` use the pricing record and customer scopes. `GET /customer-prices/options/{type}` supplies searchable, paginated customer and tenant-catalog choices for writers. Price is non-negative. Discounts range from 0 to 100 percent, with up to two decimal places. `POST /customer-prices/bulk-delete` checks 1–50 distinct IDs before soft deletion. | One active price per customer and exact item or variant. Deleted rows remain in history and a replacement receives a new ID. Quotes, sales orders and sales invoices use the active exact-item override when pricing new lines and preserve stored line prices on edits for the same customer and item. Offline Visit order replay uses its existing capture pricing rules. Existing approvals protect changes, with audited overrides for legacy approved rows. New approval submission requires a Customer Price outcome handler. Live acceptance pending. |
| Territory Sales Targets | `g04-09` opens Sales Performance with `View:SalesTargets` or `Manage:SalesTargets`. `GET /sales-targets` includes current and historically visible targets, a revision number per row, and current management capabilities. `GET /sales-targets/territories` lists currently scoped territories. `POST /sales-targets` requires management permission and a first-of-month start, positive value in INR lakhs, positive base-unit quantity, and planning reason. Same-type periods cannot overlap for a territory. `PATCH /sales-targets/{target}` revises value, quantity and reason with the current revision number. The server locks the target and checks present territory scope and that the period has yet to start. | Writes and management controls use live access. The existing seven-day account-scoped fallback serves target and achievement reads on network failure. `GET /sales-targets/{target}/achievement` continues to use verified net secondary sales. Primary invoices and RCPA estimates stay separate. Live acceptance pending. |
| Sales Statements | Negotiated `g05-01` opens `sales-statements`. `GET /secondary-sales` returns paginated participant statements and import capability. `GET /secondary-sales/{id}?register=1` returns action capabilities and a summary. `GET /secondary-sales/{id}/evidence/{section}` pages through `rows`, `balances` or `adjustments`. Original downloads use the private `/source` endpoint. | `/stockists` yields scoped stockists and their territory. `/reviewers` supplies qualified reviewers. `/ocr-options` uses the selected stockist, territory, month and reviewer to find matching sources owned by the uploader. CSV/XLSX imports use the existing multipart route and accept both templates up to 5 MB. Approval requires a review note and verified adjustment IDs. Approved statements can start a reversal or replacement through `/secondary-sales/{id}/corrections`. Live acceptance pending. |
| Corrections | Negotiated `g05-02` opens `sales-corrections`. `GET /secondary-sales/corrections` lists participant requests. `GET /secondary-sales/corrections/{id}` returns the reason, statement statuses and totals, review capability and recorded decision evidence. `/evidence/{section}` pages through replacement `rows`, `balances` or `adjustments`. `/source` returns the private corrected file with `Cache-Control: private, no-store`. Both evidence routes resolve the replacement from the scoped correction. | Read access requires a register permission and requester or assigned correction reviewer scope. The assigned reviewer uses `POST /secondary-sales/corrections/{id}/review` with a note and verified replacement adjustment IDs for approval. The server applies the reversal or replacement through the existing ledger transaction and retains the released keys. Live acceptance pending. |
| OCR Sources | Negotiated `g05-03` opens `ocr-sources`. `GET /secondary-ocr?register=1` lists participant sources and upload capability. `GET /secondary-ocr/{id}?register=1` returns extraction status, confidence, text, mapping and action capabilities. The original is downloaded through `/file` with `Cache-Control: private, no-store`. | `POST /secondary-ocr` accepts multipart PDF, PNG or JPEG up to 5 MB. The uploader uses `POST /secondary-ocr/{id}/retry` for an unmapped failed, waiting or abandoned attempt. Retries require configured executables and replace the extraction token. A human maps the scan through `POST /secondary-sales` with the matching stockist, territory, month and reviewer, a structured file and a mapping note. Live acceptance pending. |
| Marketing Campaigns | Negotiated `g06-01` opens `marketing-campaigns`. `GET /marketing-campaigns` pages authorized campaigns by search, status and deleted-record filter. `GET /marketing-campaigns/{id}` returns visible configuration and action capabilities. `/options/{type}` provides scoped item, territory and scheme choices. | `POST /marketing-campaigns` assigns the campaign number. `PATCH /marketing-campaigns/{id}` sends a full configuration and revision. Quota writes preserve posted usage. `POST /marketing-campaigns/records/{action}` applies delete, restore or eligible permanent deletion to all selected records atomically. Live acceptance pending. |
| Promotional Schemes | Negotiated `g06-02` opens `promotional-schemes`. `GET /promotional-schemes` pages visible schemes by search, status and deleted-record filter. `GET /promotional-schemes/{id}` returns visible applicability, benefit rows and action capabilities. `/options/{type}` supplies scoped customer, territory and item choices. | `POST /promotional-schemes` creates a scheme. `PATCH /promotional-schemes/{id}` accepts full configuration plus its revision and preserves submitted benefit IDs owned by the scheme. `POST /promotional-schemes/records/{action}` applies record actions atomically. The API stores scheme configuration. Sales documents currently have no posting integration with the promotional scheme resolver. Live acceptance pending. |
| E-detailing Library | `GET /e-detailing` returns published material metadata and live management capability. `GET /e-detailing/manage` lists authorized materials by publication and search with presentation counts. `/manage/products` pages scoped product choices. `/manage/{id}/file?version=…` previews authorized current files using private, no-store caching headers. | Create uses multipart `POST /e-detailing/manage` with a PDF, JPG or PNG up to 10 MB. Metadata and publication use `PATCH /manage/{id}`. Replacement uses multipart `POST /manage/{id}/file`. Both writes check a revision. Field viewing, versioned offline preparation and visit recording reuse the existing endpoints. Live acceptance pending. |
| Sample Requests | Negotiated `g06-05` opens `sample-requests`. `GET /sample-requests` pages visible requests by search, status and deleted-record filter. Detail returns line quantities, the current revision and permitted actions. `/options/{type}` pages scoped employees, locations, territories, campaigns and classified items. | `POST /sample-requests` creates a draft and accepts an idempotency key. `PATCH /sample-requests/{id}` saves the draft with its revision. `POST /sample-requests/{id}/{action}` checks the revision for submit, approve, cancel, delete, restore and permanent deletion. Approval sends an `approved` map keyed by line ID. `POST /sample-requests/records/{action}` applies authorized trash actions atomically. Eligible requests return `issue_request_id` for the native issue editor. The issue URL remains available for older clients and requires Admin sign-in. Live acceptance pending. |
| Sample Issues | Negotiated `g06-06` opens `sample-issues`. `GET /sample-issues` pages visible issues with search, status and deleted-record filters. Detail returns issue lines, posting history, revision and permitted actions. `/options/{type}` provides scoped approved requests and active locations. `/requests/{id}` prefills remaining approved lines and destination. | `POST /sample-issues` creates an idempotent draft. `PATCH /sample-issues/{id}` requires the reviewed revision. `POST /sample-issues/{id}/{action}` supports post, delete, restore and permanent deletion with revision checks. `/records/{action}` applies trash actions atomically with separate bulk permissions. Admin and mobile use shared transactional saves/actions. Posting locks the issue, request and approved lines, validates current locations and remaining quantities, and records paired stock movements. Posted and cancelled issues are retained. All management and posting is online; SGIP ledger snapshots and replay remain unchanged. Live acceptance pending. |
| SGIP Distributions | Negotiated `g06-07` retains legacy `sgip` and opens the enhanced `sgip-register`. `GET /sgip-distributions` uses Admin record visibility with search, status, date and deleted-record filters. Detail returns items, values, violations, stock reservation/posting history, revision and permitted actions. `/options/{type}` pages scoped doctors, territories, campaigns, classified items and sample issues allocated to the owner. Editing choices accept `record_id`. Register access uses the SGIP policy independently of Visit permissions. | `POST /sgip-distributions` creates an idempotent draft for the linked employee of the signed-in user. `PATCH /sgip-distributions/{id}` requires the reviewed revision and retains visit-linked customer, territory and date. `POST /sgip-distributions/{id}/{action}` supports submit, approve/post, return to draft and draft trash actions. Submit, approve and return use the existing update permission; every action rechecks current scope and state. `/records/{action}` applies trash actions atomically with separate bulk permissions. Admin and mobile share saves and actions. Submission validates compliance and reserves owner-allocated stock. Returning to draft releases the reservation. Posting deducts stock and consumes campaign quota once, and rejects a changed reserved source. Repeated campaign item lines are summed for quota checks. Posted and other non-draft records cannot be trashed. Existing visit capture, ledger snapshots, offline evidence and recovery remain available. Live acceptance pending. |
| RCPA Observations | `GET /rcpa-observations?register=1` lists paginated, scoped estimates and review capabilities. `GET /rcpa-observations/{id}` returns read-only detail including competitor presence. `GET /rcpa-observations/due` returns the scoped rolling 7/14-day chemist list. | `GET /rcpa-observations/export` shares the Admin CSV format, uses private no-store caching headers and applies the register search and date filters across all matching rows. Due and export require RCPA business permission. The existing options, capture and offline replay remain authoritative for recording. Live acceptance pending. |


## Items register

Negotiated `g07-01` opens `item-masters`. `GET /item-masters` pages scoped root items by search, type and expired-only filter. Detail includes master fields, applicable taxes, approval state, revision and permitted related views. `/options/{type}` pages visible categories, brands, units, packaging, active taxes, unassociated items, supplier accounts and leads.

`POST /item-masters` creates an idempotent item with a server-generated code. `PATCH /item-masters/{id}` requires the reviewed revision. SKU uniqueness includes deleted items. Opening stock remains master metadata; item maintenance does not post stock. Item management stays outside the optional inventory module. Existing `/items`, `/order-catalog`, sample-ledger and offline caches retain their field behavior.

`POST /item-masters/{parent}/variants` creates a named variant using the parent item name. `/related/variants` lists current or deleted visible variants. Association accepts unassociated items without children, prevents self/cyclic parenting, and rechecks parent and child authority. Individual variant actions and atomic bulk actions support dissociation, deletion, restoration and permanent deletion. Items with stock or commercial history are retained. Parent deletion requires its variants to be removed or dissociated.

`/related/suppliers` and `/related/leads` require the related model's read permissions and visibility. Supplier attach/delete and lead attach/update/detach require an editable item; lead writes additionally require lead update permission and respect approval locks. Supplier creation/editing reuse the Account workflow. Deletion uses Account lifecycle permissions. Lead quantity and price are pivot values, independent of the Lead master. Relation mutations check the parent revision. `/related/stocks` is read-only and additionally requires enabled stock navigation, stock read permissions, stock visibility and location visibility.

Admin and mobile share transactional item and relation writes. Pending approvals lock maintenance; approved records require an override and record its user ID. Items are currently excluded from supported approval settings, so neither client can start a new item approval workflow. Existing approval history is retained. Live acceptance remains pending.

## Stock on Hand

Negotiated `g07-02` opens `stock-on-hand`. `GET /inventory-stocks` returns read-only balances with item code/name, variant, SKU, location, on-hand/reserved/available quantities, average cost, last movement and archived-master flags. Decimal quantities and costs retain their server precision. Lists page 30 rows and include `fetched_at`; responses use private, no-store caching. Search covers item and location metadata. Optional `item_master_id` and `location_master_id` filters intersect current visibility. `sort` accepts `item_code`, `item_name`, `location`, the three quantity fields, `average_cost` or `last_movement_at`; `direction` is `asc` or `desc`. Default order is latest movement first, null dates last, with ID as a stable tie-breaker.

`GET /inventory-stocks/options/{items|locations}` pages searchable choices drawn only from readable balances. Both stock read permissions and enabled inventory are required on direct reads and navigation. Admin, mobile and the item/location stock relations share stock creator/OU/bypass visibility intersected with location visibility. A shared location alone does not expose another creator's stock. System-created balances retain the existing stock scope rules. Archived master labels remain readable for eligible balances. There are no stock write endpoints or controls.

The native register stays bound to the signed-in account and server, loads online, and clears displayed balances during refresh and failed reads. Existing sample-ledger snapshots and offline capture/replay remain unchanged. Live acceptance remains pending.

## Purchase Orders

Negotiated `g07-03` opens `purchase-orders`. `GET /purchase-orders` pages visible purchase orders, latest order date then ID first, with `search`, `status`, `supplier_id`, inclusive `from`/`until` order dates and `page`. Both purchase-order read permissions are required for direct reads and navigation. `GET /purchase-orders/{id}` returns header fields, exact server totals, ordered/received/remaining line quantities, revision, editing capability and permitted actions. Receipt drafts/history additionally require receipt read permissions and receipt visibility. `/options/{suppliers|locations|items|payment-terms}` provides paginated search; suppliers are visible Vendor accounts, locations are visible active locations, and items are visible root items. Payment terms retain the existing shared configuration catalog.

`POST /purchase-orders` creates a draft with a server-generated number and supports an idempotency key. `PATCH /purchase-orders/{id}` requires the reviewed revision and full header/line configuration. Quantities allow three decimals, prices four, tax rates two (0-100). Existing line IDs must belong to this PO and cannot repeat; received quantities, line totals and header totals are server-owned. Empty lines may be saved as an unfinished draft. Submission and approval require lines. Admin and mobile share transactional validation and saves. Pending approval, approved, received and cancelled records remain locked.

`POST /purchase-orders/{id}/{submit|approve|cancel|create-grn|delete}` requires the reviewed revision. Submit uses the configured operational approval flow, including rejection/resubmission; decisions remain available through My Approvals. Approve PO requires update permission and is available only when approval is disabled. Cancel PO retains an editable draft/submitted/rejected document and locks it. Delete requires delete permission, an editable document and no receipt history. `/records/{submit|delete}` accepts 1-100 distinct IDs, validates the complete selection and acts atomically; deletion additionally requires `DeleteAny:PurchaseOrder`.

Create GRN requires PO update plus receipt create and read permissions. It checks current supplier/location/item availability, locks the PO and remaining lines, copies only unreceived quantities and original unit prices, and reuses an existing visible unposted draft receipt. It returns `goods_receipt_note_id`; native detail reviews receipt drafts/history. Receipt editing, approval and posting belong to G07-04. The handoff creates no stock movements. PO and GRN stay outside the optional inventory toggle, matching Admin. Native operations remain online and account/server-bound; create and receipt-action retries retain their operation identity. Live acceptance remains pending.

## Goods Receipts (GRN)

Negotiated `g07-04` opens `goods-receipt-notes`. `GET /goods-receipt-notes` pages visible receipts by latest receipt date then ID, with `search`, `status`, `supplier_id`, inclusive `from`/`until` receipt dates and `page`. Both GRN read permissions are required for direct reads and navigation. Detail returns header fields, PO reference, received quantities, unit costs, batch/lot and remarks, approval/posting state, poster, revision, allowed actions and receipt-specific stock movements. Posted receipts remain reviewable; archived master labels remain available.

`/options/{suppliers|locations|items|purchase-orders}` pages searchable scoped choices. Suppliers, active locations and root items use the shared purchasing catalogs. PO choices additionally require PO read permissions and visibility, approved/partially received state, completed configured approval and remaining quantity. `GET /goods-receipt-notes/purchase-orders/{id}` supplies the PO supplier/location and unreceived lines for native/Admin prefilling. Supplier/location must match the selected PO; every linked line must belong to that PO and match its item. Repeated PO lines for separate batches are allowed only within their combined remaining quantity. Standalone receipts cannot carry PO-line IDs. Drafts do not reserve PO quantities.

`POST /goods-receipt-notes` creates an idempotent draft with a server-generated number. `PATCH /goods-receipt-notes/{id}` sends the full header/line configuration and reviewed revision. Existing line IDs must belong to the receipt and cannot repeat. Quantities allow three decimals, costs four, batch numbers 255 characters, and remarks 2000. Empty drafts may be saved; submission/posting require lines. Admin and mobile share transactional saves and scoped selection validation. Revisions include receipt lines, approval state and current linked PO quantities, so another receipt posting invalidates an earlier review.

`POST /goods-receipt-notes/{id}/{submit|post|delete}` requires the reviewed revision. Submit uses the configured operational approval workflow; rejection permits correction/resubmission and decisions use My Approvals. Post to Stock explicitly requires GRN update permission and complete receipt/PO approval. It rechecks current selections, locks the receipt, PO and PO lines before validating remaining quantities, sums repeated lines, writes `grn_receipt` movements and updates PO received quantities/status in one transaction. Competing receipts cannot exceed the locked remaining quantity. Repeated posting has one stock effect. Pending/approved receipts cannot be edited or deleted; posted and cancelled receipts are retained. `POST /goods-receipt-notes/records/delete` validates 1-100 distinct IDs atomically and additionally requires `DeleteAny:GoodsReceiptNote`.

Purchase Order receipt cards now open the native GRN detail/editor. Creation/posting retries retain their operation identity. The workflow remains online and account/server-bound; existing sample-ledger snapshots and offline replay remain unchanged. GRN management stays outside the optional inventory toggle, matching Admin. iOS compilation and live acceptance remain pending.

## Delivery Challans

Negotiated `g07-05` opens `delivery-challans`. `GET /delivery-challans` pages visible challans by latest delivery date then ID, with `search`, `status`, `customer_id`, inclusive `from`/`until` delivery dates and `page`. Both challan read permissions are required for direct reads and navigation. Detail includes header and invoice reference, delivered quantities, unit costs, batch/lot and remarks, approval/posting state, poster, revision, permitted actions and outbound stock movement history. Posted challans remain reviewable and archived master labels remain available.

`/options/{customers|locations|items|sales-invoices}` pages scoped searchable choices. Standalone items use the shared root-item catalog; linked items must come from the authorized invoice. Dispatch locations are active and visible in the standalone register and the existing invoice handoff. `GET /delivery-challans/sales-invoices/{id}` prefills customer and available invoice lines. An optional `record_id` restores the current draft's allocation while editing and must identify a visible challan linked to that invoice. The dispatch location is selected independently. Invoice visibility/read permission, invoice readiness, CRM and inventory module checks remain authoritative for invoice-linked operations.

`POST /delivery-challans` creates an idempotent draft with a server-generated number. `PATCH /delivery-challans/{id}` sends the full header/line configuration and reviewed revision. Quantities allow three decimals, costs four; batch/lot accepts 255 characters and remarks 2000. Existing line IDs must belong to this challan and cannot repeat. Empty unfinished drafts may be saved. Linked customer/item/line IDs must match the selected invoice; standalone lines cannot retain invoice-line IDs. Repeated invoice lines for separate batches are summed. Existing draft allocations reduce available invoice quantity; validation excludes the current draft while editing, and draft deletion releases its allocation. Revisions include invoice quantities and linked draft allocations.

`POST /delivery-challans/{id}/{submit|post|delete}` requires the reviewed revision. Submit uses the configured approval flow; rejected documents may be corrected/resubmitted and decisions use My Approvals. Posting explicitly requires challan update permission, completed challan/invoice approval, matching invoice lines and sufficient on-hand stock minus reservations. It locks the challan, invoice and invoice lines, writes outbound `delivery_challan` movements, increments delivered quantities and updates invoice delivery status atomically. A repeated post has one stock effect. Cancelled challans cannot post. Pending/approved/posted/cancelled documents cannot be edited or deleted. `/records/delete` validates 1-100 distinct IDs atomically and additionally requires `DeleteAny:DeliveryChallan`.

Admin and mobile share saves and lifecycle actions. Existing Sales Invoice draft creation remains available; linked challans now open the native editor/detail from the invoice. Standalone challan management remains outside the optional inventory toggle, matching Admin; the invoice handoff retains its module checks. Native operations stay online and account/server-bound. Draft creation and posting retries retain operation identity. Existing sample-ledger snapshots and offline replay remain unchanged. iOS compilation and live acceptance remain pending.

## Stock Transfers

Negotiated `g07-06` opens `inventory-transfers`. `GET /inventory-transfers` pages visible transfers by descending ID, with `search`, `status`, `item_master_id`, `from_location_master_id`, `to_location_master_id` and `page`. Search covers transfer number, remarks, item and both locations. Both transfer read permissions and the enabled inventory module are required for navigation and direct reads/writes. Detail includes exact quantity/cost, source/destination, approval/posting state, poster, revision, permitted actions and paired movement history. Archived master labels remain available on existing transfers.

`GET /inventory-transfers/options/{items|locations}` pages scoped searchable items (including variants) and active locations. `POST /inventory-transfers` creates an idempotent draft with a server-generated transfer number. `PATCH /inventory-transfers/{id}` sends the full item/location/quantity/cost/remarks configuration and reviewed revision. Source and destination must differ. Quantities allow three decimals, costs four and remarks 5000 characters. Admin modal saves and native saves share transactional validation, scoped choices and revision checks; client-supplied posting fields/status cannot change the lifecycle.

`POST /inventory-transfers/{id}/{submit|post|delete}` requires the reviewed revision. Submission uses the configured operational approval workflow; rejection permits correction/resubmission, and approval decisions remain in My Approvals. Posting explicitly requires transfer update permission, completed approval and currently available item/locations. It locks the transfer and both item/location balances in ascending location-ID order, rechecks on-hand minus reserved stock, writes `transfer_out` and `transfer_in`, and marks the transfer posted in one transaction. Failed or repeated posting cannot leave one-sided stock movements. Drafts do not reserve stock; competing drafts are checked against current availability when posted. Cancelled transfers cannot post. Pending/approved/posted/cancelled records cannot be edited or deleted. There are no transfer bulk actions.

Native management stays online and account/server-bound, with stable operation identities for create/post retries and a required reload after stale edits. Existing sample-ledger snapshots and offline replay remain unchanged. iOS compilation and live acceptance remain pending.

## Stock Adjustments

Negotiated `g07-07` opens `inventory-adjustments`. `GET /inventory-adjustments` pages visible adjustments by descending ID with `search`, `status`, `adjustment_type`, `item_master_id`, `location_master_id` and `page`. Search covers number, reason, remarks, item and location. Both adjustment read permissions and enabled inventory are required for navigation and direct reads/writes. Detail includes exact quantity/cost, reason, type, approval/posting state, poster, revision, permitted actions and movement history. Existing records retain archived master labels.

`GET /inventory-adjustments/options/{items|locations}` pages scoped root items and active locations. `POST /inventory-adjustments` creates an idempotent draft with a server-generated number. `PATCH /inventory-adjustments/{id}` sends full configuration and the reviewed revision. Type is `receipt`, `issue`, `increase` or `decrease`; quantity is positive with up to three decimals; unit cost is nullable or non-negative with up to four decimals. A nonblank reason is required (255 characters); remarks allow 5000. Omitting/clearing cost retains the existing current-stock-cost behavior. Admin and native saves share transactions, scoped choices and revisions; client status/posting fields cannot change the lifecycle.

`POST /inventory-adjustments/{id}/{submit|post|delete}` requires the reviewed revision. Submission uses the configured operational approval workflow; rejection permits correction/resubmission and decisions use My Approvals. Reason and current selections are checked again before submission/posting. Posting explicitly requires update permission and complete approval, locks the adjustment and stock balance, and writes `adjustment_{type}` once. Receipt/Increase add stock; Issue/Decrease remove stock within on-hand minus reserved quantity. A rejected outbound adjustment cannot alter the balance or leave a movement. Cancelled documents and blank reasons are also rejected by the posting service used by onboarding. Pending/approved/posted/cancelled records cannot be edited or deleted.

`POST /inventory-adjustments/records/delete` accepts 1-100 distinct IDs, requires `DeleteAny:InventoryAdjustment` plus each record's delete permission, and validates the complete scoped editable selection before deleting any record. Admin bulk deletion uses the same atomic guard. Native creation/posting retries retain operation identity; stale edits require reload. Management remains online and account/server-bound; existing sample-ledger snapshots and offline replay remain unchanged. iOS compilation and live acceptance remain pending.


## Stock Ledger

Negotiated `g07-08` opens read-only `stock-ledger`. `GET /inventory-movements` returns 30-row pages with `search`, `item_master_id`, `location_master_id`, `movement_type`, inclusive `from`/`until` dates (`YYYY-MM-DD`), `sort`, `direction` and `page`. Search covers item name/code, location name/code, movement type and remarks. Default order is movement time descending with an ID tie-breaker. Sort choices include item code/name, location, movement time/type, quantities in/out, balance after, unit cost and total value. Invalid types, dates, ranges and sort parameters are rejected.

Admin and API use `StockLedger`: both `ViewAny:InventoryMovement` and `View:InventoryMovement`, enabled inventory, movement creator/OU/bypass visibility intersected with location visibility. A shared location does not grant access to another creator's movements. Bypass grants retain the read and module requirements. `GET /inventory-movements/options/{items|locations}` returns searchable, paginated choices drawn only from visible movements, including archived master labels. Historical rows also retain archived item/location labels.

All twelve posting types are offered in Admin and native filters, including delivery challans, sample issue in/out and SGIP distributions. Responses include their labels, exact three-decimal quantities/balance, nullable four-decimal cost/value, movement time, source type/ID and remarks. Source metadata does not grant source-document access or expose additional document fields. `balance_after` is the stored historical value; filtered or backdated views do not recalculate it. There are no create, edit, delete, posting or export actions on this register. The G09 movement report/export remains separate.

Native reads are online, private/no-store, account/server-bound and display a fetch time. Loading or failed refreshes clear old rows; retry, search, filters and pagination reread the server. Existing posting paths, sample-ledger snapshots and offline replay remain unchanged. iOS compilation and live acceptance remain pending.


## Locations

Negotiated `g07-09` opens `location-masters`. `GET /location-masters` returns 30-row pages ordered by name/ID with `search` (name/code), `active` (`1`/`0`) and `page`. Navigation and direct reads require both location read permissions and creator/OU/territory/bypass visibility. Locations remain outside the optional inventory toggle. Detail includes the server number, name, description, parent, type/subtype, contact, territories, signed seven-decimal coordinates, active flag, image presence, approval state, revision and permitted actions. Linked labels respect their visibility; inaccessible existing territory links are retained during edits.

`GET /location-masters/options/{parents|types|subtypes|contacts|territories}` provides searchable, paginated scoped choices. Options accept `location_id`; subtype choices also accept `parent_type_id`. Parent choices exclude self/descendants and saves recheck the entire graph under a shared number-series lock. Types must belong to Locations; roots with children require a valid subtype. Territory choices require both territory read grants. Linking or unlinking a contact requires its read/update grants and visibility; contacts already owned by another record cannot be transferred by this editor, and pending/approved contact locks apply.

`POST /location-masters` creates an idempotent location with a server-generated number. `PATCH /location-masters/{id}` saves the full configuration with its reviewed revision. Coordinates are bounded to latitude ±90 and longitude ±180. Description and name allow 255 characters. Optional `image_base64` accepts actual JPEG/PNG/WebP images up to 2 MB and 4096 pixels per side; `remove_image` clears the association. Image paths cannot be supplied by the API. `GET /location-masters/{id}/image` returns image bytes only after scoped authorization, with private/no-store and nosniff headers. Admin uploads and native camera/gallery selection share the same image validation.

`POST /location-masters/{id}/actions/{submit|delete}` uses the reviewed revision. `POST /location-masters/records/{submit|delete}` checks the whole selection atomically; deletion requires `DeleteAny:LocationMaster` plus individual delete grants. Configured approval submission uses the existing workflow and My Approvals. Locations are now supported by the approval dispatcher and approval setup independently of inventory. Rejection permits correction/resubmission. Pending records stay locked; approved changes require `OverrideApproval:LocationMaster` and log the override. Location deletion retains records with children, contacts, addresses, stock/history, commercial, sample, SGIP or employment links. There is no force-delete route.

`GET /location-masters/{id}/related/stocks` reuses Stock on Hand permissions, optional-inventory gating and row visibility; it remains read-only. `GET /location-masters/{id}/related/addresses` requires address read grants and scopes addresses through the location's morph relation. Address create/update/delete also requires location update permission, the matching address grant, an unlocked parent/address and the current location revision. `POST /{id}/addresses`, `PATCH /{id}/addresses/{address}` and `DELETE /{id}/addresses/{address}` never accept another parent's address. Native editing reuses the existing address/PIN editor. Admin now has a location-specific address manager, avoiding account/company auto-linking and unsupported detach actions.

Native operations remain online and account/server-bound. Creation, actions and address retries retain their operation identity; stale editors require reload. Location mutation permissions are checked before idempotent responses are replayed. Loading and failed refreshes clear old register/relation data. Existing sample-ledger snapshots and offline replay remain unchanged. iOS compilation and live acceptance remain pending.


## Employees

Negotiated `g08-01` opens native `employees`. `GET /employees` returns 30-row pages with name/code/email/mobile search, active status, current/deleted/all records, gender, nationality, employment type/status, department, job title, grade, division, organizational units and inclusive hire/increment date ranges. Personal columns support ascending/descending sorting with an ID tie-breaker. Admin and API retain creator/OU/own-login/bypass visibility and require both `ViewAny:Employee` and `View:Employee`. Deleted records remain readable and marked as deleted, matching the Admin register. Employees stay outside the optional attendance/leave toggle.

`GET /employees/form` supplies personal-field metadata and capabilities. `GET /employees/{id}` returns personal details, approval state, revision, visible reporting-manager label, permitted actions and related section capabilities. `employee_id` is the unique HR code string; route IDs and child foreign keys use `employees.id`. User linkage remains `employees.login_id = users.id`. Creation and editing never change the identity used by punch, attendance or leave. Profile pictures are validated JPEG/PNG/WebP images up to 1 MB and 4096 pixels per side, supplied as `image_base64` or removed with `remove_image`. `GET /employees/{id}/image` authorizes the employee scope before returning private/no-store image bytes; stored paths are excluded from the API.

`POST /employees` creates personal details. `PATCH /employees/{id}` sends reviewed personal configuration with a revision. Server whitelists exclude approval/audit fields. HR codes, employee emails and mobile numbers remain unique across deleted records. Login selection requires user read/update grants and excludes users already linked to another employee, including deleted employees. Reassignment checks existing links and HR history. Login provisioning is an explicit opt-in requiring `Create:User`; it creates a new account and sends credentials after commit. An existing email account must be selected explicitly. Applying an approved HR change does not provision a login as an unrelated side effect.

`GET /employees/options/{countries|users|managers|departments|job-titles|grades|divisions|org-units|locations|positions|shifts}` returns scoped, searchable, paginated choices. The query accepts an employee database ID and selected division. New grade creation requires `Create:EmpGrade`. Reporting overrides reject self/circular chains and preserve the existing reporting service. Organizational units must belong to the selected division; inaccessible existing assignments are retained and an existing primary OU is preserved. Position writes maintain exactly one primary position and refresh derived reporting relationships.

`GET /employees/{id}/sections/{section}` returns paged, employee-owned records and field/action metadata for `employment`, `professional-tax`, `statutory`, `qualifications`, `skills`, `shifts`, `week-offs`, `banks` and `positions`. Each section requires its own model read grants. Create/update/delete also requires employee update permission, the corresponding child grant and unlocked employee/child records. `POST /{id}/sections/{section}`, `PATCH /{id}/sections/{section}/{row}` and `DELETE /{id}/sections/{section}/{row}` use the reviewed employee revision; IDs are always checked through the parent relationship. Single employment/statutory records are retained. Shift periods reject overlaps and reversed ranges; week offs reject duplicate days; statutory identifiers are unique. Bank account numbers remain text, preserving leading zeros; new bank forms default country/currency/status to India/INR/active. Position assignment and detachment preserve primary-position exclusivity. Related bulk deletion validates the complete selection atomically and requires the child bulk-delete grant. Admin uses the same section service, with parent-bound records and guarded actions.

`POST /employees/{id}/actions/{submit|delete|restore}` uses the current revision. Bulk submit/delete/restore checks every selected employee and the relevant bulk permission. Employee approval is now supported by the dispatcher and approval setup; decisions remain in My Approvals. Pending records lock editing, approved records require an audited override, and protected HR fields still follow HR-change approval. Deletion retains employees with linked logins, reporting dependencies or HR history; restore keeps the same database identity. There is no employee force-delete route.

`POST /employees/{id}/hr-changes/{employee|employment}` derives the target from the authorized employee, requires HR-change permissions, a reason and revision, and uses `HrChangeService`. Protected fields are read-only in ordinary editors when HR-change approval is enabled. Proposed values stay inactive until approval; rejected changes can be corrected/resubmitted under the existing service. Employee detail shows permitted recent change history. This employee-bound submission does not complete the separate G08 HR Change Requests register or its other target types.

Native reads/writes are online, account/server-bound and private/no-store. Create/action/section/HR-change retries retain their operation identity; write grants are checked before replaying cached responses. Stale editors require reload; refresh failures clear old directory/section rows. Existing offline capture, payroll/attendance calculations, sample workflows and employee import paths retain their established responsibilities. iOS compilation and live acceptance remain pending.


## Positions

Negotiated `g08-02` opens native `positions`. `GET /positions` returns 30-row pages with name/code/level search, active/inactive/vacant status, territory and organizational-unit multi-selection, location, reporting position, division, department, job title, grade and job role filters. Sort accepts name, code, level, status and creation/update time, with an ID tie-breaker. Admin and API require both `ViewAny:Position` and `View:Position` and share creator/OU/territory/bypass visibility. Positions remain outside the optional attendance/leave toggle.

`GET /positions/form` provides shared field descriptors and employee-assignment capability. `GET /positions/{id}` returns configuration, visible linked labels, revision and permitted actions. `GET /positions/options/{roles|parents|territories|locations|divisions|org-units|departments|titles|grades|employees}` supplies searchable, paginated scoped choices. Options accept `position_id`; organizational units also accept `division_ou_id`. Territory, location, employee and parent choices require their read grants. Employee labels retain the HR code while selection IDs refer to `employees.id`. Missing or inaccessible existing labels display a linked-selection placeholder.

`POST /positions` creates a draft; `PATCH /positions/{id}` saves the reviewed configuration with its revision. Both clients use `PositionRegister`. Codes stay unique across deleted records. The server validates reporting cycles under position-row locks, HQ membership in selected territories, division/OU compatibility and current selector scope. Existing inaccessible territory, organizational-unit and employee assignments are retained. The multi-territory flag follows the final territory set. Employee assignment changes require employee read/update grants, scoped employees and current employee approval locks; authorized approved-employee changes are audited. Existing primary-position flags are preserved. Primary selection remains in Employees. Reporting-manager synchronization runs after assignment changes.

`POST /positions/{id}/actions/{submit|delete}` requires the reviewed revision. `POST /positions/records/{submit|delete}` validates the complete selection atomically; bulk deletion requires `DeleteAny:Position` and individual delete permission. Position approval is supported by setup, routing and the outcome dispatcher. Decisions use My Approvals; rejection allows correction/resubmission. Actual pending requests lock maintenance, and approved edits/deletion require an audited override. Legacy default-pending rows with no approval request are treated as drafts. Assigned employees (including archived employees), subordinate positions, territory reporting links and expense configurations prevent deletion. There is no native restore or force-delete route. Admin now has a registered view page and the same guarded lifecycle actions.

Native management remains online, private/no-store and bound to the signed-in account/server. Failed retries retain operation identity, root permissions and scope are checked before replay, stale editors require reload, and loading or failed refreshes clear prior register data. Existing field capture and offline replay remain unchanged. Live acceptance and iOS verification remain pending.


### G09-12 Overdue Follow-ups register

Negotiated navigation adds `g09-12` / `overdue-follow-ups` under Reports / All Reports. Both reads require `View:OverdueFollowUps` (or the established elevated-role page bypass) and apply Admin's `FollowUp` record visibility.

- `GET /api/v1/reports/overdue-follow-ups`: optional `search` (100 characters), `from`, `until` (inclusive `Y-m-d` effective due dates), `user_id` and positive `page`. Returns 25 rows per page, `meta.current_page`, `meta.last_page`, `fetched_at` and server `today`.
- `GET /api/v1/reports/overdue-follow-ups/employees`: paginated `id` / `label` options drawn only from visible overdue rows; supports `search` and `page`.

Effective due is `next_follow_up_date`, otherwise `follow_up_date`. It must be before today. Completed, Cancelled, Canceled, Closed and Done statuses are excluded, as are trashed rows. No period default hides older work. Bounds constrain the same effective date in Admin and mobile. Results are ordered by effective due ascending, then ID. Rows include both original/next dates, assignee, related type, contact, status, priority and interaction. There are no report writes. API responses are private/no-store; Flutter reads check account/server identity before and after requests. Register metadata includes `can_export` (report access plus `Export:Reports`); row `can_open` requires existing Follow-up list/view policies. `GET /api/v1/reports/overdue-follow-ups/export` accepts the same filters, ignores page for export and streams all matches as UTF-8 CSV with private/no-store headers. Over 10,000 matches returns 422 with a request to narrow filters. Formula-like cells are escaped and CSV quotes/newlines are preserved. Export checks the current report and export grants independently; no server export artifact is retained. Native save uses the existing document dialog with `overdue-follow-ups.csv`; account/server identity is checked again before save.

Open follow-up also requires the current Follow-ups destination. It re-fetches `/follow-ups/{id}` and opens the existing edit form only when its current `can_update` permits it; otherwise the read-only detail is used. Reads and writes retain their independent policy/visibility checks. Returning refreshes page one using the selected report filters. Existing Admin export remains available.
