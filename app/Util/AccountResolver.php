<?php
declare(strict_types=1);

namespace App\Util;

use App\Model\User;

/**
 * The only place that turns a member-typed string into an account, so login and
 * password reset can never disagree.
 *
 * Email and phone win over username: they are what password reset proves control
 * of, while anyone can register a username. Matching is left to the column
 * collation, which folds case and accents, so callers must act on the returned
 * row and never on the submitted spelling.
 */
final class AccountResolver
{
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_PHONE = 'phone';

    private const COLUMN_USERNAME = 'username';
    private const ORDER = [self::CHANNEL_EMAIL, self::CHANNEL_PHONE, self::COLUMN_USERNAME];
    private const MAX_LENGTH = 128;

    public static function normalize(mixed $input): string
    {
        if (!is_string($input)) {
            return '';
        }
        $account = trim($input);
        return mb_strlen($account) > self::MAX_LENGTH ? '' : $account;
    }

    public static function resolve(mixed $input): ?User
    {
        return self::locate($input)[0];
    }

    /**
     * The account login would pick, but only when it was reached through the channel
     * the code goes to. Anything else would reset an account login does not lead to.
     */
    public static function forReset(mixed $input, string $channel): ?User
    {
        [$user, $column] = self::locate($input);
        return $column === $channel ? $user : null;
    }

    /**
     * A legacy account whose username equals the resolved account's email or phone.
     */
    public static function shadowedBy(mixed $input, User $resolved): ?User
    {
        $account = self::normalize($input);
        if ($account === '') {
            return null;
        }
        $owner = User::query()->where(self::COLUMN_USERNAME, $account)->first();
        return $owner && (int)$owner->id !== (int)$resolved->id ? $owner : null;
    }

    /**
     * Whether another account already answers to this string in any of the three columns.
     */
    public static function taken(mixed $input, ?int $exceptId = null): bool
    {
        $account = self::normalize($input);
        if ($account === '') {
            return false;
        }
        foreach (self::ORDER as $column) {
            $query = User::query()->where($column, $account);
            if ($exceptId !== null) {
                $query->where('id', '<>', $exceptId);
            }
            if ($query->exists()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Usernames of this shape could squat a mailbox or phone before its owner registers.
     */
    public static function looksLikeContact(string $username): bool
    {
        $halfWidth = mb_convert_kana($username, 'a', 'UTF-8');
        return str_contains($halfWidth, '@') || Validation::phone($halfWidth);
    }

    /**
     * @return array{0: ?User, 1: ?string}
     */
    private static function locate(mixed $input): array
    {
        $account = self::normalize($input);
        if ($account === '') {
            return [null, null];
        }
        foreach (self::ORDER as $column) {
            $user = User::query()->where($column, $account)->first();
            if ($user) {
                return [$user, $column];
            }
        }
        return [null, null];
    }
}
