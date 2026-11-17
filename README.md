# SMSTunnel for WHMCS

A free WHMCS module that texts your clients about their invoices, services, tickets and domains, and alerts your staff about paid orders and tickets, through **[SMSTunnel](https://smstunnel.io)**: from **your own Android phone**, with **your own number**.

WHMCS hands each text to your SMSTunnel account and your phone sends it from its own SIM, so clients see a number they know and can reply to it. The phone is linked to your account when you sign in to the SMSTunnel app, and you can cut WHMCS off at any time by deleting its API key in your dashboard.

Your phone needs the SMSTunnel app from Google Play:

<a href="https://play.google.com/store/apps/details?id=io.smstunnel.app"><img src="https://smstunnel.io/google-play-badge.png" alt="Get it on Google Play" height="56"></a>

SMSTunnel for WHMCS is not affiliated with, endorsed by, or sponsored by WHMCS Limited. WHMCS is a trademark of WHMCS Limited.

## What it does

- **Texts to your clients**, each with its own switch and wording: invoice created, payment reminder, overdue reminder, invoice paid, service activated, suspended and unsuspended, a staff reply on a ticket, domain registered and renewed. Every text is about the client's own account and goes to the client's own number, one text per event.
- **Alerts to your staff**: a new paid order, a new ticket, a client reply on a ticket, to up to 10 of your numbers.
- **Your wording**, with merge fields such as `{client_first_name}`, `{invoice_num}`, `{invoice_total}` and `{service_domain}`, and a counter under each text that shows how many SMS parts it takes (GSM 160/153 characters, Unicode 70/67, emoji count as 2).
- **Clients choose**: a *No text messages* tickbox in their profile and at sign-up, or, if you need consent first, *Text only clients who opted in* with a *Send me text messages* tickbox.
- **A test text**, the list of your phones and whether they are online, and a phone picker.
- **A log** of every text: time, event, client, number, state, message ID and error. Kept 90 days by default.
- **WHMCS never waits long**: 5 seconds to connect, 10 in all. If SMSTunnel cannot be reached, the action goes on and the WHMCS cron tries the text again, safely (see [Sending and retries](#sending-and-retries)).
- **Sign-in codes by text message** (optional): a Two-Factor Authentication module for staff and clients.
- No texts to numbers that should not get one: missing or invalid numbers, premium-rate numbers, short codes and satellite networks are skipped and logged.

The module has no way to text many or all clients at once: it only answers WHMCS events, one client at a time.

## Requirements

- Self-hosted WHMCS 8.10 to 8.13 (PHP 7.2 to 8.3) or WHMCS 9.0 and 9.1 (PHP 8.2 to 8.4). WHMCS Cloud does not accept modules from outside its catalogue.
- PHP's cURL extension (WHMCS needs it anyway) and the WHMCS cron, which WHMCS needs anyway (it retries texts that could not go out).
- An Android phone with the SMSTunnel app, and an SMSTunnel account.

## Install

1. Download `smstunnel-whmcs-1.0.0.zip` from the [latest release](https://github.com/narcisbodea/smstunnel-whmcs/releases/latest) and unzip it.
2. Upload the `modules` folder to your WHMCS root folder (it adds `modules/addons/smstunnel` and `modules/security/smstunnel2fa`; nothing of WHMCS is changed).
3. In your WHMCS admin, open **System Settings → Addon Modules** (the wrench icon → **System Settings** in WHMCS 8), find **SMSTunnel for WHMCS** and click **Activate**.
4. Click **Configure**, tick the admin roles that may use it under **Access Control**, and **Save Changes**.
5. Open **Addons → SMSTunnel for WHMCS** and follow the steps below.

## Get your key

1. Install the [SMSTunnel app from Google Play](https://play.google.com/store/apps/details?id=io.smstunnel.app) on the Android phone that should send your texts, and sign in. Sign in at [smstunnel.io](https://smstunnel.io) on your computer with the same account: the phone appears under **Phones** (you can also link it with the QR code from **Phones → Add phone**).
2. In your [SMSTunnel dashboard](https://dashboard.smstunnel.io/apps), open **Apps → Add an app** and copy its key. The key can send texts and look up whether they went out; this module needs nothing more.
3. Paste it into **API key** on the module's **Setup** tab.

## Setup

1. **Setup → 1. Your SMSTunnel key**: paste the key and save. It is stored encrypted with WHMCS's own encryption and never shown back in full (only its last 4 characters).
2. **Setup → 2. Phone**: the page lists your phones, online or offline. Leave *Any of my phones (the one that is online)*, or pick the phone that should send your texts.
3. **Setup → Send a test text** to your own number.
4. **Client texts**: tick the texts you want and adjust their wording. *Invoice created*, *Overdue reminder*, *Invoice paid*, the three service texts, *Staff reply on a ticket* and *Domain registered* start on; *Payment reminder* (before the due date) and *Domain renewed* start off.
5. **Staff alerts**: add your numbers (one per line, `+14155550123`) and tick the alerts you want.
6. **Setup → 3. Clients' choice**: leave it, or tick *Text only clients who opted in*.

When a phone is offline, texts wait in your SMSTunnel account and go out as soon as it is back online. That is normal: the log shows *Waiting for your phone*.

## Client texts

| Text | WHMCS hook | When | Starts |
| --- | --- | --- | --- |
| Invoice created | `InvoiceCreated` | WHMCS issues an invoice (it leaves Draft), after its Invoice Created email. Nothing for an invoice with nothing left to pay. | on |
| Payment reminder | `InvoicePaymentReminder` | WHMCS's reminder for an unpaid invoice that is not overdue yet (Automation Settings: Invoice Unpaid Reminder). | off |
| Overdue reminder | `InvoicePaymentReminder` | Each of WHMCS's overdue notices (First, Second, Third Overdue Reminder). | on |
| Invoice paid | `InvoicePaid` | An invoice is paid, online, with credit or by staff. Nothing for a free invoice. | on |
| Service activated | `AfterModuleCreate` | WHMCS creates the service through its product's module (automatically after payment, or Module Create in the admin area). | on |
| Service suspended | `AfterModuleSuspend` | The product's module suspends the service (overdue automation or by staff). | on |
| Service unsuspended | `AfterModuleUnsuspend` | The product's module unsuspends it. | on |
| Staff reply on a ticket | `TicketAdminReply` | Staff reply to the client's ticket. Tickets from visitors without an account send nothing. | on |
| Domain registered | `AfterRegistrarRegistration` | The registrar module registers the domain. | on |
| Domain renewed | `AfterRegistrarRenewal` | The registrar module renews it. | off |

Products without a provisioning module never run Module Create, so they send no *Service activated* text.

### Merge fields

Every client text has `{client_first_name}`, `{client_last_name}`, `{client_name}`, `{client_company_name}`, `{company_name}` (your company, from General Settings) and `{whmcs_url}`. The fields of each event are shown under its text, as buttons that insert them. The `{$field}` form of WHMCS email templates works too.

| Fields | Events |
| --- | --- |
| `{invoice_id}` `{invoice_num}` `{invoice_total}` `{invoice_balance}` `{invoice_due_date}` `{invoice_url}` | the four invoice texts; `{invoice_days_overdue}` in the overdue reminder |
| `{service_id}` `{service_name}` `{service_domain}` | the three service texts; `{service_suspend_reason}` when suspended |
| `{ticket_number}` `{ticket_subject}` `{ticket_department}` `{ticket_status}` | staff reply on a ticket |
| `{domain_name}` `{domain_years}` | domain registered and renewed |

Amounts use the client's currency and dates the client area's date format, as WHMCS writes them. A field with no value disappears without leaving double spaces, a long subject or product name is shortened to 60 characters, and the words of a ticket never go into a text. Leave a text empty (or use **Use the suggested text**) to keep the suggested wording, which later versions may improve.

The suggested texts stay in the basic SMS alphabet and fit in one SMS with typical values. One character outside it (such as `ă`, `ç` or an emoji) makes every SMS part 70 characters long instead of 160: the counter under each text says when that happens and which character caused it.

## Staff alerts

| Alert | WHMCS hook | Fields |
| --- | --- | --- |
| New paid order | `OrderPaid` (the first invoice of a new order is paid) | `{order_id}` `{order_num}` `{order_total}` `{order_payment_method}` `{client_id}` `{client_name}` `{company_name}` |
| New ticket | `TicketOpen` | `{ticket_number}` `{ticket_subject}` `{ticket_department}` `{ticket_priority}` `{client_id}` `{client_name}` `{company_name}` |
| Client reply on a ticket | `TicketUserReply` | the same as New ticket |

Each staff number gets its own text, and at most 10 alerts of one kind an hour, so a flood of tickets (spam sent to your support address, say) cannot flood your phones or use up your plan: the extra alerts are logged as skipped.

## Clients' choice

On activation the module adds a client custom field, a tickbox **No text messages**, shown in the client's profile and at sign-up (**System Settings → Custom Client Fields**). Clients who tick it get no texts.

If you need consent first, tick **Text only clients who opted in** under **Setup**. Clients then see **Send me text messages** instead, and only those who ticked it get texts. The two choices are kept in two separate fields, so switching back and forth never turns anyone's *No* into a *Yes*: the field of the other mode is hidden from clients (Admin Only) and keeps its answers. You can see and change both on each client's profile.

These are service texts about the client's own account. If the field is deleted (WHMCS deletes what clients ticked with it), no client texts go out until it is back, because nobody could say no: the module's page says so and offers to create it again.

## Phone numbers

The client's number is the phone number of their profile, in international format (E.164):

1. a number typed with `+` or `00` is taken as typed;
2. otherwise WHMCS's own international form of it (`+44.1234567890`), built from the client's country;
3. otherwise the typed number completed with the calling code of the client's country.

SMSTunnel also gets the client's calling code as `defaultCountryCode`. Countries whose numbers keep their leading 0 after the calling code (Italy, San Marino, Vatican, Côte d'Ivoire, Benin, Gabon, Congo) keep it. A client without a usable number, or with a premium-rate number (`+1 900…`, `+44 90…`), a short code or a satellite network, is skipped: the WHMCS action goes on and the log says why. Staff and test numbers without `+` are completed with your default country (General Settings).

## Sending and retries

Each text is one call to `POST /api/v1/sms/send` with one recipient, 5 seconds to connect and 10 in all. Each text has its own `Idempotency-Key`, saved before the first attempt.

- **Network error, timeout, 5xx or 429**: the text waits, and the WHMCS cron (`AfterCronJob`) tries it again with **the same key and the same request**, 5, 15, 60, 180 and 360 minutes later (or as `Retry-After` says), at most 6 tries, never later than 23 hours after the first (SMSTunnel remembers a key for 24 hours). If SMSTunnel received an attempt whose answer was lost, the retry gets that answer back and no second text is sent.
- **Any other 4xx** (a refused key, a missing permission, a number SMSTunnel cannot text): no retry. A refused key also stops the other texts of the same page, so they fail at once instead of each waiting; the Activity Log says why.
- **A 5xx that SMSTunnel stored for the key** (its answer has `Idempotent-Replayed: true`): no retry, because the same key keeps answering it and a new key could send the text twice. Check **Messages** in your dashboard.
- When SMSTunnel cannot be reached, the other texts of the same page or cron run are not tried: they wait for the next cron run, so a page waits for at most one timeout. A cron run retries at most 20 texts (SMSTunnel takes 30 send requests a minute per key).
- SMSTunnel keeps Idempotency-Keys per API key. If you save another key (or server) while a text waits and an earlier attempt may have reached SMSTunnel, that text is not retried under the new key, so it cannot go out twice; the log says to check **Messages** in your dashboard. A text whose attempts never reached SMSTunnel (a refused connection) is sent with the new key.
- **Waiting for your phone** (`queued`): SMSTunnel has the text and the phone sends it when it is back online. Not an error, never retried.
- The same WHMCS event fired twice sends one text.

The log is under **Addons → SMSTunnel for WHMCS → Log**. The daily cron (`DailyCronJob`) removes rows older than the days set under **Setup** (90 by default), and every cron run ends texts still waiting 23 hours after they were written, dropping their words.

## Sign-in codes (Two-Factor Authentication)

`modules/security/smstunnel2fa` adds **SMSTunnel: codes by text message** to WHMCS's Two-Factor Authentication. The codes go out through the addon, so set the addon up and send a test text first.

1. Open **System Settings → Two-Factor Authentication**, click **SMSTunnel: codes by text message**, choose whether staff, clients or both may use it, and activate it.
2. Each person turns it on in their own security settings (staff: **My Account**; clients: **Security Settings** in the client area): they type their mobile number, get a code by text message, and type it back. The number is saved only then.
3. From then on, each sign-in texts them a code.

Codes are 6 random digits, sent with `POST /api/v1/sms/send-2fa`, valid for 10 minutes, for one use, in the browser session that asked for it. 5 wrong tries end a code; a person gets at most 5 codes in 30 minutes, at least 60 seconds apart (**Text me a new code** under the box, a link that works once). Because anyone who can sign up could type any number into the setup form, a number gets at most 5 codes an hour whoever asks, and all setups together at most 20 codes an hour. Only a keyed hash of each code is stored, typed codes are compared in constant time, and no code is written to any log. **Keep the backup code WHMCS shows when two-factor authentication is turned on**: it signs you in when your phone cannot get a text.

The folder is `smstunnel2fa`, not `smstunnel`, because WHMCS names a module's functions after its folder and the addon already uses the `smstunnel_` names.

## When something goes wrong

| Message | What to do |
| --- | --- |
| SMSTunnel refused the API key | The key was deleted, mistyped, or its account is suspended. Create a new one under **Apps → Add an app** and paste it under **Setup**. |
| This API key may not send texts | The key lacks the permission to send texts. Create a new one under **Apps → Add an app**. |
| Too many texts at once, or the daily limit of your plan is reached | SMSTunnel takes 30 send requests a minute per key, and your [plan](https://smstunnel.io/pricing/) has a daily allowance. The cron sends the waiting texts later. |
| SMSTunnel could not be reached from your server | A network, DNS or firewall problem between your server and smstunnel.io (port 443). The cron retries for up to 23 hours. |
| Waiting for your phone | Your phone is offline. The text goes out when it is back online; nothing to do. |
| Will retry at 14:05 | The text waits for the cron. Check that the WHMCS cron runs (**Utilities → Automation Status**). |
| Gave up after 6 tries | SMSTunnel could not be reached for hours. The words were not kept; the event will not send again on its own. |
| Skipped | The client opted out (or did not opt in), has no usable number, or has a premium-rate number. The log says which. |

Failures are also written to WHMCS's **Activity Log**, without numbers in full, without the words of a text and without the key.

## Privacy

The module sends only service texts about a client's own account, to the client's own number, and alerts to your own staff numbers.

What goes to SMSTunnel, and why:

- **To deliver each text**: the recipient's number and the text, which can contain the client's name, invoice, service, ticket subject or domain, depending on your wording. SMSTunnel passes it to your phone, which sends it.
- **To identify your account**: your API key, the phone you chose, and the calling code used to complete the number.
- Nothing else: no email address, no address, no ticket messages, no passwords.

What stays in WHMCS:

- The settings (the API key encrypted with WHMCS's `encrypt()`) in `mod_smstunnel_settings`.
- The log in `mod_smstunnel_log`: time, event, client, number, state, message ID and error. The words of a text are kept only while it waits for a retry; sign-in code texts are never kept. Rows older than 90 days (or what you set) are removed daily.
- Sign-in codes in `mod_smstunnel_codes` as keyed hashes for a day; the numbers for sign-in codes encrypted.
- The client fields *No text messages* and *Send me text messages* and what clients ticked.

Deactivating the addon keeps all of it, unless you tick **Delete all data on deactivate** under **Setup** first: then deactivation deletes the tables, the key, the log and the client fields with their answers.

You are the controller of your clients' data; SMSTunnel processes the texts for you. Clients can reply to the texts: the replies reach your phone.

## Build it with an AI assistant

Want a text the module does not send, such as a reminder before a domain expires? Copy this prompt into Claude, ChatGPT, Cursor or Copilot and replace the part in brackets. Never put a real API key in a prompt.

```text
I run WHMCS [8.13 / 9.0 / 9.1] (self-hosted, PHP [8.3]) and want it to send a text message with SMSTunnel.
SMSTunnel sends texts from my own Android phone, from my own number: my code hands each text to my
SMSTunnel account and the SMSTunnel app on my phone sends it from its own SIM.

The module "SMSTunnel for WHMCS" is installed (modules/addons/smstunnel, activated under System Settings →
Addon Modules) and set up with my API key. It already texts clients about invoices, services, tickets and
domains. Its PHP classes can send a text for my own hook, logged and retried like its own texts:

  require_once ROOTDIR . '/modules/addons/smstunnel/lib/autoload.php';
  $text = new \SmsTunnel\Whmcs\Send\OutgoingText('my_event', 'client');
  $text->clientId = $clientId;          // for the log's client link
  $text->to = '+14155550123';           // one number, E.164
  $text->message = 'The words';         // up to 1600 characters
  $text->countryCode = '1';             // optional: the client's calling code
  \SmsTunnel\Whmcs\Module::outbox()->deliver($text);

SMSTunnel API facts (if you call it directly instead):
- POST https://smstunnel.io/api/v1/sms/send with the headers X-API-Key: <key> and Content-Type: application/json,
  and optionally Idempotency-Key: <a unique value per text> so a retry never sends the text twice.
- Body: {"to": "+14155550123", "message": "...", "deviceId": "<optional phone ID>", "defaultCountryCode": "<optional, e.g. +1>"}.
  No other fields: the API refuses unknown ones.
- Success: {"success": true, "messageId": "...", "data": {...}}; "queued": true means my phone is offline and
  SMSTunnel keeps the text until the phone is back online.
- One recipient per call: to text several people, send one text to each.
- Up to 1600 characters per text; 30 send requests a minute per key.
- Numbers in international format (+ and country code). defaultCountryCode completes numbers written without + or 00.
- Sign-in and verification codes go to POST https://smstunnel.io/api/v1/sms/send-2fa instead: the text must contain
  a code of 4 to 10 digits standing on its own (e.g. 482913 or 123-456), at most 320 characters.
- Errors are JSON such as {"message": "Invalid or expired API key", "statusCode": 401}, sometimes with a "code":
  401 = wrong or deleted key; 403 = the key lacks a permission (code PERMISSION_REQUIRED) or the plan lacks the
  feature; 429 = too many requests, wait (Retry-After) and try again; 5xx = try again later with the same Idempotency-Key.

What I want: [describe what should send a text, to whom, and when]

Rules for you:
- Write it as a WHMCS hook file in includes/hooks/ (or a small addon module), using add_hook and WHMCS's
  Internal API (localAPI) or Capsule to read data. Do not edit WHMCS core files or the SMSTunnel module.
- Keep the API key out of source code, templates and anything sent to the browser: use the module's saved key
  (through its classes above) or WHMCS's encrypted storage, never a literal in the code.
- Use short timeouts (5 seconds to connect, 10 in all) and catch every error: a text must never stop a WHMCS
  page, payment callback or cron run.
- Validate numbers and turn them into international format before sending; skip invalid, premium-rate and short numbers.
- Handle the errors above: retry 429 and 5xx later with the same Idempotency-Key, never retry 401 and 403.
  Log failures without full phone numbers, without the words of the text and without the key.
- Only send texts people expect: about their own account, one text per event. Never loop over all clients.
```

## Responsible use

Send only texts that people expect from you: invoices, service changes, replies to their tickets, codes they asked for. Unsolicited messages from a personal SIM break most carriers' terms of service. Please make sure your usage complies with your carrier's terms and with local regulations (in the EU, that includes GDPR and the ePrivacy rules on electronic communications; in the United States, the TCPA). If you need recorded consent, use **Text only clients who opted in**.

## Development

```bash
bash tools/check.sh          # php -l and the unit tests on PHP 7.2, 7.3, 7.4, 8.1, 8.2, 8.3, 8.4 and 8.5 (Docker),
                             # plus the editor's counter test (node)
node tests/e2e/run.mjs       # end to end against a local SMSTunnel API and a simulated phone (see below)
bash tools/build.sh          # build/smstunnel-whmcs-<version>.zip from the committed files
```

WHMCS needs a licence to run, so the tests load the module into a stand-in for WHMCS (`tests/harness`): WHMCS's `Capsule` is Laravel's (`illuminate/database`, installed per PHP version: Laravel 7 on PHP 7.2 as WHMCS 8 ships, up to Laravel 12 on PHP 8.3+) on SQLite, `add_hook()` records the callbacks of `hooks.php` and runs them like WHMCS does, and `localAPI()`, `encrypt()`/`decrypt()`, `logActivity()`, `generate_token()`, `getCurrency()`/`formatCurrency()` and `fromMySQLDate()` behave like WHMCS's. The unit tests cover numbers, texts and the part counter, settings and encryption, the clients' choice, every hook, the retry rules (with a transport that answers what each test needs), the admin page (CSRF, escaping, saving, the test text, the log) and sign-in codes. Their HTTP part runs against `tests/fake-api/server.mjs`, a fake SMSTunnel API that keeps Idempotency-Keys the way the server does.

The end-to-end test (`tests/e2e/run.mjs`) needs the SMSTunnel server build and its dev tools (paths in `SMSTUNNEL_SERVER_DIR`, `SMSTUNNEL_DEV_DIR`, `SMSTUNNEL_NODE_MODULES`): it seeds a test database of its own, starts the API on port 3171 and a simulated phone, fires the module's hooks in Docker, checks every text in the API's database, verifies a sign-in code the phone received, and removes everything it started. Docker projects are named `smst-int-whmcs` and taken down with `-v`.

The **SMSTunnel server** field under **Setup → Advanced** exists for tests; a WHMCS keeps `https://smstunnel.io`.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT, © NARBOWEB SRL. See [LICENSE](LICENSE).
