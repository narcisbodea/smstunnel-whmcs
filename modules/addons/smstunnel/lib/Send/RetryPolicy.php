<?php
/**
 * SMSTunnel for WHMCS.
 *
 * @author    NARBOWEB SRL (https://smstunnel.io)
 * @copyright 2026 NARBOWEB SRL
 * @license   MIT
 */

namespace SmsTunnel\Whmcs\Send;

use SmsTunnel\Whmcs\Api\ApiException;
use SmsTunnel\Whmcs\Api\TransportException;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

/**
 * When a text that did not go out is tried again.
 *
 * - Tried again, by the WHMCS cron, always with the text's own Idempotency-Key (so SMSTunnel never
 *   sends it twice): a network error or timeout, a 5xx, a 429 (after its Retry-After), and a 409
 *   IDEMPOTENCY_KEY_IN_PROGRESS (the first attempt is still running on SMSTunnel's side; asking again
 *   with the same key can only return its answer).
 * - Never tried again: any other 4xx (a wrong key, a missing permission, a number SMSTunnel refuses...),
 *   and a 5xx that SMSTunnel answered from its record of the key (Idempotent-Replayed): that error
 *   stays the answer for the key, and a new key could send the text twice.
 * - At most 6 attempts, 5, 15, 60, 180 and 360 minutes apart (or as Retry-After says), and never later
 *   than 23 hours after the first: SMSTunnel remembers a key for 24 hours.
 */
final class RetryPolicy
{
    const MAX_ATTEMPTS = 6;

    /** Seconds after attempt 1, 2, 3, 4 and 5. */
    const BACKOFF = array(300, 900, 3600, 10800, 21600);

    /** The last attempt starts at most this long after the first (SMSTunnel keeps a key for 24 h). */
    const WINDOW = 82800;

    /** The cron runs every few minutes: no retry is planned sooner than this. */
    const MIN_DELAY = 60;

    const SENT = 'sent';
    const RETRY = 'retry';
    const FINAL = 'final';

    /**
     * What an error means for the text.
     *
     * @return array{0: string, 1: int|null, 2: bool} RETRY or FINAL, the Retry-After in seconds (or null),
     *                                                 and whether the next texts of this request should
     *                                                 wait too (the API cannot be reached, is failing,
     *                                                 limits the rate, or refuses the key)
     */
    public static function classify(\Throwable $error): array
    {
        if ($error instanceof TransportException) {
            return array(self::RETRY, null, true);
        }
        if ($error instanceof ApiException) {
            $status = $error->getStatus();
            if ($status === 429) {
                return array(self::RETRY, $error->getRetryAfter(), true);
            }
            if ($status === 409 && $error->getErrorCode() === 'IDEMPOTENCY_KEY_IN_PROGRESS') {
                return array(self::RETRY, $error->getRetryAfter(), false);
            }
            if ($status >= 500) {
                return $error->isReplayed() ? array(self::FINAL, null, false) : array(self::RETRY, $error->getRetryAfter(), true);
            }

            return array(self::FINAL, null, in_array($status, array(401, 403), true));
        }

        return array(self::FINAL, null, false);
    }

    /**
     * When to try next, or null to give up.
     *
     * @param int      $attempts     attempts made so far (1 after the first)
     * @param int|null $retryAfter   seconds SMSTunnel asked to wait, if it said
     * @param int      $firstAttempt Unix time of the first attempt
     * @param int      $now          Unix time now
     *
     * @return int|null
     */
    public static function nextAttempt(int $attempts, $retryAfter, int $firstAttempt, int $now)
    {
        if ($attempts >= self::MAX_ATTEMPTS) {
            return null;
        }
        if ($retryAfter !== null) {
            $delay = (int) $retryAfter;
        } else {
            $delay = self::BACKOFF[max(0, min($attempts, count(self::BACKOFF)) - 1)];
        }
        $at = $now + max(self::MIN_DELAY, $delay);
        if ($at > $firstAttempt + self::WINDOW) {
            return null;
        }

        return $at;
    }
}
