@if ($modules === [])
    <p>No supported modules are enabled in this company setup.</p>
@else
    <p>One approval level routes requests to the employee’s reporting manager.</p>
    <p>{{ implode(', ', $modules) }}</p>
@endif
