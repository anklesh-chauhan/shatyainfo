Shatya Info is a pharma field-force ERP. Admin panel at `/admin` is the product. Sales/Tenant/Landlord panels exist but do not replace Admin.

## Domain spine

Tour plan → visit → DCR → travel segments / expenses / SGIP samples. Sales CRM is leads → deals → quotes/orders/invoices. HR is punch → daily attendance → leave. Inventory posts GRN, transfers, adjustments, sample issues, and SGIP deductions.

## Visibility and IDs

- `created_by` / `updated_by` are user IDs, never display names.
- Bypass scopes with `super_admin`, `administration_admin`, or `AccessAllRecords`.
- `Visit.employee_id` is `users.id`. `LeaveApplication.employee_id`, `LeaveInstance.employee_id`, and `DailyAttendance.employee_id` are `employees.id`.
- `User::employee()` is via `employees.login_id`. `Employee.employee_id` is the HR code string.

## Tests

- Pest + `RefreshDatabase`. MySQL database is `it_erp_testing`.
- Use `reportingUser`, `reportingShift` (`ShiftMaster::withoutEvents`), `reportingLeaveType`.
- Creating a `Visit` while authenticated attaches a DCR (`VisitObserver` + `DcrService`). Wrap Visit creates in `withoutEvents` only when that side effect is not under test.
- `ShiftMaster::created` inserts five setup rows. Tenant create drops databases — never run in Pest.

## Do not

- Join visit `employee_id` to `employees.id`.
- Put names in `created_by`.
- Treat `SampleInventoryFlowTest` GET `/` as inventory coverage.
