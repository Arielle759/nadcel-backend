<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_link_points_to_the_frontend(): void
    {
        Notification::fake();
        config(['app.frontend_url' => 'https://nadcel.example']);
        $user = User::factory()->create(['email' => 'awa@example.test']);

        $this->postJson('/api/auth/forgot-password', ['email' => 'awa@example.test'])->assertOk();

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $n) use ($user) {
            $url = $n->resetUrl($user);
            return str_starts_with($url, 'https://nadcel.example/reinitialiser-mot-de-passe?token=')
                && str_contains($url, 'email=awa%40example.test');
        });
    }

    public function test_unknown_email_gets_the_same_answer_and_no_email(): void
    {
        Notification::fake();
        $known = User::factory()->create(['email' => 'awa@example.test']);

        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'personne@example.test'])->assertOk()->json('message');
        $existing = $this->postJson('/api/auth/forgot-password', ['email' => 'awa@example.test'])->assertOk()->json('message');

        $this->assertSame($existing, $unknown);
        Notification::assertSentTimes(ResetPasswordNotification::class, 1);
        Notification::assertSentTo($known, ResetPasswordNotification::class);
    }

    public function test_valid_token_changes_password_and_closes_open_sessions(): void
    {
        $user = User::factory()->create(['email' => 'awa@example.test', 'password' => 'AncienMotDePasse123']);
        $oldToken = auth('api')->login($user);
        auth('api')->logout();
        $token = Password::createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token, 'email' => 'awa@example.test', 'password' => 'NouveauMotDePasse456',
        ])->assertOk();

        $this->assertTrue(Hash::check('NouveauMotDePasse456', $user->fresh()->password));
        // L'ancien jeton JWT est lié à l'ancien mot de passe : il ne fonctionne plus.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$oldToken}")->getJson('/api/appointments')->assertUnauthorized();
        // Le lien ne sert qu'une fois.
        $this->postJson('/api/auth/reset-password', [
            'token' => $token, 'email' => 'awa@example.test', 'password' => 'EncoreUnAutre789',
        ])->assertUnprocessable();
    }

    public function test_invalid_token_and_short_password_are_refused(): void
    {
        $user = User::factory()->create(['email' => 'awa@example.test']);

        $this->postJson('/api/auth/reset-password', [
            'token' => 'faux-jeton', 'email' => 'awa@example.test', 'password' => 'NouveauMotDePasse456',
        ])->assertUnprocessable()->assertJsonValidationErrors('token');

        $this->postJson('/api/auth/reset-password', [
            'token' => Password::createToken($user), 'email' => 'awa@example.test', 'password' => 'court',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }
}
