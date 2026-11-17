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
 * SMSTunnel could not be reached, or did not answer: DNS, TLS, a refused connection or a timeout.
 */
class TransportException extends \RuntimeException
{
    /** @var bool */
    private $mayHaveArrived;

    /**
     * @param bool $mayHaveArrived true when the request left and its answer never came: SMSTunnel may
     *                             have the text (only a retry with the same Idempotency-Key is safe)
     */
    public function __construct(string $message, int $code = 0, bool $mayHaveArrived = false)
    {
        parent::__construct($message, $code);
        $this->mayHaveArrived = $mayHaveArrived;
    }

    public function mayHaveArrived(): bool
    {
        return $this->mayHaveArrived;
    }
}
