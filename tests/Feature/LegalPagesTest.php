<?php

it('serves each public legal document without review markers', function (string $document, string $heading) {
    $response = $this->get(route('legal.show', $document));

    $response->assertOk()->assertSee($heading);
    $response->assertDontSee('[VERIFY')->assertDontSee('[CONFIRM')->assertDontSee('Review fields');
})->with([
    ['service-agreement', 'Shatya Info Service Agreement'],
    ['data-processing-agreement', 'Shatya Info Data Processing Agreement'],
    ['privacy', 'Shatya Info Privacy Notice'],
    ['trial-terms', 'Shatya Info 30-Day Free Trial Terms'],
]);

it('offers a downloadable dpa', function () {
    $response = $this->get(route('legal.dpa.download'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

it('shows legal acceptance at company registration', function () {
    $this->get(route('signup.create'))
        ->assertOk()
        ->assertSee('name="legal_acceptance"', false)
        ->assertSee(route('legal.show', 'service-agreement'))
        ->assertSee(route('legal.show', 'privacy'));
});
