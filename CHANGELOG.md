# Changelog

## 1.0.1 (2026-11-18)

- The log names each event once: a ticket, order, service or domain now links the event's own name (1.0.0 read "Staff reply on a ticket ticket" and "New paid order order"). An invoice still shows its number after the name.

## 1.0.0 (2026-11-18)

First release.

- Addon module `smstunnel` for self-hosted WHMCS 8.10 to 9.1 (PHP 7.2 to 8.4).
- Client texts, each with a switch and its own wording: invoice created, payment reminder, overdue reminder, invoice paid, service activated, suspended and unsuspended, staff reply on a ticket, domain registered and renewed.
- Staff alerts to up to 10 numbers: new paid order, new ticket, client reply on a ticket.
- Merge fields, the `{$field}` form of WHMCS email templates, and an SMS part counter in the editor (GSM 160/153, Unicode 70/67, emoji count as 2).
- Clients' choice with client custom fields: *No text messages*, or in opt-in mode *Send me text messages*, kept apart so switching modes never turns a No into a Yes.
- Numbers in E.164 from WHMCS's formatted number or the client's country, with `defaultCountryCode`; premium-rate numbers, short codes and satellite networks are skipped.
- One recipient per call, 5 s to connect and 10 s in all, an `Idempotency-Key` per text; the WHMCS cron retries network errors, 5xx, 429 (after `Retry-After`) and 409 in-progress with the same key, at most 6 tries within 23 hours, never another 4xx.
- A text that may have reached SMSTunnel is never retried under another API key or server (keys are kept per API key on SMSTunnel's side).
- At most 10 staff alerts of one kind an hour to each number, so a ticket flood cannot flood the phones.
- Without the client field (deleted by mistake), no client texts go out until it is created again.
- The test text, the list of phones with their state, and a phone picker.
- A log with the event, client, number, state, message ID and error, cleaned daily after 90 days (configurable).
- The API key stored with WHMCS's `encrypt()` and never shown back in full; CSRF tokens on every form; all output escaped.
- Deactivation keeps settings and log unless *Delete all data on deactivate* is ticked.
- Two-Factor Authentication module `smstunnel2fa`: sign-in codes by text message through `send-2fa`, 6 digits, 10 minutes, one use, 5 tries, bound to the browser session, stored as keyed hashes, never logged. At most 5 codes in 30 minutes per person, 5 an hour per number and 20 setups an hour in all; the "Text me a new code" link works once.
