<?php

use App\ExpenseConfigurationMatcher;
use App\Models\SalesDcr;
use App\Models\Visit;
use App\Models\VisitPurpose;
use App\Services\Expense\BuildsExpenseContext;
use Illuminate\Database\Eloquent\Collection;

test('expense matching uses any selected visit purpose and excludes matching purposes', function () {
    $matcher = new class extends ExpenseConfigurationMatcher
    {
        public function matches(string $operator, string $value, array $purposes): bool
        {
            return $this->evaluate((object) [
                'condition_key' => 'travel_type',
                'operator' => $operator,
                'value' => $value,
            ], ['travel_type' => $purposes]);
        }
    };

    expect($matcher->matches('=', 'conference', ['customer_visit', 'conference']))->toBeTrue()
        ->and($matcher->matches('=', 'hq', ['conference']))->toBeFalse()
        ->and($matcher->matches('!=', 'hq', ['conference']))->toBeTrue()
        ->and($matcher->matches('!=', 'hq', ['hq', 'customer_visit']))->toBeFalse();
});

test('expense context uses visit purposes before territory fallback', function () {
    $resolver = new class
    {
        use BuildsExpenseContext;

        public function purposes(SalesDcr $dcr): string|array
        {
            return $this->resolveTravelType($dcr);
        }
    };

    $visit = new Visit;
    $visit->setRelation('visitPurposes', new Collection([
        new VisitPurpose(['code' => 'conference']),
        new VisitPurpose(['code' => 'training']),
    ]));
    $dcr = new SalesDcr;
    $dcr->setRelation('visits', new Collection([$visit]));

    expect($resolver->purposes($dcr))->toBe(['conference', 'training']);

    $dcr->setRelation('visits', new Collection);
    expect($resolver->purposes($dcr))->toBe('hq');
});
