<?php

namespace Tests\Feature\Feature\SalespersonApi;

use Tests\TestCase;

class AddCustomersToBeatTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    public function test_example(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
