<?php

namespace Tests\Feature;

use App\Models\Form;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_form_can_be_created_and_submitted(): void
    {
        $response = $this->post('/forms', [
            'title' => 'Internship Application',
            'description' => 'Apply for the summer internship.',
            'schema' => [
                'title' => 'Internship Application',
                'fields' => [
                    ['id' => 'name', 'type' => 'text', 'label' => 'Full name', 'key' => 'full_name', 'required' => true],
                    ['id' => 'email', 'type' => 'email', 'label' => 'Email', 'key' => 'email', 'required' => true],
                ],
            ],
        ]);

        $response->assertRedirect();

        $form = Form::latest()->first();
        $this->assertNotNull($form);
        $this->assertCount(2, $form->schema['fields']);

        $publicPage = $this->get('/forms/' . $form->public_uuid . '/fill');
        $publicPage->assertOk()->assertSee('Internship Application');

        $submitResponse = $this->post('/forms/' . $form->public_uuid . '/submit', [
            'answers' => [
                'full_name' => 'Ada Lovelace',
                'email' => 'ada@example.com',
            ],
        ]);

        $submitResponse->assertRedirect();
        $this->assertDatabaseHas('form_submissions', [
            'form_id' => $form->id,
        ]);
    }

    public function test_form_submission_state_is_scoped_to_the_current_session(): void
    {
        $response = $this->post('/forms', [
            'title' => 'Volunteer Signup',
            'description' => 'Volunteer sign-up form',
            'schema' => [
                'title' => 'Volunteer Signup',
                'fields' => [
                    ['id' => 'name', 'type' => 'text', 'label' => 'Name', 'key' => 'name', 'required' => true],
                ],
            ],
        ]);

        $response->assertRedirect();

        $form = Form::latest()->first();
        $this->assertNotNull($form);

        $this->post('/forms/' . $form->public_uuid . '/submit', [
            'answers' => [
                'name' => 'Ada Lovelace',
            ],
        ])->assertRedirect();

        $this->get('/forms/' . $form->public_uuid . '/fill')->assertOk()->assertSee('Response submitted');

        session()->flush();
        $this->get('/forms/' . $form->public_uuid . '/fill')->assertOk()->assertSee('Submit');
    }

    public function test_form_can_be_created_from_json_schema_string(): void
    {
        $response = $this->post('/forms', [
            'title' => 'Volunteer Signup',
            'description' => 'Volunteer sign-up form',
            'schema' => json_encode([
                'title' => 'Volunteer Signup',
                'fields' => [
                    ['id' => 'name', 'type' => 'text', 'label' => 'Name', 'key' => 'name', 'required' => true],
                ],
            ]),
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('forms', ['title' => 'Volunteer Signup']);
    }

    public function test_ai_generation_can_return_a_schema_from_prompt(): void
    {
        $response = $this->post('/ai/generate', [
            'prompt' => 'Internship application with phone, email and resume upload',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('schema.fields'));
    }
}
