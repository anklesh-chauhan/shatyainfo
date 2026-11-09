<section class="setup-import-card" aria-label="Employee import">
    <div class="setup-card-heading"><span class="setup-card-number">↑</span><div><h3>Import employees from Excel</h3><p>One template for employee details, positions, territory codes and reporting relationships.</p></div></div>
    <details>
        <summary>Set up managers and correct imports</summary>
        <p>Give each manager a position with one active occupant. On each MR’s primary position row, enter that manager’s position code in <code>reports_to_position_code</code>. Managers can appear anywhere in the file. Use <code>reports_to_employee_code</code> to check that the position resolves to the intended employee.</p>
        <p>Preview the file, correct the listed rows and upload it again. Keep employee codes unchanged when correcting existing records. The same employee code can appear on multiple rows for different positions, with consistent personal details.</p>
        <p>After import, confirm managers have linked logins and the permissions they need. Configure DCR approvals separately in Reporting rules.</p>
    </details>
    @include('filament.pages.partials.data-import-employees')
</section>
