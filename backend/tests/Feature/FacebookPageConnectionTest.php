<?php

namespace Tests\Feature;

use App\Models\FacebookPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FacebookPageConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE_TOKEN = 'mock-page-token-only-valid-inside-this-test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.facebook.graph_version' => 'v26.0']);
    }

    public function test_valid_page_token_fetches_page_details_and_saves_one_page(): void
    {
        $user = User::factory()->create();
        $this->fakeSuccessfulPageResponse();

        $response = $this->actingAs($user)->postJson('/api/facebook-pages/connect', [
            'page_access_token' => self::PAGE_TOKEN,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.facebook_page_id', '123456789012345')
            ->assertJsonPath('data.page_name', 'Harbor Books')
            ->assertJsonPath('data.page_username', 'harborbooks')
            ->assertJsonPath('data.page_category', 'Book shop');

        $this->assertDatabaseCount('facebook_pages', 1);
        $this->assertDatabaseHas('facebook_pages', [
            'user_id' => $user->id,
            'facebook_page_id' => '123456789012345',
            'page_name' => 'Harbor Books',
        ]);

        Http::assertSent(function (ClientRequest $request): bool {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && str_contains($request->url(), '/v26.0/me')
                && ($query['fields'] ?? null) === 'id,name,username,category,picture'
                && $request->hasHeader('Authorization', 'Bearer '.self::PAGE_TOKEN)
                && ! array_key_exists('access_token', $query);
        });
    }

    public function test_invalid_token_is_rejected_without_saving_and_meta_error_is_not_exposed(): void
    {
        $user = User::factory()->create();
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'message' => 'Sensitive provider detail must not reach the client.',
                    'type' => 'OAuthException',
                    'code' => 190,
                ],
            ], 400),
        ]);

        $response = $this->actingAs($user)->postJson('/api/facebook-pages/connect', [
            'page_access_token' => self::PAGE_TOKEN,
        ]);

        $response
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Invalid Facebook Page Access Token.'])
            ->assertDontSee('Sensitive provider detail');
        $this->assertDatabaseCount('facebook_pages', 0);
    }

    public function test_expired_token_returns_a_specific_safe_error(): void
    {
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'message' => 'Token expired; provider trace hidden.',
                    'type' => 'OAuthException',
                    'code' => 190,
                    'error_subcode' => 463,
                ],
            ], 400),
        ]);

        $response = $this->actingAs(User::factory()->create())->postJson('/api/facebook-pages/connect', [
            'page_access_token' => self::PAGE_TOKEN,
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'This Facebook Page Access Token has expired. Generate a new Page Access Token.',
            )
            ->assertDontSee('provider trace');
    }

    public function test_missing_meta_permissions_return_a_clean_error(): void
    {
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'message' => 'Raw permissions response is private.',
                    'type' => 'OAuthException',
                    'code' => 200,
                ],
            ], 403),
        ]);

        $response = $this->actingAs(User::factory()->create())->postJson('/api/facebook-pages/connect', [
            'page_access_token' => self::PAGE_TOKEN,
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'This Page Access Token is missing permissions needed to read Page information.',
            )
            ->assertDontSee('Raw permissions response');
    }

    public function test_invalid_page_or_user_token_is_rejected(): void
    {
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'message' => 'Tried accessing an invalid Page field.',
                    'type' => 'GraphMethodException',
                    'code' => 100,
                ],
            ], 400),
        ]);

        $response = $this->actingAs(User::factory()->create())->postJson('/api/facebook-pages/connect', [
            'page_access_token' => self::PAGE_TOKEN,
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'The token did not resolve to a Facebook Page. Confirm the Page ID and Page Access Token.',
            )
            ->assertDontSee('invalid Page field');
    }

    public function test_page_rate_limit_is_returned_as_a_retryable_error(): void
    {
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'message' => 'Provider limit detail.',
                    'type' => 'OAuthException',
                    'code' => 4,
                ],
            ], 400),
        ]);

        $response = $this->actingAs(User::factory()->create())->postJson('/api/facebook-pages/connect', [
            'page_access_token' => self::PAGE_TOKEN,
        ]);

        $response
            ->assertStatus(429)
            ->assertJsonPath(
                'message',
                'Facebook is temporarily rate limiting requests. Please wait and try again.',
            )
            ->assertDontSee('Provider limit detail');
    }

    public function test_network_failure_returns_a_safe_service_error(): void
    {
        Http::fake(function (): never {
            throw new ConnectionException('Synthetic network failure.');
        });

        $response = $this->actingAs(User::factory()->create())->postJson('/api/facebook-pages/connect', [
            'page_access_token' => self::PAGE_TOKEN,
        ]);

        $response
            ->assertStatus(503)
            ->assertExactJson(['message' => 'Could not reach Facebook. Please try again.'])
            ->assertDontSee('Synthetic network failure');
    }

    public function test_meta_server_error_returns_a_clean_error(): void
    {
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'message' => 'Internal provider diagnostics.',
                    'type' => 'GraphMethodException',
                    'code' => 2,
                    'is_transient' => true,
                ],
            ], 500),
        ]);

        $response = $this->actingAs(User::factory()->create())->postJson('/api/facebook-pages/connect', [
            'page_access_token' => self::PAGE_TOKEN,
        ]);

        $response
            ->assertStatus(503)
            ->assertJsonPath('message', 'Facebook is temporarily unavailable. Please try again.')
            ->assertDontSee('Internal provider diagnostics');
    }

    public function test_reconnecting_the_same_page_updates_its_single_record_and_token(): void
    {
        $user = User::factory()->create();
        $secondToken = 'second-mock-page-token-for-this-test';
        Http::fake([
            '*' => Http::sequence()
                ->push($this->pagePayload(), 200)
                ->push($this->pagePayload(['name' => 'Harbor Books & Gifts']), 200),
        ]);

        $this->actingAs($user)->postJson('/api/facebook-pages/connect', [
            'page_access_token' => self::PAGE_TOKEN,
        ])->assertOk();

        $this->actingAs($user)->postJson('/api/facebook-pages/connect', [
            'page_access_token' => $secondToken,
        ])->assertOk()->assertJsonPath('data.page_name', 'Harbor Books & Gifts');

        $this->assertDatabaseCount('facebook_pages', 1);
        $page = FacebookPage::query()->sole();
        $this->assertSame($secondToken, $page->page_access_token);
        $this->assertSame('Harbor Books & Gifts', $page->page_name);
        $this->assertSame($user->id, $page->user_id);
    }

    public function test_a_different_user_cannot_take_over_an_already_connected_page(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        Http::fake([
            '*' => Http::sequence()
                ->push($this->pagePayload(), 200)
                ->push($this->pagePayload(['name' => 'Attempted takeover']), 200),
        ]);

        $this->actingAs($owner)->postJson('/api/facebook-pages/connect', [
            'page_access_token' => self::PAGE_TOKEN,
        ])->assertOk();

        $response = $this->actingAs($otherUser)->postJson('/api/facebook-pages/connect', [
            'page_access_token' => 'other-mock-page-token',
        ]);

        $response
            ->assertStatus(409)
            ->assertExactJson(['message' => 'This Facebook Page is already connected to another account.']);

        $page = FacebookPage::query()->sole();
        $this->assertSame($owner->id, $page->user_id);
        $this->assertSame('Harbor Books', $page->page_name);
        $this->assertSame(self::PAGE_TOKEN, $page->page_access_token);
    }

    public function test_access_token_is_encrypted_in_the_database(): void
    {
        $user = User::factory()->create();
        $this->fakeSuccessfulPageResponse();

        $this->actingAs($user)->postJson('/api/facebook-pages/connect', [
            'page_access_token' => self::PAGE_TOKEN,
        ])->assertOk();

        $storedValue = DB::table('facebook_pages')
            ->where('facebook_page_id', '123456789012345')
            ->value('page_access_token');

        $this->assertIsString($storedValue);
        $this->assertNotSame(self::PAGE_TOKEN, $storedValue);
        $this->assertSame(self::PAGE_TOKEN, Crypt::decryptString($storedValue));
        $this->assertSame(self::PAGE_TOKEN, FacebookPage::query()->sole()->page_access_token);
    }

    public function test_page_access_token_never_appears_in_the_api_response(): void
    {
        $this->fakeSuccessfulPageResponse();

        $response = $this->actingAs(User::factory()->create())->postJson('/api/facebook-pages/connect', [
            'page_access_token' => self::PAGE_TOKEN,
        ]);

        $response->assertOk();
        $this->assertStringNotContainsString(self::PAGE_TOKEN, $response->getContent());
        $this->assertArrayNotHasKey('page_access_token', $response->json('data'));
    }

    public function test_connection_endpoint_requires_an_authenticated_app_user(): void
    {
        $response = $this->postJson('/api/facebook-pages/connect', [
            'page_access_token' => self::PAGE_TOKEN,
        ]);

        $response->assertStatus(401);
        $this->assertDatabaseCount('facebook_pages', 0);
    }

    private function fakeSuccessfulPageResponse(): void
    {
        Http::fake([
            '*' => Http::response($this->pagePayload(), 200),
        ]);
    }

    /** @param array<string, mixed> $overrides
     *  @return array<string, mixed>
     */
    private function pagePayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => '123456789012345',
            'name' => 'Harbor Books',
            'username' => 'harborbooks',
            'category' => 'Book shop',
            'picture' => [
                'data' => [
                    'url' => 'https://example.test/page-picture.png',
                ],
            ],
        ], $overrides);
    }
}
