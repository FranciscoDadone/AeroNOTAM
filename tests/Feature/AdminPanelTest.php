<?php

use App\Models\User;
use App\Models\WhatsappMessage;

function admin(): User
{
    return User::factory()->create(['is_admin' => true]);
}

it('sends an anonymous visitor to the login form', function () {
    $this->get('/admin')->assertRedirect(route('admin.login'));
    $this->get('/admin/mensajes')->assertRedirect(route('admin.login'));
});

/**
 * `users` is an ordinary table; a row in it is not a way into the panel.
 */
it('refuses a logged-in account that is not an admin', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin')
        ->assertForbidden();
});

it('logs an admin in and lands on the dashboard', function () {
    $user = admin();

    $this->post('/admin/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticatedAs($user);
});

/**
 * A valid password on a non-admin account must look exactly like a wrong one,
 * or the form becomes a way to enumerate which addresses exist.
 */
it('leaves a non-admin account logged out even with the right password', function () {
    $user = User::factory()->create();

    $this->post('/admin/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('rejects a wrong password', function () {
    $user = admin();

    $this->post('/admin/login', ['email' => $user->email, 'password' => 'nope'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('shows the metrics dashboard', function () {
    WhatsappMessage::factory()->count(3)->create(['phone' => 'whatsapp:+5491111111111']);
    WhatsappMessage::factory()->unmatched()->create();

    $this->actingAs(admin())
        ->get('/admin')
        ->assertOk()
        ->assertSee('Métricas')
        ->assertSee('EZE')
        ->assertSee('hola, cómo andás?', false);
});

it('lists incoming messages with the sender name and the bot reply', function () {
    WhatsappMessage::factory()->create([
        'phone' => 'whatsapp:+5491133334444',
        'profile_name' => 'Ana Pilot',
        'body' => 'notams ezeiza',
        'reply' => ['Sin NOTAM activos para EZEIZA.'],
    ]);

    $this->actingAs(admin())
        ->get('/admin/mensajes')
        ->assertOk()
        ->assertSee('Ana Pilot')
        ->assertSee('+5491133334444')
        ->assertSee('notams ezeiza');
});

it('shows a single message with everything the bot answered', function () {
    $message = WhatsappMessage::factory()->create([
        'body' => 'clima en aeroparque',
        'reply' => ['Primera parte de la respuesta.', 'Segunda parte.'],
    ]);

    $this->actingAs(admin())
        ->get(route('admin.messages.show', $message))
        ->assertOk()
        ->assertSee('clima en aeroparque')
        ->assertSee('Primera parte de la respuesta.')
        ->assertSee('Segunda parte.');
});

it('filters the log by phone, aerodrome and status', function () {
    WhatsappMessage::factory()->create(['phone' => 'whatsapp:+5491111111111', 'body' => 'notams ezeiza']);
    WhatsappMessage::factory()->create([
        'phone' => 'whatsapp:+5492222222222',
        'body' => 'notams aeroparque',
        'anac_code' => 'AER',
        'status' => WhatsappMessage::STATUS_FAILED,
    ]);

    $admin = admin();

    $this->actingAs($admin)->get('/admin/mensajes?phone=2222222222')
        ->assertSee('notams aeroparque')
        ->assertDontSee('notams ezeiza');

    $this->actingAs($admin)->get('/admin/mensajes?airport=EZE')
        ->assertSee('notams ezeiza')
        ->assertDontSee('notams aeroparque');

    $this->actingAs($admin)->get('/admin/mensajes?status=failed')
        ->assertSee('notams aeroparque')
        ->assertDontSee('notams ezeiza');
});

/**
 * The whole point of the filter: it is the queue of things the matcher missed.
 */
it('filters down to the messages that resolved no aerodrome', function () {
    WhatsappMessage::factory()->create(['body' => 'notams ezeiza']);
    WhatsappMessage::factory()->unmatched()->create();

    $this->actingAs(admin())
        ->get('/admin/mensajes?unmatched=1')
        ->assertSee('hola, cómo andás?', false)
        ->assertDontSee('notams ezeiza');
});

/**
 * The console command is the only way an account gets `is_admin`, so it has to
 * produce one that can actually log in.
 */
it('creates a usable admin account from the console', function () {
    $this->artisan('admin:create', ['email' => 'ops@flybot.test', '--password' => 'una-clave-larga'])
        ->assertSuccessful();

    expect(User::query()->where('email', 'ops@flybot.test')->sole()->is_admin)->toBeTrue();

    $this->post('/admin/login', ['email' => 'ops@flybot.test', 'password' => 'una-clave-larga'])
        ->assertRedirect(route('admin.dashboard'));
});

it('promotes an existing account instead of duplicating it', function () {
    $user = User::factory()->create(['email' => 'ops@flybot.test']);

    $this->artisan('admin:create', ['email' => 'ops@flybot.test', '--password' => 'una-clave-larga'])
        ->assertSuccessful();

    expect(User::query()->count())->toBe(1)
        ->and($user->fresh()?->is_admin)->toBeTrue();
});

it('refuses a password nobody should be using', function () {
    $this->artisan('admin:create', ['email' => 'ops@flybot.test', '--password' => 'corta'])
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

it('logs the admin out', function () {
    $this->actingAs(admin())
        ->post('/admin/logout')
        ->assertRedirect(route('admin.login'));

    $this->assertGuest();
});
