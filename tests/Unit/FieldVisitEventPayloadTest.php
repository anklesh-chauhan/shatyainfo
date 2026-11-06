<?php

use App\Http\Requests\Api\V1\StoreFieldVisitEventRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\Validator;

function mobileDetailsPacket(): array
{
    return [
        'action' => 'details',
        'target' => ['patch_id' => 14, 'detail_id' => 5, 'company_id' => 54],
        'payload' => [
            'remarks' => null,
            'feedback' => [
                ['answer' => 2, 'question_id' => 1],
                ['answer' => 3, 'question_id' => 2],
                ['answer' => 4, 'question_id' => 3],
                ['answer' => 5, 'question_id' => 4],
            ],
            'is_joint_work' => false, 'joint_user_ids' => [],
            'visit_outcome_id' => 2, 'visit_purpose_ids' => [1],
            'next_follow_up_date' => '2026-11-16',
        ],
        'event_id' => 'ea9c025b-40ff-4136-a794-ebf8c6d189c1',
        'captured_at' => '2026-11-05T18:26:31+00:00',
        'previous_event_id' => '889b8593-0389-4458-9646-2bef16dddcab',
    ];
}

function mobileDetailsValidator(array $packet): Validator
{
    $request = StoreFieldVisitEventRequest::create('/api/v1/field-visit-events', 'POST', $packet);
    $request->headers->set('Idempotency-Key', $packet['event_id']);
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $validator = $factory->make($packet, $request->rules(), $request->messages());
    $request->withValidator($validator);

    return $validator;
}

it('accepts the exact native mobile details packet without dropping joint work or feedback', function () {
    $packet = mobileDetailsPacket();
    $validator = mobileDetailsValidator($packet);
    expect($validator->passes())->toBeTrue()
        ->and($validator->validated()['payload'])->toBe($packet['payload']);
});

it('keeps strict validation of malformed mobile details fields', function (array $changes, string $field) {
    $packet = mobileDetailsPacket();
    $packet['payload'] = [...$packet['payload'], ...$changes];
    $validator = mobileDetailsValidator($packet);
    expect($validator->fails())->toBeTrue()->and($validator->errors()->has($field))->toBeTrue();
})->with([
    'scalar joint users' => [['joint_user_ids' => 'invalid'], 'payload.joint_user_ids'],
    'scalar feedback' => [['feedback' => 'invalid'], 'payload.feedback'],
    'invalid rating' => [['feedback' => [['question_id' => 1, 'answer' => 6]]], 'payload.feedback.0.answer'],
    'unknown field' => [['unrecognized_field' => true], 'payload'],
    'invalid manual time' => [['start_time' => 'yesterday'], 'payload.start_time'],
]);
