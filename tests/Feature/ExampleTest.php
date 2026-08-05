<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_a_successful_response()
    {
        $this->instanceAlreadySetUp();

        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
