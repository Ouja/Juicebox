<?php

namespace Tests\Feature;

use App\Jobs\SendWelcomeEmail;
use App\Mail\WelcomeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WelcomeEmailTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_manual_command_queues_email_for_existing_user(): void
    {
        $user = User::factory()->create();
        Queue::fake();

        $this->artisan('welcome:send', ['user' => $user->id])
            ->expectsOutput('Welcome email queued.')->assertSuccessful();

        Queue::assertPushed(SendWelcomeEmail::class, fn (SendWelcomeEmail $job): bool => $job->userId === $user->id);
    }

    public function test_manual_command_rejects_missing_user(): void
    {
        Queue::fake();

        $this->artisan('welcome:send', ['user' => 9999])
            ->expectsOutput('User not found.')->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_manual_command_rejects_invalid_identifier(): void
    {
        Queue::fake();

        $this->artisan('welcome:send', ['user' => 'abc'])
            ->expectsOutput('The user ID must be a positive integer.')->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_job_sends_welcome_email_through_service_and_library(): void
    {
        $user = User::factory()->create();
        Mail::fake();

        SendWelcomeEmail::dispatchSync($user->id);

        Mail::assertSent(WelcomeMail::class, fn (WelcomeMail $mail): bool => $mail->hasTo($user->email) && $mail->name === $user->name);
        Mail::assertSentCount(1);
    }

    public function test_registration_job_is_processed_by_database_queue_worker(): void
    {
        config(['queue.default' => 'database']);
        Mail::fake();

        $this->postJson('/api/register', [
            'name' => 'Taylor', 'email' => 'taylor@example.com',
            'password' => 'Securepass123', 'password_confirmation' => 'Securepass123',
        ])->assertCreated();

        $this->assertDatabaseCount('jobs', 1);
        Mail::assertNothingSent();
        $this->artisan('queue:work', ['--once' => true, '--tries' => 1])->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        Mail::assertSent(WelcomeMail::class, fn (WelcomeMail $mail): bool => $mail->hasTo('taylor@example.com'));
    }

    public function test_job_skips_user_deleted_before_processing(): void
    {
        $user = User::factory()->create();
        $user->delete();
        Mail::fake();

        SendWelcomeEmail::dispatchSync($user->id);

        Mail::assertNothingSent();
    }

    public function test_welcome_email_escapes_user_name(): void
    {
        $html = (new WelcomeMail('<script>alert(1)</script>'))->render();

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }
}
