<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthSessionTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_EMAIL = 'console-admin@example.test';

    private const ADMIN_PASSWORD = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        parent::setUp();
        config(['moderation.admin_emails' => [self::ADMIN_EMAIL]]);
    }

    public function test_csrf_bootstrap_responds_without_exposing_a_token_in_json(): void
    {
        $this->getJson('/api/auth/csrf')
            ->assertNoContent();
    }

    public function test_allowlisted_admin_can_sign_in_and_open_the_existing_session_guarded_api(): void
    {
        $admin = User::factory()->create([
            'email' => self::ADMIN_EMAIL,
            'password' => Hash::make(self::ADMIN_PASSWORD),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => strtoupper(self::ADMIN_EMAIL),
            'password' => self::ADMIN_PASSWORD,
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $admin->getKey())
            ->assertJsonPath('data.email', self::ADMIN_EMAIL)
            ->assertJsonMissingPath('data.password');

        $this->getJson('/api/moderation/access')
            ->assertOk()
            ->assertJsonPath('data.email', self::ADMIN_EMAIL);
    }

    public function test_non_allowlisted_account_cannot_create_an_admin_session(): void
    {
        User::factory()->create([
            'email' => 'ordinary-user@example.test',
            'password' => Hash::make(self::ADMIN_PASSWORD),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'ordinary-user@example.test',
            'password' => self::ADMIN_PASSWORD,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Email or password is incorrect, or this account is not authorized.');

        $this->getJson('/api/moderation/access')->assertUnauthorized();
    }

    public function test_invalid_password_does_not_authenticate_the_browser_session(): void
    {
        User::factory()->create([
            'email' => self::ADMIN_EMAIL,
            'password' => Hash::make(self::ADMIN_PASSWORD),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => self::ADMIN_EMAIL,
            'password' => 'wrong-password-value',
        ])->assertUnprocessable();

        $this->getJson('/api/moderation/access')->assertUnauthorized();
    }

    public function test_sign_out_invalidates_the_session(): void
    {
        User::factory()->create([
            'email' => self::ADMIN_EMAIL,
            'password' => Hash::make(self::ADMIN_PASSWORD),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => self::ADMIN_EMAIL,
            'password' => self::ADMIN_PASSWORD,
        ])->assertOk();

        $this->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Signed out successfully.');

        $this->getJson('/api/moderation/access')->assertUnauthorized();
    }
}
