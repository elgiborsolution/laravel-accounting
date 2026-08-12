<?php

namespace ESolution\LaravelAccounting\Tests\Feature;

use ESolution\LaravelAccounting\Contracts\AfterApiHook;
use ESolution\LaravelAccounting\Contracts\BeforeApiHook;
use ESolution\LaravelAccounting\Models\Service;
use ESolution\LaravelAccounting\Services\ServiceManagementService;
use ESolution\LaravelAccounting\Support\ApiContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ServiceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_can_create_service_with_integer_updated_by(): void
    {
        $response = $this->postJson('/api/accounting/services', [
            'service_code' => 'SERVICE-INT-UPDATED-BY',
            'service_name' => 'Service Integer Updated By',
            'module_name' => 'FIN',
            'updated_by' => 947,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.service_code', 'SERVICE-INT-UPDATED-BY')
            ->assertJsonPath('data.updated_by', '947');

        $this->assertDatabaseHas('acc_services', [
            'service_code' => 'SERVICE-INT-UPDATED-BY',
            'updated_by' => '947',
        ]);
    }

    public function test_can_update_service_with_uuid_updated_by(): void
    {
        $service = Service::create([
            'service_code' => 'SERVICE-UUID-UPDATED-BY',
            'service_name' => 'Service UUID Updated By',
            'module_name' => 'FIN',
            'description' => null,
            'status' => true,
        ]);

        $uuid = '550e8400-e29b-41d4-a716-446655440000';

        $response = $this->putJson("/api/accounting/services/{$service->id}", [
            'service_name' => 'Service UUID Updated By Updated',
            'updated_by' => $uuid,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.service_name', 'Service UUID Updated By Updated')
            ->assertJsonPath('data.updated_by', $uuid);

        $this->assertDatabaseHas('acc_services', [
            'id' => $service->id,
            'updated_by' => $uuid,
        ]);
    }

    public function test_updated_by_remains_null_when_omitted(): void
    {
        $response = $this->postJson('/api/accounting/services', [
            'service_code' => 'SERVICE-NULL-UPDATED-BY',
            'service_name' => 'Service Null Updated By',
            'module_name' => 'FIN',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.updated_by', null);

        $this->assertDatabaseHas('acc_services', [
            'service_code' => 'SERVICE-NULL-UPDATED-BY',
            'updated_by' => null,
        ]);
    }

    public function test_can_filter_services_by_true_status(): void
    {
        Service::create([
            'service_code' => 'SERVICE-ACTIVE-FILTER',
            'service_name' => 'Active Service',
            'module_name' => 'FIN',
            'status' => true,
        ]);
        Service::create([
            'service_code' => 'SERVICE-INACTIVE-FILTER',
            'service_name' => 'Inactive Service',
            'module_name' => 'FIN',
            'status' => false,
        ]);

        $response = $this->getJson('/api/accounting/services?status=true');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.service_code', 'SERVICE-ACTIVE-FILTER');
    }

    public function test_can_filter_services_by_false_status(): void
    {
        Service::create([
            'service_code' => 'SERVICE-ACTIVE-FILTER-FALSE',
            'service_name' => 'Active Service',
            'module_name' => 'FIN',
            'status' => true,
        ]);
        Service::create([
            'service_code' => 'SERVICE-INACTIVE-FILTER-FALSE',
            'service_name' => 'Inactive Service',
            'module_name' => 'FIN',
            'status' => false,
        ]);

        $response = $this->getJson('/api/accounting/services?status=false');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.service_code', 'SERVICE-INACTIVE-FILTER-FALSE');
    }

    public function test_services_are_unfiltered_when_status_is_omitted(): void
    {
        Service::create([
            'service_code' => 'SERVICE-ACTIVE-UNFILTERED',
            'service_name' => 'Active Service',
            'module_name' => 'FIN',
            'status' => true,
        ]);
        Service::create([
            'service_code' => 'SERVICE-INACTIVE-UNFILTERED',
            'service_name' => 'Inactive Service',
            'module_name' => 'FIN',
            'status' => false,
        ]);

        $response = $this->getJson('/api/accounting/services');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_service_api_hooks_can_modify_payload_and_result(): void
    {
        config()->set('accounting.hooks.before', BeforeServiceLifecycleTestHook::class);
        config()->set('accounting.hooks.after', AfterServiceLifecycleTestHook::class);

        $response = $this->postJson('/api/accounting/services', [
            'service_code' => 'SERVICE-HOOK-API',
            'service_name' => 'Original Name',
            'module_name' => 'FIN',
            'description' => 'Original Description',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.service_name', 'Original Name [before-hook]')
            ->assertJsonPath('data.hook_marker', 'after-hook')
            ->assertJsonPath('data.hook_action', 'services.store');

        $this->assertDatabaseHas('acc_services', [
            'service_code' => 'SERVICE-HOOK-API',
            'service_name' => 'Original Name [before-hook]',
        ]);
    }

    public function test_service_internal_calls_use_same_hook_lifecycle(): void
    {
        config()->set('accounting.hooks.before', BeforeServiceLifecycleTestHook::class);
        config()->set('accounting.hooks.after', AfterServiceLifecycleTestHook::class);

        $service = app(ServiceManagementService::class)->create([
            'service_code' => 'SERVICE-HOOK-INTERNAL',
            'service_name' => 'Internal Original',
            'module_name' => 'FIN',
            'description' => 'Internal Description',
        ], Request::create('/internal/services', 'POST'));

        $this->assertSame('Internal Original [before-hook]', $service->service_name);
        $this->assertSame('after-hook', $service->getAttribute('hook_marker'));
        $this->assertSame('services.store', $service->getAttribute('hook_action'));
    }
}

class BeforeServiceLifecycleTestHook implements BeforeApiHook
{
    public function handle(ApiContext $context): void
    {
        $payload = $context->payload();

        if (isset($payload['service_name'])) {
            $payload['service_name'] .= ' [before-hook]';
        }

        $context->setPayload($payload);
    }
}

class AfterServiceLifecycleTestHook implements AfterApiHook
{
    public function handle(ApiContext $context, mixed $result): mixed
    {
        if ($result instanceof Service) {
            $result->setAttribute('hook_marker', 'after-hook');
            $result->setAttribute('hook_action', $context->action());
        }

        return $result;
    }
}
