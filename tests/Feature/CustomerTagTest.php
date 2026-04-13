<?php

namespace Tests\Feature;

use App\Models\Commercial;
use App\Models\Customer;
use App\Models\CustomerTag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerTagTest extends TestCase
{
    use RefreshDatabase;

    private User $authenticatedUser;

    private Commercial $commercial;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authenticatedUser = User::factory()->create();
        $this->commercial = Commercial::create([
            'name' => 'Commercial Test',
            'phone_number' => '221700000001',
            'gender' => 'male',
            'user_id' => $this->authenticatedUser->id,
        ]);
    }

    private function makeCustomer(string $name = 'Client Test'): Customer
    {
        return Customer::create([
            'name' => $name,
            'phone_number' => '221700000999',
            'owner_number' => '221700000998',
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->commercial->id,
        ]);
    }

    // ─── Tag CRUD ───────────────────────────────────────────────────────────────

    public function test_can_list_customer_tags(): void
    {
        CustomerTag::factory()->create(['name' => 'VIP', 'color' => '#1976D2']);
        CustomerTag::factory()->create(['name' => 'Prospect chaud', 'color' => '#388E3C']);

        $response = $this->actingAs($this->authenticatedUser)
            ->get(route('customer-tags.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Clients/CustomerTags')
            ->has('customerTags', 2)
        );
    }

    public function test_can_create_a_customer_tag(): void
    {
        $response = $this->actingAs($this->authenticatedUser)
            ->post(route('customer-tags.store'), [
                'name' => 'VIP',
                'color' => '#1976D2',
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('customer_tags', [
            'name' => 'VIP',
            'color' => '#1976D2',
        ]);
    }

    public function test_customer_tag_name_must_be_unique(): void
    {
        CustomerTag::factory()->create(['name' => 'VIP']);

        $response = $this->actingAs($this->authenticatedUser)
            ->post(route('customer-tags.store'), [
                'name' => 'VIP',
                'color' => '#388E3C',
            ]);

        $response->assertSessionHasErrors('name');
        $this->assertDatabaseCount('customer_tags', 1);
    }

    public function test_can_update_a_customer_tag(): void
    {
        $customerTag = CustomerTag::factory()->create(['name' => 'Ancien nom', 'color' => '#1976D2']);

        $response = $this->actingAs($this->authenticatedUser)
            ->put(route('customer-tags.update', $customerTag), [
                'name' => 'Nouveau nom',
                'color' => '#388E3C',
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('customer_tags', [
            'id' => $customerTag->id,
            'name' => 'Nouveau nom',
            'color' => '#388E3C',
        ]);
    }

    public function test_can_delete_a_customer_tag(): void
    {
        $customerTag = CustomerTag::factory()->create(['name' => 'À supprimer']);

        $response = $this->actingAs($this->authenticatedUser)
            ->delete(route('customer-tags.destroy', $customerTag));

        $response->assertRedirect();
        $this->assertDatabaseMissing('customer_tags', ['id' => $customerTag->id]);
    }

    public function test_deleting_a_tag_removes_it_from_customers(): void
    {
        $customerTag = CustomerTag::factory()->create(['name' => 'À supprimer']);
        $customer = $this->makeCustomer();
        $customer->tags()->attach($customerTag);

        $this->assertDatabaseHas('customer_customer_tag', [
            'customer_id' => $customer->id,
            'customer_tag_id' => $customerTag->id,
        ]);

        $this->actingAs($this->authenticatedUser)
            ->delete(route('customer-tags.destroy', $customerTag));

        $this->assertDatabaseMissing('customer_customer_tag', [
            'customer_id' => $customer->id,
            'customer_tag_id' => $customerTag->id,
        ]);
    }

    // ─── Customer-tag syncing ───────────────────────────────────────────────────

    public function test_can_attach_tags_when_creating_a_customer(): void
    {
        $tagVip = CustomerTag::factory()->create(['name' => 'VIP']);
        $tagProspect = CustomerTag::factory()->create(['name' => 'Prospect']);

        $this->actingAs($this->authenticatedUser)
            ->post(route('clients.store'), [
                'name' => 'Client Avec Tags',
                'phone_number' => '700000000',
                'owner_number' => '700000001',
                'gps_coordinates' => '14.6928,17.4467',
                'commercial_id' => $this->commercial->id,
                'tag_ids' => [$tagVip->id, $tagProspect->id],
            ]);

        $customer = Customer::where('name', 'Client Avec Tags')->firstOrFail();

        $this->assertCount(2, $customer->tags);
        $this->assertTrue($customer->tags->contains($tagVip));
        $this->assertTrue($customer->tags->contains($tagProspect));
    }

    public function test_can_update_customer_tags_via_customer_update(): void
    {
        $tagVip = CustomerTag::factory()->create(['name' => 'VIP']);
        $tagProspect = CustomerTag::factory()->create(['name' => 'Prospect']);
        $customer = $this->makeCustomer();
        $customer->tags()->attach($tagVip);

        $this->actingAs($this->authenticatedUser)
            ->put(route('clients.update', $customer), [
                'name' => $customer->name,
                'phone_number' => $customer->phone_number,
                'tag_ids' => [$tagProspect->id],
            ]);

        $customer->refresh();
        $this->assertCount(1, $customer->tags);
        $this->assertTrue($customer->tags->contains($tagProspect));
        $this->assertFalse($customer->tags->contains($tagVip));
    }

    public function test_can_remove_all_tags_from_a_customer(): void
    {
        $customerTag = CustomerTag::factory()->create(['name' => 'VIP']);
        $customer = $this->makeCustomer();
        $customer->tags()->attach($customerTag);

        $this->actingAs($this->authenticatedUser)
            ->put(route('clients.update', $customer), [
                'name' => $customer->name,
                'phone_number' => $customer->phone_number,
                'tag_ids' => [],
            ]);

        $customer->refresh();
        $this->assertCount(0, $customer->tags);
    }

    public function test_customer_index_includes_tags_in_response(): void
    {
        $customerTag = CustomerTag::factory()->create(['name' => 'VIP', 'color' => '#1976D2']);
        $customer = $this->makeCustomer();
        $customer->tags()->attach($customerTag);

        $response = $this->actingAs($this->authenticatedUser)
            ->get(route('clients.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Clients/Index')
            ->has('allTags', 1)
        );
    }

    public function test_can_filter_customers_by_tag(): void
    {
        $tagVip = CustomerTag::factory()->create(['name' => 'VIP']);
        $tagAutre = CustomerTag::factory()->create(['name' => 'Autre']);

        $customerWithVipTag = $this->makeCustomer('Client VIP');
        $customerWithAutreTag = $this->makeCustomer('Autre Client');

        $customerWithVipTag->tags()->attach($tagVip);
        $customerWithAutreTag->tags()->attach($tagAutre);

        $response = $this->actingAs($this->authenticatedUser)
            ->get(route('clients.index', ['tag_id' => $tagVip->id]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Clients/Index')
            ->where('clients.total', 1)
        );
    }
}
