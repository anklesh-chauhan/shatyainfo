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
| `sgip` | `g06-07` | Visit SGIP |
| `sales-performance` | `g04-09` | Sales performance |

Unbound inventory items are omitted. The table preserves the legacy schema 1 contract. New clients request `GET /api/v1/mobile-navigation?destinations=standalone`. The server can add `standalone_screen_key` alongside the original `screen_key`. A client validates both against the item key. Internal record links continue to use the original screen keys. A newer client on a legacy server uses the legacy bindings until the server supports the request.

| Item | Standalone destination | Read and record actions |
| --- | --- | --- |
| G01-02 | `punch` | Attendance Today and existing Punch in/out actions. A Punch-only sign-in opens Modules. |
| G02-03 | `visit-register` | `GET /api/v1/visits` lists the caller's Visits. A row opens the existing Visit workspace. |
| G02-06 | `expense-register` | `GET /api/v1/dcr-expenses` lists expenses on the caller's DCRs. A row opens that report's expense workflow. Today's shortcut opens the same workflow for today's DCR. |
| G06-03 | `e-detailing-library` | Published catalog and versioned file preview through `/api/v1/e-detailing`. Recording remains in the Visit workspace. |
| G06-07 | `sgip-register` | `GET /api/v1/sgip-distributions` lists the caller's distributions attached to their Visits. A row opens the existing Visit SGIP workflow. |

The three registers return paginated source rows and use the same permission and module checks as their linked workflows. Broader team registers and new resource actions belong to their delivery waves. A menu route is checked against the specific item key on refresh, including entries that share a legacy screen key.

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

## Account action capabilities

The paginated `GET /api/v1/accounts` response includes top-level `can_create`. Account responses include record-specific `data.can_update` on detail. Flutter treats missing flags as false. The existing policies and record visibility still authorize direct writes. Global Search opens the same detail view. Account writes validate changed related records and explicit assignments. Retained assignment values can accompany an edit to other fields.

## Visit document and reporting access

Visit workspace responses include `can_create_quote`, `can_create_sales_order` and `can_update_sales_order`. New offline orders require both Sales Order create and update. The order catalog and draft edits require Sales Order update. Linked documents, field order state and summaries include only records the caller can read. A document link supplies an editor URL only when the caller can update the record. Linked DCR and expense details require the corresponding report access. The server rechecks authorization for direct requests and queued writes.

## DCR action capabilities

DCR payloads return an additive `data.capabilities` object with `submit`, `create_expense`, `update_expense` and `delete_expense`. The values represent authorization. The app still checks report state, dates, pending work and other workflow conditions. The server authorizes each request and checks permissions again before returning a replayed DCR or expense result. Field reporting uses the signed-in representative’s DCR. Admin uses the existing territory, organizational unit and record visibility rules.

## Existing Sales & CRM action contract

Paths are relative to `/api/v1`. These actions are separate from navigation eligibility.

| Resource | Shipped contract | Remaining mobile work (M04) |
| --- | --- | --- |
| Leads | `GET/POST /leads`, `GET/PATCH /leads/{lead}`, `GET /leads/options`. Options supply `can_create`; records supply `can_update` and `can_convert`. `POST /leads/{lead}/convert` requires visible lead, `Update:Lead`, `Create:Deal` and eligible state. It preserves account type/code and only returns full Deal details if visible. | Contact-owned address selection, custom fields and supported lifecycle/related actions including delete. Lead approval is a recorded web-only exception. |
| Lead children | `GET /leads/{lead}/activities` and `GET /leads/{lead}/notes` are paginated; Flutter pages Activity but only loads the first Notes page. Notes read follows Lead visibility; `POST /leads/{lead}/notes` requires Lead update. `GET /leads/{lead}/items` requires visible Lead and `ViewAny:ItemMaster`; read-only. Activity also requires `ViewAny:LeadActivity`. | Notes pagination; standalone Activity register and supported writes; supported item and address relationship actions. |
| Follow-ups | `GET/POST /follow-ups`, `GET/PATCH /follow-ups/{followUp}`, `GET /follow-ups/options`. Native lead-scoped list pages results and uses `can_create` / `can_update`. The API also supports list queries without a lead filter. | Standalone register, read-only native detail, delete and approval including supported bulk actions. |
| Deals | `GET/POST /deals`, `GET/PATCH /deals/{deal}`, `GET /deals/options`. Options supply `can_create`; records supply `can_update`. Incoming conversion is owned by the Lead endpoint. | Contact-owned address selection and supported lifecycle actions including delete. Deal approval is a recorded web-only exception. |
