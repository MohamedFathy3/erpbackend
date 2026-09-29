<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BiometricAgentPairingCode;
use App\Models\BiometricDevice;
use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class BiometricAgentApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.root_domain' => 'professionalacademyedu.com']);

        $this->tenant = Tenant::query()->create([
            'name' => 'ACSA Test',
            'slug' => 'acsa-test',
            'status' => 'active',
            'plan' => 'starter',
        ]);
        $role = Role::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'admin']);
        $this->admin = Admin::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $role->id,
        ]);
    }

    public function test_pairing_code_is_tenant_scoped_one_time_and_agent_access_is_revocable(): void
    {
        $headers = ['X-Tenant-Slug' => 'acsa-test'];
        $this->actingAs($this->admin, 'sanctum');

        $codeResponse = $this->withHeaders($headers)
            ->postJson('/api/biometric/agents/pairing-codes', ['name' => 'Office Agent'])
            ->assertCreated()
            ->assertJsonPath('status', true);

        $pairingCode = $codeResponse->json('data.code');
        $this->assertIsString($pairingCode);
        $this->assertDatabaseHas('biometric_agent_pairing_codes', [
            'tenant_id' => $this->tenant->id,
            'code_hash' => hash('sha256', $pairingCode),
            'used_at' => null,
        ]);
        $this->assertDatabaseMissing('biometric_agent_pairing_codes', ['code_hash' => $pairingCode]);

        $paired = $this->withHeaders($headers)
            ->postJson('/api/biometric/agents/pair', ['code' => $pairingCode])
            ->assertCreated()
            ->json('data');

        $this->assertSame('1', (string) $paired['agent_id']);
        $this->assertIsString($paired['agent_token']);
        $this->assertDatabaseHas('biometric_agents', [
            'id' => $paired['agent_id'],
            'tenant_id' => $this->tenant->id,
            'status' => 'offline',
        ]);
        $this->withHeaders($headers)->postJson('/api/biometric/agents/pair', ['code' => $pairingCode])->assertUnprocessable();

        $device = BiometricDevice::query()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Front Desk',
            'ip_address' => '192.168.1.201',
            'port' => 4370,
            'protocol' => 'tcp',
            'device_password' => 0,
            'is_active' => true,
        ]);
        Auth::forgetGuards();
        $agentHeaders = $headers + ['Authorization' => 'Bearer ' . $paired['agent_token']];
        $this->withHeaders($agentHeaders)
            ->getJson('/api/biometric/agents/devices')
            ->assertOk()
            ->assertJsonPath('data.devices.0.id', (string) $device->id)
            ->assertJsonPath('data.devices.0.password', 0);

        Tenant::query()->create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => 'active',
            'plan' => 'starter',
        ]);
        $this->withHeaders(['X-Tenant-Slug' => 'other-tenant'] + ['Authorization' => 'Bearer ' . $paired['agent_token']])
            ->getJson('/api/biometric/agents/devices')
            ->assertUnauthorized();

        $eventKey = str_repeat('a', 64);
        $eventBatch = ['events' => [[
                'event_key' => $eventKey,
                'device_id' => $device->id,
                'device_user_id' => 'UNMAPPED-USER',
                'timestamp' => '2026-09-29T10:15:00+00:00',
                'status' => 0,
                'punch' => 0,
                'device_uid' => 4,
            ]]];
        $this->withHeaders($agentHeaders)
            ->postJson('/api/biometric/agents/events', $eventBatch)
            ->assertOk()
            ->assertJsonPath('data.accepted_event_keys.0', $eventKey);
        $this->withHeaders($agentHeaders)
            ->postJson('/api/biometric/agents/events', $eventBatch)
            ->assertOk()
            ->assertJsonPath('data.accepted_event_keys.0', $eventKey);
        $this->assertDatabaseHas('biometric_logs', [
            'tenant_id' => $this->tenant->id,
            'device_id' => $device->id,
            'agent_event_key' => $eventKey,
        ]);
        $this->assertDatabaseCount('biometric_logs', 1);

        $this->withHeaders($agentHeaders)
            ->postJson('/api/biometric/agents/heartbeat', [
                'agent_id' => $paired['agent_id'],
                'agent_version' => '0.1.0',
                'status' => 'online',
                'devices_count' => 1,
                'pending_events' => 0,
                'errors' => [],
            ])
            ->assertOk();
        $this->assertDatabaseHas('biometric_agents', [
            'id' => $paired['agent_id'],
            'status' => 'online',
            'agent_version' => '0.1.0',
        ]);

        Auth::forgetGuards();
        $this->actingAs($this->admin, 'sanctum');
        $this->withHeaders($headers)
            ->deleteJson('/api/biometric/agents/' . $paired['agent_id'])
            ->assertOk();
        $this->assertDatabaseHas('biometric_agents', [
            'id' => $paired['agent_id'],
            'status' => 'revoked',
        ]);

        Auth::forgetGuards();
        $this->withHeaders($agentHeaders)->getJson('/api/biometric/agents/devices')->assertUnauthorized();
    }

    public function test_pairing_code_creation_requires_hr_permission(): void
    {
        $headers = ['X-Tenant-Slug' => 'acsa-test'];
        $user = Admin::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($user, 'sanctum')
            ->withHeaders($headers)
            ->postJson('/api/biometric/agents/pairing-codes', ['name' => 'Not allowed'])
            ->assertForbidden();

        $this->assertSame(0, BiometricAgentPairingCode::query()->count());
    }
}
