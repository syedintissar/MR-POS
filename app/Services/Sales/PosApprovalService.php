<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Manager approval at the till (SRS AUTH-09). When a sale needs more than the cashier may do (a bigger discount, a changed price,
 * a price below the minimum, the wholesale list) a manager types their own username and password on the cashier's screen and
 * gets back a token. The token is tied to the cashier who asked, lasts two minutes and is used once. The password itself is
 * never stored or logged.
 */
class PosApprovalService
{
    public const TTL_SECONDS = 120;

    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{token: string, approver: User}
     *
     * @throws DomainException wrong login, not allowed to approve, or too many attempts
     */
    public function grant(User $requester, string $username, string $password): array
    {
        $throttleKey = 'pos-approval:'.$requester->id;
        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($throttleKey) / 60);
            throw new DomainException("Too many approval attempts. Try again in {$minutes} minute(s).");
        }

        $approver = User::query()->where('username', trim($username))->first();
        $valid = $approver && $approver->is_active && Hash::check($password, $approver->password);

        if (! $valid || ! $approver->can('sales.approve')) {
            RateLimiter::hit($throttleKey, 300);
            $this->audit->log('pos_approval_failed', 'sales', 'Failed approval attempt for the account "'.mb_substr(trim($username), 0, 60).'"', user: $requester);

            throw new DomainException('Approval refused: wrong username or password, or that account may not approve sales.');
        }

        RateLimiter::clear($throttleKey);

        $token = Str::random(40);
        Cache::put($this->key($token), ['approver_id' => $approver->id, 'requester_id' => $requester->id], now()->addSeconds(self::TTL_SECONDS));

        $this->audit->log('pos_approval_granted', 'sales', "{$approver->name} gave approval at the till of {$requester->name}", user: $requester, new: ['approver' => $approver->username]);

        return ['token' => $token, 'approver' => $approver];
    }

    /** The approver behind a valid, unused token that was issued to this cashier; null otherwise. Does not use the token up. */
    public function peek(?string $token, User $requester): ?User
    {
        if ($token === null || $token === '') {
            return null;
        }

        $entry = Cache::get($this->key($token));
        if (! is_array($entry) || (int) $entry['requester_id'] !== $requester->id) {
            return null;
        }

        $approver = User::query()->find((int) $entry['approver_id']);

        return $approver && $approver->is_active ? $approver : null;
    }

    /** Use the token up (called once the sale is safely committed). */
    public function consume(string $token): void
    {
        Cache::forget($this->key($token));
    }

    private function key(string $token): string
    {
        return 'pos.approval.'.hash('sha256', $token);
    }
}
