# Mobile navigation contract

15 November 2026. Schema version 1. The registry is `App\Services\Mobile\MobileNavigationRegistry`. The Flutter cache is `MobileNavigationCache`. The endpoint is `GET /api/v1/mobile-navigation`.

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

Item keys are the inventory IDs `g01-01` through `g09-15`. Schema 1 may emit only these screen keys, and only for the item keys listed here:

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
| `e-detailing` | `g06-03` | E-detailing |
| `rcpa` | `g06-04` | RCPA |
| `sgip` | `g06-07` | Visit SGIP |
| `sales-performance` | `g04-09` | Sales performance |

Any other permitted inventory item is omitted until its screen exists. Flutter opens only a screen key in this table. An unknown key is shown in development as unavailable and cannot be opened. A menu record never creates a screen.

`GET /me` continues to own `field_planning`, `optional_modules`, and `company_launch`. This payload does not grant create, update, delete, approval, export, or per-record actions.

## Permission mapping

An item is included only when all of these pass:

1. Its key is in `g01-01`–`g09-15` and its screen is in the table above.
2. The Admin page or resource would register navigation for this user. Use the existing `canAccess()`, `shouldRegisterNavigation()`, and `ViewAny:{permission key}` checks. Do not invent a parallel role list.
3. `OptionalModuleService::navigationEnabled()` passes. Inventory stock, ledger, transfers, and adjustments stay hidden while the inventory module is disabled. Items, locations, purchase orders, receipts, and challans are outside that module list. CRM, expenses, and the listed HR resources follow the same service.
4. Page conditions already in source still apply, including `supports_mtp` and `supports_flexible_plans`.

`super_admin`, `administration_admin`, and `AccessAllRecords` keep their current record-scope bypass. `AccessAllRecords` does not add menu items or action permission. Hiding a menu is not authorization. Each later read or write repeats the existing policy and record scope.

Report pages currently allow any authenticated user when `View:{page}` is absent. Keep that behaviour only until those permissions exist. Do not copy it onto other groups.

## Refresh, cache, and errors

Fetch this route after login, after a successful `/me` session restore, when the app resumes online, and after a 401 or 403 from any mobile API call. Cache the body per server, `context.company_key`, and `context.user_id`.

On a network error, reuse that cache only inside the existing seven-day offline session and only for the same server, company, and user. A 401 follows the current logout and reauthentication path. Pending field evidence stays queued. A 403 on a resource refetches navigation and removes destinations that are no longer returned. A 403 on this route clears the cached menu. Neither response deletes queued evidence or replays it for another user.

A missing route, missing `schema_version`, non-array `groups`, or any malformed body produces no menu. The client must not open every screen as a fallback. `schema_version` greater than the app supports shows an upgrade-required screen and retains pending work. The current app, which does not call this route, keeps using `/me`.

## Test cases

- The example user receives the three groups in order and does not receive `settings`.
- Disabling the inventory module removes `g07-02`, `g07-06`, `g07-07`, and `g07-08` only.
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
