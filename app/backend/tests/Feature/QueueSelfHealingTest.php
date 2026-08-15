<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Support\QueueKeeper;
use App\Support\QueueMaintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The queue keeping itself alive, so a frozen migration never depends on
 * somebody remembering to install a cron or type `queue:retry all`.
 *
 * A migration is entirely queue-driven, which means "no worker" is not a
 * degraded app — it is a stopped one, with the merchant watching a progress bar
 * that will never move again.
 */
class QueueSelfHealingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): array
    {
        $token = str_repeat('a', 64);

        AdminUser::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
            'api_token_hash' => hash('sha256', $token),
        ]);

        return ['Authorization' => "Bearer {$token}"];
    }

    private function queueJob(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'Fake']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time() - 300,
            'created_at' => time() - 300,
        ]);
    }

    public function test_an_empty_queue_never_starts_a_worker(): void
    {
        config(['queue.auto_worker' => true]);

        // The check runs on every API request, so the cheap path matters: no
        // jobs means no spawn, no lock, no process.
        $this->assertFalse(QueueKeeper::ensureRunning());
        $this->assertSame(0, QueueKeeper::pending());
    }

    public function test_only_one_worker_is_started_per_window(): void
    {
        // The suite runs on the `sync` driver, where there is no jobs table to
        // read — production is `database`, which is the case being tested.
        config(['queue.auto_worker' => true, 'queue.default' => 'database']);
        $this->queueJob();

        if (! QueueKeeper::canSpawn()) {
            $this->markTestSkipped('proc_open is disabled on this host');
        }

        $first = QueueKeeper::ensureRunning();

        // A merchant with the migration page open polls every few seconds, and
        // several tabs poll at once. Without the lock each poll would start
        // another worker and they would fight over the same jobs.
        $this->assertTrue($first);
        $this->assertFalse(QueueKeeper::ensureRunning(), 'the lock must hold within the window');
    }

    public function test_the_self_starter_can_be_switched_off(): void
    {
        // Anywhere supervisor or Horizon runs, two schedulers competing for the
        // same jobs is worse than none.
        config(['queue.auto_worker' => false, 'queue.default' => 'database']);
        $this->queueJob();

        $this->assertFalse(QueueKeeper::ensureRunning());
    }

    public function test_failed_jobs_are_re_queued_without_a_command(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'Fake', 'uuid' => 'x']),
            'exception' => 'RuntimeException: transient',
            'failed_at' => now(),
        ]);

        $retried = QueueMaintenance::retryFailedJobs();

        $this->assertSame(1, $retried, 'a transient failure is real records the merchant is waiting for');
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_a_job_that_keeps_failing_is_eventually_left_alone(): void
    {
        $uuid = (string) \Illuminate\Support\Str::uuid();

        // Three automatic attempts already spent.
        Cache::put('queue-maintenance:retries:'.$uuid, 3, now()->addDay());

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'Poison', 'uuid' => 'x']),
            'exception' => 'RuntimeException: genuinely broken',
            'failed_at' => now(),
        ]);

        // Retrying forever would hide a real bug while burning the merchant's
        // Shopify rate limit on a job that cannot succeed.
        $this->assertSame(0, QueueMaintenance::retryFailedJobs());
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    public function test_the_admin_can_start_a_worker_from_the_panel(): void
    {
        $this->queueJob();

        $response = $this->withHeaders($this->admin())->postJson('/api/admin/queue/run');

        $response->assertOk();
        // Whatever the host allows, the panel must answer with something the
        // operator can act on rather than an unexplained failure.
        $this->assertNotEmpty($response->json('message'));
        $this->assertIsBool($response->json('can_self_start'));
    }

    public function test_the_admin_can_retry_failed_jobs_from_the_panel(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'Fake', 'uuid' => 'x']),
            'exception' => 'RuntimeException: transient',
            'failed_at' => now(),
        ]);

        $this->withHeaders($this->admin())
            ->postJson('/api/admin/queue/retry-failed')
            ->assertOk()
            ->assertJson(['retried' => 1]);
    }

    public function test_the_queue_endpoints_require_an_admin(): void
    {
        $this->postJson('/api/admin/queue/run')->assertStatus(401);
        $this->postJson('/api/admin/queue/retry-failed')->assertStatus(401);
    }
}
