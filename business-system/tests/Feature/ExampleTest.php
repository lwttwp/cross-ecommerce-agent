<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_root_redirects_to_admin(): void
    {
        $response = $this->get('/');

        // / 重定向到 /admin（后台），未登录再 302 到 /login
        $response->assertRedirect();
    }

    public function test_login_page_renders(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_admin_requires_login(): void
    {
        $response = $this->get('/admin');

        $response->assertRedirect('/login');
    }
}
