<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
        $this->configureAuthentication();
    }

    /**
     * Authentification : mot de passe, puis état du compte.
     *
     * Les colonnes `is_active` et `account_locked_until` existaient mais n'étaient lues
     * nulle part : un compte désactivé ou verrouillé pouvait ouvrir une session normalement.
     * Le contrôle a lieu après la vérification du mot de passe (il ne révèle donc rien à
     * qui ne le connaît pas) et avant le second facteur.
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request) {
            $provider = Auth::guard(config('fortify.guard'))->getProvider();

            // Timebox : le temps de réponse ne doit pas dire si l'adresse existe.
            $user = (new Timebox)->call(function () use ($provider, $request) {
                $user = $provider->retrieveByCredentials($request->only(Fortify::username(), 'password'));

                if (! $user || ! $provider->validateCredentials($user, ['password' => $request->password])) {
                    return null;
                }

                if (config('hashing.rehash_on_login', true) && method_exists($provider, 'rehashPasswordIfRequired')) {
                    $provider->rehashPasswordIfRequired($user, ['password' => $request->password]);
                }

                return $user;
            }, 200000);

            if (! $user) {
                return null; // Fortify répond « ces identifiants ne correspondent pas ».
            }

            if (! $user->is_active) {
                throw ValidationException::withMessages([
                    Fortify::username() => 'This account has been disabled.',
                ]);
            }

            if ($user->account_locked_until && $user->account_locked_until->isFuture()) {
                throw ValidationException::withMessages([
                    Fortify::username() => 'This account is temporarily locked. Please try again later.',
                ]);
            }

            return $user;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'canRegister' => Features::enabled(Features::registration()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/Register'));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
