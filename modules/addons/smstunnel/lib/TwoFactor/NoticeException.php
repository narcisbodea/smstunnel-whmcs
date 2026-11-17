<?php
/**
 * SMSTunnel for WHMCS.
 *
 * @author    NARBOWEB SRL (https://smstunnel.io)
 * @copyright 2026 NARBOWEB SRL
 * @license   MIT
 */

namespace SmsTunnel\Whmcs\TwoFactor;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

/**
 * A message for the person setting up sign-in codes, where WHMCS's own \WHMCS\Exception is missing.
 */
class NoticeException extends \RuntimeException
{
}
