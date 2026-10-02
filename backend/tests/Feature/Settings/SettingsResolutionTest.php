<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Services\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/**
 * How a setting flows down from the diocese, gets locked, reset and kept
 * secret (docs/specs/settings-spec.md, S1). Uses a test-only "Money"
 * section so it doesn't depend on which real fields exist yet.
 */
class SettingsResolutionTest extends TestCase
{
    use BuildsSettingsWorld, RefreshDatabase;

    protected Settings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'settings.sections.testmoney' => [
                'label' => 'Money', 'icon' => 'ri-bank-line', 'colour' => 'secondary', 'group' => 'money',
                'levels' => ['church', 'region', 'diocese'], 'kind' => 'form', 'sentence' => 'Test section.',
            ],
            'settings.fields' => [
                'test.currency' => ['section' => 'testmoney', 'card' => 'Money', 'label' => 'Currency', 'rules' => ['nullable', 'string', 'max:3'], 'default' => 'KES', 'lockable' => true],
                'test.paybill' => ['section' => 'testmoney', 'card' => 'M-Pesa', 'label' => 'Paybill', 'rules' => ['nullable', 'digits_between:5,7']],
                'test.dioceseonly' => ['section' => 'testmoney', 'card' => 'Money', 'label' => 'Diocese only', 'levels' => ['diocese']],
                'test.apikey' => ['section' => 'testmoney', 'card' => 'Gateway', 'label' => 'API key', 'type' => 'secret', 'secret' => true, 'levels' => ['diocese'], 'rules' => ['nullable', 'string', 'max:200']],
            ],
        ]);
        $this->buildSettingsWorld();
        $this->settings = app(Settings::class);
    }

    public function test_with_no_rows_the_default_is_used(): void
    {
        $r = $this->settings->resolve('test.currency', $this->myChurch);

        $this->assertSame('KES', $r['value']);
        $this->assertSame('default', $r['source']);
        $this->assertFalse($r['changed']);
    }

    public function test_a_diocese_value_flows_down_to_a_church(): void
    {
        $this->settings->setMany($this->diocese, 'diocese', 'testmoney', ['test.currency' => 'USD']);

        $r = $this->settings->resolve('test.currency', $this->myChurch);
        $this->assertSame('USD', $r['value']);
        $this->assertSame('inherited', $r['source']);
        $this->assertSame(['type' => 'diocese', 'name' => 'Test Diocese'], $r['from']);
    }

    public function test_a_church_value_beats_the_diocese_unless_the_diocese_locked_it(): void
    {
        $this->settings->setMany($this->diocese, 'diocese', 'testmoney', ['test.currency' => 'USD']);
        $this->settings->setMany($this->myChurch, 'church', 'testmoney', ['test.currency' => 'EUR']);
        $this->assertSame('own', $this->settings->resolve('test.currency', $this->myChurch)['source']);
        $this->assertSame('EUR', $this->settings->get('test.currency', $this->myChurch));

        $this->settings->setMany($this->diocese, 'diocese', 'testmoney', [], ['test.currency' => true]);

        $r = $this->settings->resolve('test.currency', $this->myChurch);
        $this->assertSame('USD', $r['value']);
        $this->assertSame(['type' => 'diocese', 'name' => 'Test Diocese'], $r['locked_by']);
        $this->assertSame('USD', $this->settings->get('test.currency', $this->otherChurch));
    }

    public function test_saving_what_would_be_inherited_or_resetting_removes_the_own_row(): void
    {
        $this->settings->setMany($this->myChurch, 'church', 'testmoney', ['test.currency' => 'EUR', 'test.paybill' => '123456']);
        $this->assertSame(2, Setting::where('territory_id', $this->myChurch->id)->count());

        $this->settings->setMany($this->myChurch, 'church', 'testmoney', ['test.currency' => 'KES'], [], ['test.paybill']);

        $this->assertSame(0, Setting::where('territory_id', $this->myChurch->id)->count());
        $this->assertSame('default', $this->settings->resolve('test.currency', $this->myChurch)['source']);
    }

    public function test_a_setting_locked_above_cannot_be_saved_below(): void
    {
        $this->settings->setMany($this->diocese, 'diocese', 'testmoney', ['test.currency' => 'USD'], ['test.currency' => true]);

        try {
            $this->settings->setMany($this->myChurch, 'church', 'testmoney', ['test.currency' => 'EUR']);
            $this->fail('Expected a validation error');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Set by the diocese', $e->errors()['test.currency'][0]);
        }
    }

    public function test_a_setting_not_editable_at_a_level_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->settings->setMany($this->myChurch, 'church', 'testmoney', ['test.dioceseonly' => 'x']);
    }

    public function test_only_regions_and_the_diocese_can_lock(): void
    {
        $this->expectException(ValidationException::class);

        $this->settings->setMany($this->myChurch, 'church', 'testmoney', ['test.currency' => 'EUR'], ['test.currency' => true]);
    }

    public function test_a_secret_is_stored_encrypted_never_shown_and_masked_in_the_audit(): void
    {
        $this->settings->setMany($this->diocese, 'diocese', 'testmoney', ['test.apikey' => 'sk-live-123'], [], [], $this->bishop);

        $raw = Setting::where('key', 'test.apikey')->value('value');
        $this->assertStringNotContainsString('sk-live-123', $raw);
        $this->assertSame('sk-live-123', $this->settings->get('test.apikey', $this->diocese));

        Sanctum::actingAs($this->bishop);
        $field = collect($this->getJson('/api/settings/sections/testmoney')->assertOk()->json('data.cards'))
            ->flatMap(fn ($c) => $c['fields'])->firstWhere('key', 'test.apikey');
        $this->assertNull($field['value']);
        $this->assertTrue($field['secret_set']);
        $this->assertStringNotContainsString('sk-live-123', json_encode(Audit::where('event', 'settings.updated')->get()->toArray()));

        // A blank value keeps the saved one.
        $this->settings->setMany($this->diocese, 'diocese', 'testmoney', ['test.apikey' => '']);
        $this->assertSame('sk-live-123', $this->settings->get('test.apikey', $this->diocese));
    }

    public function test_a_change_above_is_seen_below_straight_away(): void
    {
        $this->assertSame('KES', $this->settings->get('test.currency', $this->myChurch)); // cached now
        $this->settings->setMany($this->region, 'region', 'testmoney', ['test.currency' => 'TZS']);

        $this->assertSame('TZS', $this->settings->get('test.currency', $this->myChurch));
    }

    public function test_settings_that_cannot_be_read_fall_back_to_defaults(): void
    {
        Cache::shouldReceive('get')->andReturn(1);
        Cache::shouldReceive('rememberForever')->andThrow(new \RuntimeException('no settings table'));

        $this->assertSame('KES', app(Settings::class)->get('test.currency', $this->myChurch));
    }

    public function test_the_api_saves_a_section_and_refuses_bad_values(): void
    {
        Sanctum::actingAs($this->pastor);

        $this->putJson('/api/settings/sections/testmoney', ['values' => ['test.paybill' => '12']])
            ->assertStatus(422)->assertJsonValidationErrors(['test.paybill']);
        $this->putJson('/api/settings/sections/testmoney', ['values' => ['test.paybill' => '123456']])->assertOk();

        $this->assertSame('123456', $this->settings->get('test.paybill', $this->myChurch));
        $this->assertDatabaseHas('audits', ['event' => 'settings.updated', 'auditable_type' => 'territory', 'auditable_id' => $this->myChurch->id, 'tags' => 'settings,testmoney']);
    }

    public function test_a_reader_cannot_save(): void
    {
        Sanctum::actingAs($this->secretary);

        $this->getJson('/api/settings/sections/testmoney')->assertOk()->assertJsonPath('data.can.update', false);
        $this->putJson('/api/settings/sections/testmoney', ['values' => ['test.paybill' => '123456']])->assertForbidden();
    }
}
