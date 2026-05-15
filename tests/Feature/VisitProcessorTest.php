<?php

declare(strict_types=1);

namespace Boralp\Pixelite\Tests\Feature;

use Boralp\Pixelite\Models\Referer;
use Boralp\Pixelite\Models\UserAgent;
use Boralp\Pixelite\Models\Utm;
use Boralp\Pixelite\Models\Visit;
use Boralp\Pixelite\Models\VisitRaw;
use Boralp\Pixelite\Services\VisitProcessor;
use Boralp\Pixelite\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class VisitProcessorTest extends TestCase
{
    use RefreshDatabase;

    private VisitProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = $this->app->make(VisitProcessor::class);
    }

    private function makeRaw(array $overrides = []): VisitRaw
    {
        return VisitRaw::create(array_merge([
            'session_id' => uniqid('sess_', true),
            'route_name' => 'home',
        ], $overrides));
    }

    // ── basic processing ──────────────────────────────────────────────────────

    public function test_run_returns_zero_when_queue_empty(): void
    {
        $this->assertSame(0, $this->processor->run(10));
    }

    public function test_run_processes_raw_into_visit(): void
    {
        $this->makeRaw();

        $count = $this->processor->run(10);

        $this->assertSame(1, $count);
        $this->assertSame(0, VisitRaw::count());
        $this->assertSame(1, Visit::count());
    }

    public function test_run_processes_batch_of_multiple_records(): void
    {
        foreach (range(1, 5) as $i) {
            $this->makeRaw(['session_id' => "sess-{$i}"]);
        }

        $count = $this->processor->run(10);

        $this->assertSame(5, $count);
        $this->assertSame(0, VisitRaw::count());
        $this->assertSame(5, Visit::count());
    }

    public function test_run_respects_batch_size_limit(): void
    {
        foreach (range(1, 10) as $i) {
            $this->makeRaw(['session_id' => "sess-{$i}"]);
        }

        $count = $this->processor->run(3);

        $this->assertSame(3, $count);
        $this->assertSame(7, VisitRaw::count());
        $this->assertSame(3, Visit::count());
    }

    // ── data fidelity ─────────────────────────────────────────────────────────

    public function test_session_id_and_route_name_carried_to_visit(): void
    {
        $this->makeRaw(['session_id' => 'my-session', 'route_name' => 'products.show']);

        $this->processor->run(10);

        $visit = Visit::first();
        $this->assertSame('my-session', $visit->session_id);
        $this->assertSame('products.show', $visit->route_name);
    }

    public function test_user_id_carried_when_cross_session_enabled(): void
    {
        config(['pixelite.profiling.cross_session' => true]);

        $this->makeRaw(['user_id' => 42]);

        $this->processor->run(10);

        $this->assertSame(42, Visit::first()->user_id);
    }

    public function test_user_id_stripped_when_cross_session_disabled(): void
    {
        config(['pixelite.profiling.cross_session' => false]);

        $this->makeRaw(['user_id' => 42]);

        $this->processor->run(10);

        $this->assertNull(Visit::first()->user_id);
    }

    public function test_team_id_and_custom_id_carried(): void
    {
        $this->makeRaw(['team_id' => 7, 'custom_id' => 'shop-123']);

        $this->processor->run(10);

        $visit = Visit::first();
        $this->assertSame(7, $visit->team_id);
        $this->assertSame('shop-123', $visit->custom_id);
    }

    // ── IP handling ───────────────────────────────────────────────────────────

    public function test_ip_stored_as_binary_and_readable(): void
    {
        // Store IPv4-mapped IPv6 binary (what TrackVisit middleware produces)
        $ipv4 = '192.168.1.100';
        $binary = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" . inet_pton($ipv4);

        $this->makeRaw(['ip' => $binary]);

        $this->processor->run(10);

        $visit = Visit::first();
        // ip attribute should decode back to string representation
        $this->assertNotNull($visit->ip);
    }

    public function test_null_ip_is_preserved(): void
    {
        $this->makeRaw(['ip' => null]);

        $this->processor->run(10);

        // getRaw ip column value to bypass accessor
        $rawIp = \Illuminate\Support\Facades\DB::table('visits')->value('ip');
        $this->assertNull($rawIp);
    }

    // ── related table deduplication ───────────────────────────────────────────

    public function test_identical_user_agents_deduplicated(): void
    {
        $ua = 'Mozilla/5.0 (X11; Linux x86_64) TestBrowser/1.0';

        foreach (range(1, 3) as $i) {
            $this->makeRaw([
                'session_id' => "sess-{$i}",
                'user_agent' => $ua,
            ]);
        }

        $this->processor->run(10);

        // All three visits share a single UserAgent row
        $this->assertSame(1, UserAgent::count());
        $this->assertSame(3, Visit::count());
    }

    public function test_identical_utm_params_deduplicated(): void
    {
        $payload = json_encode(['utm' => [
            'utm_source'   => 'google',
            'utm_medium'   => 'cpc',
            'utm_campaign' => 'launch',
            'utm_term'     => null,
            'utm_content'  => null,
        ]]);

        foreach (range(1, 4) as $i) {
            $this->makeRaw(['session_id' => "sess-{$i}", 'payload' => $payload]);
        }

        $this->processor->run(10);

        $this->assertSame(1, Utm::count());
        $this->assertSame(4, Visit::count());
    }

    public function test_distinct_utm_params_create_separate_rows(): void
    {
        foreach (['google', 'facebook', 'twitter'] as $source) {
            $payload = json_encode(['utm' => [
                'utm_source' => $source,
                'utm_medium' => 'cpc',
                'utm_campaign' => null,
                'utm_term' => null,
                'utm_content' => null,
            ]]);
            $this->makeRaw(['session_id' => uniqid($source), 'payload' => $payload]);
        }

        $this->processor->run(10);

        $this->assertSame(3, Utm::count());
    }

    // ── referrer ──────────────────────────────────────────────────────────────

    public function test_referrer_extracted_from_payload(): void
    {
        $payload = json_encode(['referrer' => 'https://example.com/landing']);

        $this->makeRaw(['payload' => $payload]);

        $this->processor->run(10);

        $this->assertSame(1, Referer::count());
        $this->assertSame('https://example.com/landing', Referer::first()->raw);
    }

    public function test_no_referrer_creates_no_referer_row(): void
    {
        $this->makeRaw(['payload' => json_encode(['locale' => 'en'])]);

        $this->processor->run(10);

        $this->assertSame(0, Referer::count());
    }

    // ── multiple runs / idempotency ───────────────────────────────────────────

    public function test_second_run_on_empty_queue_returns_zero(): void
    {
        $this->makeRaw();

        $this->assertSame(1, $this->processor->run(10));
        $this->assertSame(0, $this->processor->run(10));
    }

    public function test_multiple_sequential_runs_process_all_records(): void
    {
        foreach (range(1, 6) as $i) {
            $this->makeRaw(['session_id' => "sess-{$i}"]);
        }

        $this->processor->run(4);
        $this->processor->run(4);

        $this->assertSame(0, VisitRaw::count());
        $this->assertSame(6, Visit::count());
    }

    // ── shutdown ──────────────────────────────────────────────────────────────

    public function test_shutdown_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        $this->processor->shutdown();
    }
}
