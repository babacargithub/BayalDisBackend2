<?php

namespace Tests\Feature;

use App\Models\Commercial;
use App\Models\Customer;
use App\Models\User;
use App\Services\CustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerCacheTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Commercial $commercial;

    private CustomerService $customerService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->commercial = Commercial::factory()->create(['user_id' => $this->user->id]);
        $this->customerService = $this->app->make(CustomerService::class);

        Customer::create([
            'name' => 'Client A',
            'phone_number' => '221700000001',
            'owner_number' => '221700000001',
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->commercial->id,
        ]);

        Customer::create([
            'name' => 'Client B',
            'phone_number' => '221700000002',
            'owner_number' => '221700000002',
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->commercial->id,
        ]);
    }

    public function test_second_call_to_get_customers_for_commercial_hits_no_database(): void
    {
        $this->customerService->getCustomersForCommercial($this->commercial);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $customers = $this->customerService->getCustomersForCommercial($this->commercial);

        $this->assertCount(0, DB::getQueryLog());
        $this->assertCount(2, $customers);

        DB::disableQueryLog();
    }

    public function test_cache_is_invalidated_when_customer_is_created_via_service(): void
    {
        $this->customerService->getCustomersForCommercial($this->commercial);

        $this->customerService->createCustomer($this->commercial, [
            'name' => 'Nouveau Client',
            'phone_number' => '221700000003',
            'owner_number' => '221700000003',
            'gps_coordinates' => '14.6928,17.4467',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $customers = $this->customerService->getCustomersForCommercial($this->commercial);

        $this->assertGreaterThan(0, count(DB::getQueryLog()));
        $this->assertCount(3, $customers);

        DB::disableQueryLog();
    }

    public function test_cache_is_invalidated_when_customer_is_created_from_back_office(): void
    {
        $this->customerService->getCustomersForCommercial($this->commercial);

        $this->customerService->storeCustomerWithTags([
            'name' => 'Client Back Office',
            'phone_number' => '221700000010',
            'owner_number' => '221700000010',
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->commercial->id,
        ], []);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $customers = $this->customerService->getCustomersForCommercial($this->commercial);

        $this->assertGreaterThan(0, count(DB::getQueryLog()));
        $this->assertCount(3, $customers);

        DB::disableQueryLog();
    }

    public function test_cache_is_invalidated_when_customer_is_updated(): void
    {
        $customer = Customer::where('commercial_id', $this->commercial->id)->first();

        $this->customerService->getCustomersForCommercial($this->commercial);

        $this->customerService->updateCustomer($customer, ['name' => 'Nom Modifié']);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $customers = $this->customerService->getCustomersForCommercial($this->commercial);

        $this->assertGreaterThan(0, count(DB::getQueryLog()));
        $this->assertTrue($customers->contains('name', 'Nom Modifié'));

        DB::disableQueryLog();
    }

    public function test_cache_is_invalidated_when_customer_is_deleted(): void
    {
        $customer = Customer::where('commercial_id', $this->commercial->id)->first();

        $this->customerService->getCustomersForCommercial($this->commercial);

        $this->customerService->deleteCustomer($customer);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $customers = $this->customerService->getCustomersForCommercial($this->commercial);

        $this->assertGreaterThan(0, count(DB::getQueryLog()));
        $this->assertCount(1, $customers);

        DB::disableQueryLog();
    }
}
