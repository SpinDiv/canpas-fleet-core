<?php

declare(strict_types=1);

namespace Canpas\Fleet\Domain;

use Canpas\Fleet\Audit\Audit;
use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Support\DomainError;
use Canpas\Fleet\Tenancy\Actor;

/** A signed-in person's own account: changing their password. */
final class Account
{
    public const MIN_LENGTH = 8;
    /** Wrong current-password guesses allowed per person before a 15-minute pause. */
    public const MAX_TRIES = 5;

    public static function changePassword(Actor $actor, string $current, string $new, string $confirm): void
    {
        $actor->require('cf_access_portal');
        $tryKey = 'cf_pwtry_' . $actor->userId;
        $tries = (int) get_transient($tryKey);
        if ($tries >= self::MAX_TRIES) {
            throw new DomainError('rate_limited', 'Too many wrong passwords. Please wait 15 minutes and try again.');
        }
        $user = get_userdata($actor->userId);
        if (!$user || !wp_check_password($current, $user->user_pass, $actor->userId)) {
            set_transient($tryKey, $tries + 1, 15 * MINUTE_IN_SECONDS);
            throw new DomainError('wrong_password', 'Your current password is not correct.');
        }
        delete_transient($tryKey);
        if ($new !== $confirm) {
            throw new DomainError('password_mismatch', 'The new passwords do not match.');
        }
        if (mb_strlen($new) < self::MIN_LENGTH) {
            throw new DomainError('weak_password', 'Use at least ' . self::MIN_LENGTH . ' characters for your new password.');
        }

        Db::transaction(static function () use ($actor, $new): void {
            wp_set_password($new, $actor->userId);
            Audit::log($actor->companyId, $actor->userId, 'password_changed', 'user', null);
        });

        // The new password makes the old login cookie invalid; issue a fresh one on this device.
        if ($actor->userId === get_current_user_id()) {
            // Keep "remember me" if the old cookie was a long one (more than two days left).
            $old = wp_parse_auth_cookie('', 'logged_in');
            $remember = is_array($old) && (int) $old['expiration'] - time() > 2 * DAY_IN_SECONDS;
            // Let nonces made later in this request use the new session, not the ended one.
            $keep = static function (string $cookie): void {
                $_COOKIE[LOGGED_IN_COOKIE] = $cookie;
            };
            add_action('set_logged_in_cookie', $keep);
            wp_set_current_user($actor->userId);
            wp_set_auth_cookie($actor->userId, $remember);
            remove_action('set_logged_in_cookie', $keep);
        }
    }
}
