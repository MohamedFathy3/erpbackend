<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsappIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_number_verification_validates_e164_without_claiming_public_lookup(): void
    {
        $admin = Admin::factory()->create(['tenant_id' => null]);
        $customer = Customer::create(['name' => 'Test', 'phone' => '+967771234567']);

        $response = $this->actingAs($admin)->postJson("/api/crm/customers/{$customer->id}/verify-whatsapp-number");

        $response->assertOk()->assertJsonPath('data.format_valid', true)->assertJsonPath('data.availability_checked', false);
    }

    public function test_text_messages_are_rejected_outside_the_service_window(): void
    {
        $admin = Admin::factory()->create(['tenant_id' => null]);
        $customer = Customer::create(['name' => 'Test', 'phone' => '+967771234567']);

        $this->actingAs($admin)->postJson("/api/crm/customers/{$customer->id}/send-whatsapp", [
            'type' => 'text', 'body' => 'Hello',
        ])->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_webhook_updates_provider_status(): void
    {
        config(['whatsapp.webhook_verify_token' => 'test-token']);
        $message = \App\Models\WhatsappMessage::create([
            'customer_id' => Customer::create(['name' => 'Test', 'phone' => '+967771234567'])->id,
            'to_phone' => '+967771234567', 'type' => 'template', 'status' => 'sent',
            'provider_message_id' => 'wamid.TEST',
        ]);

        $this->postJson('/api/integrations/whatsapp/webhook', [
            'entry' => [['changes' => [['value' => ['statuses' => [['id' => 'wamid.TEST', 'status' => 'delivered', 'timestamp' => time()]]]]]]],
        ])->assertOk();

        $this->assertDatabaseHas('whatsapp_messages', ['id' => $message->id, 'status' => 'delivered']);
    }
}
