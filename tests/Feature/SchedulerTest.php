<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

function freshSchedule(): Schedule
{
    app()->forgetInstance(Schedule::class);

    return app(Schedule::class);
}

function syncEvents(Schedule $schedule): array
{
    return array_values(array_filter($schedule->events(), fn (Event $event) => str_contains((string) $event->command, 'ua-banks:sync')));
}

it('does not schedule the sync by default', function () {
    expect(syncEvents(freshSchedule()))->toBe([]);
});

it('schedules the sync when enabled', function () {
    config(['ua-banks.schedule.enabled' => true]);

    $events = syncEvents(freshSchedule());

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('17 4 * * *')
        ->and($events[0]->timezone)->toBe('Europe/Kyiv')
        ->and($events[0]->withoutOverlapping)->toBeTrue()
        ->and($events[0]->onOneServer)->toBeTrue();
});

it('uses the configured cron and timezone', function () {
    config([
        'ua-banks.schedule.enabled' => true,
        'ua-banks.schedule.cron' => '0 */6 * * *',
        'ua-banks.schedule.timezone' => 'UTC',
    ]);

    $events = syncEvents(freshSchedule());

    expect($events[0]->expression)->toBe('0 */6 * * *')
        ->and($events[0]->timezone)->toBe('UTC');
});
