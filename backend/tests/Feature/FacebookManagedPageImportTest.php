<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FacebookManagedPageImportTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_EMAIL = 'bulk-pages-admin@example.test';

    private const SECOND_ADMIN_EMAIL = 'second-bulk-admin@example.test';

    private const USER_TOKEN = 'test-user-token-never-store-or-return';

    private const PAGE_ONE_TOKEN = 'test-page-one-token-never-return';

    private const PAGE_TWO_TOKEN = 'test-page-two-token-never-return';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.facebook.graph_version' => 'v26.0',
            'moderation.admin_emails' => [self::ADMIN_EMAIL, self::SECOND_ADMIN_EMAIL],
            'cache.default' => 'array',
        ]);
        Cache::flush();
    }

    public function test_user_selects_pages_before_import_and_tokens_stay_out_of_the_browser(): void
    {
        $admin = User::factory()->create(['email' => self::ADMIN_EMAIL]);
        Http::fake(function (ClientRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/me/accounts')) {
                return Http::response([
                    'data' => [
                        ['id' => 'bulk-page-1001', 'name' => 'Northwind Books', 'access_token' => self::PAGE_ONE_TOKEN],
                        ['id' => 'bulk-page-1002', 'name' => 'Southwind Books', 'access_token' => self::PAGE_TWO_TOKEN],
                    ],
                ], 200);
            }

            $authorization = $request->header('Authorization')[0] ?? '';
            if ($authorization === 'Bearer '.self::PAGE_ONE_TOKEN) {
                return Http::response([
                    'id' => 'bulk-page-1001',
                    'name' => 'Northwind Books',
                    'username' => 'northwindbooks',
                    'category' => 'Book shop',
                    'picture' => ['data' => ['url' => 'https://example.test/northwind.png']],
                ], 200);
            }

            if ($authorization === 'Bearer '.self::PAGE_TWO_TOKEN) {
                return Http::response([
                    'id' => 'bulk-page-1002',
                    'name' => 'Southwind Books',
                    'username' => 'southwindbooks',
                    'category' => 'Book shop',
                    'picture' => ['data' => ['url' => 'https://example.test/southwind.png']],
                ], 200);
            }

            return Http::response(['error' => ['code' => 190]], 400);
        });

        $discoveryResponse = $this->actingAs($admin)->postJson('/api/facebook-pages/discover-managed', [
            'user_access_token' => self::USER_TOKEN,
        ]);

        $discoveryResponse->assertOk()
            ->assertJsonPath('data.expires_in_seconds', 300)
            ->assertJsonCount(2, 'data.pages')
            ->assertJsonPath('data.pages.0.facebook_page_id', 'bulk-page-1001')
            ->assertJsonPath('data.pages.0.page_name', 'Northwind Books')
            ->assertJsonPath('data.pages.1.facebook_page_id', 'bulk-page-1002')
            ->assertJsonPath('data.pages.1.page_name', 'Southwind Books')
            ->assertJsonMissingPath('data.pages.0.access_token')
            ->assertDontSee(self::USER_TOKEN)
            ->assertDontSee(self::PAGE_ONE_TOKEN)
            ->assertDontSee(self::PAGE_TWO_TOKEN);

        $importId = $discoveryResponse->json('data.import_id');
        $this->assertIsString($importId);
        $this->assertDatabaseCount('facebook_pages', 0);

        $cacheKey = 'facebook-page-import:'.hash('sha256', $importId);
        $cachedImport = Cache::get($cacheKey);
        $this->assertIsArray($cachedImport);
        $this->assertSame((string) $admin->getKey(), $cachedImport['user_id']);
        $this->assertNotSame(self::PAGE_ONE_TOKEN, $cachedImport['pages']['bulk-page-1001']['encrypted_access_token']);
        $this->assertNotSame(self::PAGE_TWO_TOKEN, $cachedImport['pages']['bulk-page-1002']['encrypted_access_token']);
        $this->assertSame(self::PAGE_ONE_TOKEN, Crypt::decryptString($cachedImport['pages']['bulk-page-1001']['encrypted_access_token']));
        $this->assertSame(self::PAGE_TWO_TOKEN, Crypt::decryptString($cachedImport['pages']['bulk-page-1002']['encrypted_access_token']));
        $this->assertStringNotContainsString(self::USER_TOKEN, serialize($cachedImport));

        $importResponse = $this->postJson('/api/facebook-pages/import-managed', [
            'import_id' => $importId,
            'facebook_page_ids' => ['bulk-page-1002'],
        ]);

        $importResponse->assertOk()
            ->assertJsonPath('data.connected', 1)
            ->assertJsonPath('data.failed', 0)
            ->assertJsonPath('data.pages.0.facebook_page_id', 'bulk-page-1002')
            ->assertJsonPath('data.pages.0.status', 'connected')
            ->assertDontSee(self::USER_TOKEN)
            ->assertDontSee(self::PAGE_ONE_TOKEN)
            ->assertDontSee(self::PAGE_TWO_TOKEN);

        $this->assertDatabaseCount('facebook_pages', 1);
        $this->assertDatabaseHas('facebook_pages', [
            'user_id' => $admin->getKey(),
            'facebook_page_id' => 'bulk-page-1002',
            'page_name' => 'Southwind Books',
        ]);
        $storedTokens = DB::table('facebook_pages')->pluck('page_access_token')->all();
        $this->assertNotContains(self::USER_TOKEN, $storedTokens);
        $this->assertNotContains(self::PAGE_ONE_TOKEN, $storedTokens);
        $this->assertNotContains(self::PAGE_TWO_TOKEN, $storedTokens);
        $this->assertNull(Cache::get($cacheKey), 'Import cache should be deleted after selected Page processing.');

        Http::assertSent(function (ClientRequest $request): bool {
            if (! str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/me/accounts')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->hasHeader('Authorization', 'Bearer '.self::USER_TOKEN)
                && ($query['fields'] ?? null) === 'id,name,access_token'
                && ! array_key_exists('access_token', $query);
        });
        Http::assertSentCount(1);
        Http::assertNotSent(function (ClientRequest $request): bool {
            return $request->hasHeader('Authorization', 'Bearer '.self::PAGE_ONE_TOKEN)
                || $request->hasHeader('Authorization', 'Bearer '.self::PAGE_TWO_TOKEN);
        });
    }

    public function test_missing_page_list_permission_returns_safe_message_without_creating_discovery(): void
    {
        $admin = User::factory()->create(['email' => self::ADMIN_EMAIL]);
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'message' => 'Provider permission details must stay private.',
                    'type' => 'OAuthException',
                    'code' => 200,
                ],
            ], 403),
        ]);

        $this->actingAs($admin)->postJson('/api/facebook-pages/discover-managed', [
            'user_access_token' => self::USER_TOKEN,
        ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'The User Access Token cannot list these Pages. Grant pages_show_list and confirm the Facebook account can access them.',
            )
            ->assertDontSee(self::USER_TOKEN)
            ->assertDontSee('Provider permission details');

        $this->assertDatabaseCount('facebook_pages', 0);
    }

    public function test_discovery_import_id_is_bound_to_the_admin_who_created_it(): void
    {
        $admin = User::factory()->create(['email' => self::ADMIN_EMAIL]);
        $otherAdmin = User::factory()->create(['email' => self::SECOND_ADMIN_EMAIL]);
        Http::fake([
            '*' => Http::response([
                'data' => [
                    ['id' => 'bulk-page-1001', 'name' => 'Northwind Books', 'access_token' => self::PAGE_ONE_TOKEN],
                ],
            ], 200),
        ]);

        $discoveryResponse = $this->actingAs($admin)->postJson('/api/facebook-pages/discover-managed', [
            'user_access_token' => self::USER_TOKEN,
        ])->assertOk();
        $importId = $discoveryResponse->json('data.import_id');

        $this->actingAs($otherAdmin)->postJson('/api/facebook-pages/import-managed', [
            'import_id' => $importId,
            'facebook_page_ids' => ['bulk-page-1001'],
        ])->assertNotFound();

        $this->assertDatabaseCount('facebook_pages', 0);
        Http::assertSentCount(1);
    }

    public function test_non_admin_cannot_discover_or_import_managed_pages(): void
    {
        $nonAdmin = User::factory()->create(['email' => 'ordinary-user@example.test']);
        Http::fake();

        $this->actingAs($nonAdmin)->postJson('/api/facebook-pages/discover-managed', [
            'user_access_token' => self::USER_TOKEN,
        ])->assertForbidden();

        $this->actingAs($nonAdmin)->postJson('/api/facebook-pages/import-managed', [
            'import_id' => '5e89109a-7589-46d0-b6da-f9720325d221',
            'facebook_page_ids' => ['bulk-page-1001'],
        ])->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseCount('facebook_pages', 0);
    }
}
