# Mobile parity working state

Updated 15 November 2026. ERP at `C:/Herd/it-erp`, Flutter at `C:/Herd/grahak_field`. Use the [plan](mobile-parity-plan.md), [inventory](mobile-parity-inventory.md), and [API contract](mobile-parity-contract.md) for scope and detailed evidence.

## Latest completed task

**M01-G04-03c Activity pagination.** Lead detail > Activity loads the next page on demand using the existing paginated endpoint. The default page size remains 50. Rows append in server order. A later-page failure keeps visible rows and retries the same page. Refresh starts at page 1 and discards superseded responses. The existing first-page 403, Retry, and empty states still work.

- Flutter: `lib/leads/lead_repository.dart`, `lib/screens/lead_activity_screen.dart`, `test/follow_up_test.dart`. ERP: inventory G04-03 and this handoff.
- `flutter test test/follow_up_test.dart --reporter expanded` passed **33 tests**, including all five Activity tests and the Follow-up capability regressions.
- `dart analyze lib/leads/lead_repository.dart lib/screens/lead_activity_screen.dart test/follow_up_test.dart` found no issues. Formatted those three Dart files. Full Flutter suite, Pest, and device acceptance were not run for this card.

## Completed work and decisions

- P00-P06 previously recorded complete. Flutter SDK `C:/Herd/sfa/flutter` (baseline Flutter 3.47.4, Dart 3.13.3). Laravel tests use MySQL `it_erp_testing` on port 3307. The Flutter tree was untracked at baseline.
- **Leads and Deals** (M01-G04-01, M01-G04-04): create/edit, scoped account and standalone contact pickers, Lead territory selection, and scoped Lead to Deal conversion shipped. Conversion requires a visible lead, `Update:Lead` and `Create:Deal`, and leaves the account type and code intact. Contact-owned address selection remains a gap. Admin conversion and selector issues are recorded in the inventory.
- **Lead Notes and Items** (M01-G04-01g through 01j): Notes read access follows visible lead access and creation requires `Update:Lead`. The app lists and adds notes. Items are read-only and use `ViewAny:ItemMaster` plus lead visibility.
- **Follow-ups** (M01-G04-02a through 02e): scoped API, native create/edit, and paginated lead history shipped. Initial load, Refresh, and successful save revalidate creation independently of list pages. The capability card passed 31 focused tests and targeted analysis.
- Admin owns menu order, authorization, and record visibility. Lead/Deal approval and Settings & Administration are approved web-only exceptions. Keep all other incomplete workflows as **Gap**.

## Next task and open gaps

1. **Notes pagination.** Make `LeadNotesScreen` and `LeadRepository.notes` page-aware using the existing endpoint. Preserve the composer, permission gate, and refresh after a successful save.
2. **Follow-up parity.** Read-only details, standalone cross-lead register, delete and approval (including bulk) remain **Gap**.
3. **Lead delete.** Review access before implementing the API (M01-G04-01k). Then continue the CRM queue with Quotes, Orders, Invoices, Prices, and Targets.

Activity standalone register and write actions remain **Gap**. Device acceptance remains open. Treat each workflow as a separate task card.
