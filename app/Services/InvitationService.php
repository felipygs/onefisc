<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Invitation;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InvitationService
{
    public function __construct(protected PlanLimitService $limits) {}

    /**
     * Create an invitation: random 32-byte token, sha256 hash stored, raw token only in the link.
     *
     * @return array{invitation: Invitation, token: string}
     */
    public function invite(Account $account, string $name, string $email, string $role): array
    {
        $this->limits->ensureUserCapacity($account);

        $token = bin2hex(random_bytes(32));

        $invitation = Invitation::create([
            'account_id' => $account->id,
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
        ]);
        $invitation->setAttribute('token', $token);
        $invitation->syncOriginal();

        return ['invitation' => $invitation, 'token' => $token];
    }

    /**
     * Find a pending, unexpired invitation by raw token.
     */
    public function findByToken(string $token): ?Invitation
    {
        $invitation = Invitation::query()
            ->withoutGlobalScope('account')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        $expired = ! $invitation instanceof Invitation
            || $invitation->accepted_at !== null
            || ! $invitation->expires_at instanceof CarbonInterface
            || $invitation->expires_at->isPast();

        if ($expired) {
            return null;
        }

        return $invitation;
    }

    /**
     * Accept an invitation: creates the user with the invitation role/account, marks accepted, logs in.
     */
    public function accept(string $token, string $password, string $passwordConfirmation): User
    {
        $validated = Validator::make(
            ['password' => $password, 'password_confirmation' => $passwordConfirmation],
            ['password' => ['required', 'string', 'min:8', 'confirmed']]
        )->validate();

        $invitation = $this->findByToken($token);

        if (! $invitation instanceof Invitation) {
            throw ValidationException::withMessages(['token' => 'Este convite é inválido ou expirou.']);
        }

        /** @var string $plainPassword */
        $plainPassword = $validated['password'];

        return DB::transaction(function () use ($invitation, $plainPassword): User {
            if (User::query()->where('email', $invitation->email)->exists()) {
                throw ValidationException::withMessages(['email' => 'Este e-mail já está em uso.']);
            }

            $account = Account::query()->withoutGlobalScopes()->findOrFail($invitation->account_id);
            app(PlanLimitService::class)->ensureUserCapacity($account);

            $user = new User([
                'name' => $invitation->name,
                'email' => $invitation->email,
                'password' => $plainPassword,
            ]);
            $user->account_id = $invitation->account_id;
            $user->role = $invitation->role;
            $user->save();

            $invitation->accepted_at = now();
            $invitation->save();

            Auth::login($user);

            return $user;
        });
    }
}
