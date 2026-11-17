<?php
/**
 * SMSTunnel for WHMCS.
 *
 * @author    NARBOWEB SRL (https://smstunnel.io)
 * @copyright 2026 NARBOWEB SRL
 * @license   MIT
 */

namespace SmsTunnel\Whmcs\Api;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

/**
 * What SMSTunnel answered to a text it took.
 */
final class SendResult
{
    /** @var string */
    public $messageId;

    /**
     * @var bool true when the phone was offline: the text waits on the SMSTunnel account and the phone
     *           sends it as soon as it is back online (normal, not an error)
     */
    public $queued;

    /** @var bool true when the plan added its free line to the text */
    public $branded;

    /** @var bool true when the answer is the stored one of an earlier request with the same Idempotency-Key */
    public $replayed;

    public function __construct(string $messageId, bool $queued, bool $branded, bool $replayed)
    {
        $this->messageId = $messageId;
        $this->queued = $queued;
        $this->branded = $branded;
        $this->replayed = $replayed;
    }
}
