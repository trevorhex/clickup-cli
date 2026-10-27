<?php

use App\Support\UserConfig;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(TestCase::class)
    ->beforeEach(function () {
        // Never touch the real Keychain or ~/.config/cu during tests.
        putenv('CLICKUP_TOKEN=pk_test');
        Http::preventStrayRequests();

        $config = new UserConfig(sys_get_temp_dir().'/cu-test-'.uniqid().'/config.json');
        $config->set('workspace_id', '900');
        $config->set('user_id', 42);
        $this->app->instance(UserConfig::class, $config);
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something(): void
{
    // ..
}

/** A ClickUp task payload with sensible defaults. */
function fakeTask(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => 'abc123',
        'custom_id' => null,
        'name' => 'Fix booking calendar',
        'status' => ['status' => 'in progress', 'color' => '#4194f6', 'orderindex' => 1],
        'priority' => ['priority' => 'high'],
        'due_date' => null,
        'date_updated' => '1700000000000',
        'date_created' => '1700000000000',
        'url' => 'https://app.clickup.com/t/abc123',
        'list' => ['id' => '1', 'name' => 'Spa Bookings'],
        'folder' => ['name' => 'Civana', 'hidden' => false],
        'assignees' => [['id' => 42, 'username' => 'Trevor']],
        'creator' => ['username' => 'Trevor'],
    ], $overrides);
}

/** Statuses for a list, as GET /list/{id} returns them. */
function fakeStatuses(): array
{
    return ['statuses' => [
        ['status' => 'to do', 'type' => 'open'],
        ['status' => 'in progress', 'type' => 'custom'],
        ['status' => 'in review', 'type' => 'custom'],
        ['status' => 'complete', 'type' => 'closed'],
    ]];
}
