<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\HasBroker;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use HasBroker;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['warren.auth.enabled' => true]);
        $this->withoutVite();
        $this->makeBroker();
    }

    private function user(string $password = 'correct-horse'): User
    {
        return User::factory()->create(['email' => 'ana@example.com', 'password' => $password]);
    }

    public function test_guests_are_sent_to_the_login_page_and_json_gets_401(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->getJson($this->api('/topology'))->assertUnauthorized();
        $this->getJson($this->api('/events'))->assertUnauthorized();
        $this->getJson($this->api('/messages/1'))->assertUnauthorized();
        $this->getJson('/api/brokers')->assertUnauthorized();
    }

    public function test_the_login_page_says_when_there_are_no_users_yet(): void
    {
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Login')->where('noUsers', true));

        $this->user();
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->component('Login')->where('noUsers', false));
    }

    public function test_correct_credentials_log_in_and_land_where_the_user_was_going(): void
    {
        $user = $this->user();
        $this->get('/');

        $this->post('/login', ['email' => 'ana@example.com', 'password' => 'correct-horse'])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.user.email', 'ana@example.com')->where('auth.enabled', true));
    }

    public function test_wrong_credentials_are_rejected_without_saying_which_part_was_wrong(): void
    {
        $this->user();

        $this->post('/login', ['email' => 'ana@example.com', 'password' => 'nope'])->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $this->post('/login', ['email' => 'ghost@example.com', 'password' => 'nope'])->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $this->assertGuest();
    }

    public function test_repeated_failures_are_throttled_even_with_the_right_password(): void
    {
        $this->user();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'ana@example.com', 'password' => 'wrong']);
        }

        $this->post('/login', ['email' => 'ana@example.com', 'password' => 'correct-horse'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame(trans('auth.throttle', ['seconds' => RateLimiter::availableIn('login:ana@example.com|127.0.0.1')]), session('errors')->first('email'));
    }

    public function test_logging_out_ends_the_session(): void
    {
        $this->actingAs($this->user())->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->getJson($this->api('/topology'))->assertUnauthorized();
    }

    public function test_a_logged_in_user_skips_the_login_page(): void
    {
        $this->actingAs($this->user())->get('/login')->assertRedirect('/');
    }

    public function test_login_can_be_switched_off(): void
    {
        config(['warren.auth.enabled' => false]);

        $this->get('/')->assertOk();
        $this->get('/login')->assertRedirect('/');
    }

    public function test_the_user_command_creates_and_resets(): void
    {
        $this->artisan('warren:user', ['email' => 'bo@example.com', '--password' => 'long-enough-1'])->assertSuccessful();
        $this->assertTrue(\Hash::check('long-enough-1', User::where('email', 'bo@example.com')->value('password')));
        $this->assertSame('bo', User::where('email', 'bo@example.com')->value('name'));

        $this->artisan('warren:user', ['email' => 'bo@example.com', '--password' => 'brand-new-pass'])->assertSuccessful();
        $this->assertSame(1, User::count());
        $this->assertTrue(\Hash::check('brand-new-pass', User::value('password')));
    }

    public function test_the_user_command_refuses_bad_input(): void
    {
        $this->artisan('warren:user', ['email' => 'not-an-email', '--password' => 'long-enough-1'])->assertFailed();
        $this->artisan('warren:user', ['email' => 'bo@example.com', '--password' => 'short'])->assertFailed();
        $this->assertSame(0, User::count());
    }

    public function test_from_env_creates_the_first_user_once_and_never_overwrites(): void
    {
        config(['warren.auth.admin_email' => 'root@example.com', 'warren.auth.admin_password' => 'from-env-secret']);

        $this->artisan('warren:user --from-env')->assertSuccessful();
        $this->assertTrue(\Hash::check('from-env-secret', User::where('email', 'root@example.com')->value('password')));

        User::where('email', 'root@example.com')->first()->update(['password' => 'changed-by-the-user']);
        $this->artisan('warren:user --from-env')->assertSuccessful();
        $this->assertTrue(\Hash::check('changed-by-the-user', User::where('email', 'root@example.com')->value('password')));
    }

    public function test_from_env_without_variables_only_warns(): void
    {
        $this->artisan('warren:user --from-env')->expectsOutputToContain(trans('warren.user.no_users'))->assertSuccessful();
        $this->assertSame(0, User::count());
    }

    public function test_messages_are_in_brazilian_portuguese_by_default(): void
    {
        $this->assertSame('pt_BR', config('app.locale'));
        $this->user();

        $this->post('/login', ['email' => 'ana@example.com', 'password' => 'errada'])
            ->assertSessionHasErrors(['email' => 'E-mail ou senha incorretos.']);
        $this->post('/login', ['email' => '', 'password' => ''])
            ->assertSessionHasErrors(['email' => 'O campo e-mail é obrigatório.']);
    }
}
