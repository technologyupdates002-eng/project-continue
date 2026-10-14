<?php

namespace Tests\Feature\Banking;

use Tests\TestCase;

class CoopTestConnectivityCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('coop_bank.enable_real_calls', false);
    }

    public function test_command_initializes_without_verbose_option_collision(): void
    {
        $this->artisan('coop:test-connectivity')
            ->assertExitCode(0);
    }

    public function test_command_help_does_not_error(): void
    {
        $this->artisan('coop:test-connectivity', ['--help' => true])
            ->assertExitCode(0);
    }

    public function test_command_verbose_flag_initializes(): void
    {
        $this->artisan('coop:test-connectivity', ['--verbose' => true])
            ->assertExitCode(0);
    }

    public function test_command_shows_simulation_mode_in_test(): void
    {
        $this->artisan('coop:test-connectivity')
            ->expectsOutput('Mock token returned — COOP_ENABLE_REAL_CALLS is false.')
            ->assertExitCode(0);
    }
}
