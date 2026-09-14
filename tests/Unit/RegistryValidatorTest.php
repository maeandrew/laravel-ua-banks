<?php

use Maeandrew\UaBanks\Exceptions\SyncFailedException;
use Maeandrew\UaBanks\Sync\RegistryValidator;
use Maeandrew\UaBanks\Tests\TestCase;

it('accepts the fixtures', function () {
    [$typ0, $typ1] = (new RegistryValidator(5))->validate(TestCase::fixture('typ0.json'), TestCase::fixture('typ1.json'));

    expect($typ0)->toHaveCount(10)->and($typ1)->toHaveCount(3);
});

it('rejects responses that are not JSON arrays', function (mixed $typ0, mixed $typ1) {
    (new RegistryValidator(1))->validate($typ0, $typ1);
})->with([
    'object' => [['error' => 'x'], []],
    'string' => ['oops', []],
    'null' => [null, []],
    'typ1 object' => [fn () => TestCase::fixture('typ0.json'), ['a' => 1]],
])->throws(SyncFailedException::class, 'not a JSON array');

it('enforces min_banks unless forced', function () {
    $validator = new RegistryValidator(50);

    expect(fn () => $validator->validate(TestCase::fixture('typ0.json'), []))
        ->toThrow(SyncFailedException::class, 'only 10 head offices received, at least 50 expected')
        ->and($validator->validate(TestCase::fixture('typ0.json'), [], force: true)[0])->toHaveCount(10);
});

it('rejects records with missing critical fields', function () {
    (new RegistryValidator(5))->validate(TestCase::fixture('typ0-invalid-missing-fields.json'), []);
})->throws(SyncFailedException::class, 'missing or invalid MFO');

it('reports every critical field', function () {
    try {
        (new RegistryValidator(5))->validate(TestCase::fixture('typ0-invalid-missing-fields.json'), []);
    } catch (SyncFailedException $e) {
        expect($e->getMessage())
            ->toContain('record #1: missing or invalid MFO')
            ->toContain('record #2: missing SHORTNAME')
            ->toContain('record #3: missing or invalid KSTAN');
    }
});

it('rejects duplicate MFOs', function () {
    (new RegistryValidator(5))->validate(TestCase::fixture('typ0-invalid-duplicates.json'), []);
})->throws(SyncFailedException::class, 'duplicate MFO 300012');

it('rejects aliases without GLMFO', function () {
    $typ1 = TestCase::fixture('typ1.json');
    unset($typ1[1]['GLMFO']);

    (new RegistryValidator(5))->validate(TestCase::fixture('typ0.json'), $typ1);
})->throws(SyncFailedException::class, 'typ=1 record #1: missing or invalid GLMFO');

it('does not fail on non-critical problems such as bad dates', function () {
    $typ0 = TestCase::fixture('typ0.json');
    $typ0[0]['D_OPEN'] = '99.99.9999';
    $typ0[1]['KSTAN'] = 42;

    expect((new RegistryValidator(5))->validate($typ0, [])[0])->toHaveCount(10);
});
