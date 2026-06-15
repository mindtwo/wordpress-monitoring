<?php

declare(strict_types=1);

use Mindtwo\Monitoring\Data\TransportResult;
use Mindtwo\Monitoring\WordPress\Scheduler\PushScheduler;

test('sync schedules the recurring push once', function () {
    $wordPress = fakeWordPress();
    $scheduler = new PushScheduler($wordPress, static fn (): TransportResult => TransportResult::delivered(200));

    $scheduler->sync();
    $scheduler->sync();

    expect($wordPress->scheduleCalls)->toHaveCount(1)
        ->and($wordPress->scheduleCalls[0]['hook'])->toBe('mindtwo_monitoring_push')
        ->and($wordPress->scheduleCalls[0]['recurrence'])->toBe('daily');
});

test('sync clears the event when the schedule is disabled', function () {
    $wordPress = fakeWordPress();
    $wordPress->scheduled[PushScheduler::HOOK] = time();
    $wordPress->options['mindtwo_monitoring_settings']['schedule_enabled'] = false;

    (new PushScheduler($wordPress, static fn (): TransportResult => TransportResult::delivered(200)))->sync();

    expect($wordPress->scheduled)->not->toHaveKey(PushScheduler::HOOK)
        ->and($wordPress->clearedHooks)->toBe([PushScheduler::HOOK]);
});

test('run pushes when enabled and refuses when disabled', function () {
    $wordPress = fakeWordPress();
    $pushed = 0;

    $scheduler = new PushScheduler($wordPress, function () use (&$pushed): TransportResult {
        $pushed++;

        return TransportResult::delivered(200);
    });

    expect($scheduler->run()->success)->toBeTrue()
        ->and($pushed)->toBe(1);

    $wordPress->options['mindtwo_monitoring_settings']['schedule_enabled'] = false;

    expect($scheduler->run()->success)->toBeFalse()
        ->and($pushed)->toBe(1);
});

test('a custom recurrence is honored', function () {
    $wordPress = fakeWordPress();
    $wordPress->options['mindtwo_monitoring_settings']['schedule_recurrence'] = 'twicedaily';

    (new PushScheduler($wordPress, static fn (): TransportResult => TransportResult::delivered(200)))->sync();

    expect($wordPress->scheduleCalls[0]['recurrence'])->toBe('twicedaily');
});
