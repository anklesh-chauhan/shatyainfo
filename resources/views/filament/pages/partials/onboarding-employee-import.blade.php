<section class="setup-import-card" aria-label="Employee import">
    <div class="setup-card-heading"><span class="setup-card-number">↑</span><div><h3>Import employees from Excel</h3><p>One template for employee details, positions, territory codes and reporting relationships.</p></div></div>
    <x-filament::button color="gray" wire:click="downloadExample" wire:loading.attr="disabled">Download Excel template</x-filament::button>
    <details class="setup-optional">
        <summary>How to prepare your employee file</summary>
        <p>Fill the Employees sheet. Keep employee codes unchanged when updating records.</p>
        <p>For monthly plans, enter territory codes for each position. Use reports_to_position_code for the manager’s position; that position must have one active employee.</p>
        <p>Read the template’s Instructions sheet for required fields and login setup. Preview the file, fix any errors, then select Confirm import.</p>
    </details>
    @include('filament.pages.partials.data-import-employees')
</section>
