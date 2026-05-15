<?php

declare(strict_types=1);

namespace Boralp\Pixelite\Tests\Unit;

use Boralp\Pixelite\Models\Visit;
use Boralp\Pixelite\Models\VisitRaw;
use Boralp\Pixelite\Services\PrivacyService;
use Boralp\Pixelite\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PrivacyServiceTest extends TestCase
{
    use RefreshDatabase;

    private PrivacyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(PrivacyService::class);
    }

    // ── isTrackingAllowed ─────────────────────────────────────────────────────

    public function test_tracking_allowed_by_default(): void
    {
        $request = Request::create('/');
        $this->assertTrue($this->service->isTrackingAllowed($request));
    }

    public function test_opt_out_cookie_blocks_tracking(): void
    {
        config(['pixelite.rights.opt_out_enabled' => true]);
        config(['pixelite.rights.opt_out_cookie' => 'pixelite_optout']);

        $request = Request::create('/');
        $request->cookies->set('pixelite_optout', '1');

        $this->assertFalse($this->service->isTrackingAllowed($request));
    }

    public function test_opt_out_disabled_cookie_ignored(): void
    {
        config(['pixelite.rights.opt_out_enabled' => false]);

        $request = Request::create('/');
        $request->cookies->set('pixelite_optout', '1');

        $this->assertTrue($this->service->isTrackingAllowed($request));
    }

    public function test_consent_required_no_cookie_uses_default_granted(): void
    {
        config(['pixelite.consent.required' => true]);
        config(['pixelite.consent.cookie_name' => 'pixelite_consent']);
        config(['pixelite.consent.default' => 'granted']);

        $request = Request::create('/');
        $this->assertTrue($this->service->isTrackingAllowed($request));
    }

    public function test_consent_required_no_cookie_uses_default_denied(): void
    {
        config(['pixelite.consent.required' => true]);
        config(['pixelite.consent.default' => 'denied']);

        $request = Request::create('/');
        $this->assertFalse($this->service->isTrackingAllowed($request));
    }

    public function test_consent_cookie_granted_values(): void
    {
        config(['pixelite.consent.required' => true]);
        config(['pixelite.consent.cookie_name' => 'pixelite_consent']);

        foreach (['1', 'true', 'granted', 'yes'] as $value) {
            $request = Request::create('/');
            $request->cookies->set('pixelite_consent', $value);
            $this->assertTrue(
                $this->service->isTrackingAllowed($request),
                "Expected tracking to be allowed for consent value: {$value}"
            );
        }
    }

    public function test_consent_cookie_denied_value(): void
    {
        config(['pixelite.consent.required' => true]);
        config(['pixelite.consent.cookie_name' => 'pixelite_consent']);

        $request = Request::create('/');
        $request->cookies->set('pixelite_consent', 'denied');

        $this->assertFalse($this->service->isTrackingAllowed($request));
    }

    public function test_opt_out_takes_precedence_over_consent_cookie(): void
    {
        config(['pixelite.rights.opt_out_enabled' => true]);
        config(['pixelite.consent.required' => true]);
        config(['pixelite.consent.default' => 'granted']);

        $request = Request::create('/');
        $request->cookies->set('pixelite_optout', '1');
        $request->cookies->set('pixelite_consent', 'granted');

        $this->assertFalse($this->service->isTrackingAllowed($request));
    }

    // ── deleteByUserId ────────────────────────────────────────────────────────

    public function test_delete_by_user_id_removes_matching_records(): void
    {
        VisitRaw::insert([
            ['user_id' => 1, 'session_id' => 'a', 'route_name' => 'home', 'created_at' => now()],
            ['user_id' => 2, 'session_id' => 'b', 'route_name' => 'home', 'created_at' => now()],
        ]);

        $result = $this->service->deleteByUserId(1);

        $this->assertSame(1, $result['raw_deleted']);
        $this->assertSame(1, VisitRaw::count());
        $this->assertSame(2, VisitRaw::first()->user_id);
    }

    public function test_delete_by_user_id_logs_dsr(): void
    {
        VisitRaw::insert([
            ['user_id' => 5, 'session_id' => 'x', 'route_name' => 'page', 'created_at' => now()],
        ]);

        $this->service->deleteByUserId(5);

        $this->assertDatabaseHas('pixelite_dsr', [
            'type'            => 'deletion',
            'identifier'      => '5',
            'identifier_type' => 'user_id',
            'status'          => 'completed',
        ]);
    }

    public function test_delete_returns_zero_when_nothing_matches(): void
    {
        $result = $this->service->deleteByUserId(9999);

        $this->assertSame(0, $result['total']);
    }

    // ── deleteByTeamId ────────────────────────────────────────────────────────

    public function test_delete_by_team_id(): void
    {
        VisitRaw::insert([
            ['team_id' => 10, 'session_id' => 'c', 'route_name' => 'home', 'created_at' => now()],
            ['team_id' => 20, 'session_id' => 'd', 'route_name' => 'home', 'created_at' => now()],
        ]);

        $result = $this->service->deleteByTeamId(10);

        $this->assertSame(1, $result['raw_deleted']);
        $this->assertSame(1, VisitRaw::count());
    }

    // ── deleteByCustomId ──────────────────────────────────────────────────────

    public function test_delete_by_custom_id(): void
    {
        VisitRaw::insert([
            ['custom_id' => 'shop-abc', 'session_id' => 'e', 'route_name' => 'home', 'created_at' => now()],
            ['custom_id' => 'shop-xyz', 'session_id' => 'f', 'route_name' => 'home', 'created_at' => now()],
        ]);

        $result = $this->service->deleteByCustomId('shop-abc');

        $this->assertSame(1, $result['raw_deleted']);
        $this->assertSame(1, VisitRaw::count());
    }

    // ── deleteBySessionId ─────────────────────────────────────────────────────

    public function test_delete_by_session_id(): void
    {
        VisitRaw::insert([
            ['session_id' => 'sess-1', 'route_name' => 'home', 'created_at' => now()],
            ['session_id' => 'sess-2', 'route_name' => 'home', 'created_at' => now()],
        ]);

        $result = $this->service->deleteBySessionId('sess-1');

        $this->assertSame(1, $result['raw_deleted']);
    }

    // ── purgeOldData ──────────────────────────────────────────────────────────

    public function test_purge_skips_when_retention_disabled(): void
    {
        config(['pixelite.retention.enabled' => false]);

        DB::table('visit_raws')->insert([
            ['session_id' => 'old', 'route_name' => 'home', 'created_at' => now()->subDays(10)],
        ]);

        $result = $this->service->purgeOldData();

        $this->assertSame(0, $result['raw_deleted']);
        $this->assertSame(1, VisitRaw::count());
    }

    public function test_purge_deletes_old_raw_records(): void
    {
        config(['pixelite.retention.enabled' => true]);
        config(['pixelite.retention.raw_hours' => 24]);
        config(['pixelite.retention.visits_days' => 365]);

        DB::table('visit_raws')->insert([
            ['session_id' => 'old', 'route_name' => 'home', 'created_at' => now()->subHours(25)],
            ['session_id' => 'new', 'route_name' => 'home', 'created_at' => now()->subHours(1)],
        ]);

        $result = $this->service->purgeOldData();

        $this->assertSame(1, $result['raw_deleted']);
        $this->assertSame(1, VisitRaw::count());
        $this->assertSame('new', VisitRaw::first()->session_id);
    }
}
