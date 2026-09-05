<?php

namespace Tests\Feature;

use App\Models\Researcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResearcherApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_researchers(): void
    {
        Researcher::factory()->count(3)->create();

        $response = $this->getJson('/api/researchers');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_can_create_researcher(): void
    {
        $payload = [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'affiliation' => 'Analytical Engine Society',
        ];

        $response = $this->postJson('/api/researchers', $payload);

        $response->assertStatus(201)
            ->assertJsonFragment(['email' => 'ada@example.com']);

        $this->assertDatabaseHas('researchers', ['email' => 'ada@example.com']);
    }

    public function test_researcher_creation_requires_valid_email(): void
    {
        $response = $this->postJson('/api/researchers', [
            'name' => 'No Email',
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }
}
