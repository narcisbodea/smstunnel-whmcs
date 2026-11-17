# WHMCS Marketplace listing: SMSTunnel for WHMCS

Everything the listing form at https://marketplace.whmcs.com asks for, ready to paste, and what the owner has to do. Checked against the Marketplace's help pages on 18 Nov 2026 (https://marketplace.whmcs.com/help).

## Marketplace rules that shape this listing

- Listing is free; every listing is reviewed by a moderator, usually within two weeks. If a submission fails review, the Marketplace emails the address in the group's settings with the changes needed.
- **No links in a module listing's description**, and no "see our site for more": everything a user needs must be in the description. Markdown is allowed. No images inside the description.
- **English** first (another language only with an English translation under it).
- **Download URL** of a free listing: a direct link to the download (the release zip), not an information page.
- **Icon**: GIF, PNG or JPEG, best at 200×200, not mainly another company's logo (so no WHMCS logo in it). Ours: `listing/icon-200.png` (SMSTunnel's own).
- **Screenshots**: up to 3 on a new listing (more only with a paid promotion), high quality; client-area shots in the Six or Twenty-One theme.
- Support comes from the vendor: the **Website URL** and **Support URL** of the group show on the listing.
- The Marketplace lists modules and themes only, not stand-alone services: this is a module (the texts go through the owner's own SMSTunnel account and phone).

## The form

**Title**

SMSTunnel for WHMCS

**Short description** (tagline)

Text your clients and staff about invoices, services, tickets and domains from your own Android phone and number.

**Category**

Email & Notifications (if a second one is offered: Performance & Security, for the sign-in codes).

**Compatible WHMCS versions**

8.10, 8.11, 8.12, 8.13, 9.0, 9.1 (self-hosted).

**Price**

Free (MIT license).

**Download URL**

`https://github.com/narcisbodea/smstunnel-whmcs/releases/download/v1.0.0/smstunnel-whmcs-1.0.0.zip` (exists once the repository and its v1.0.0 release are published; see the steps below).

**Description** (Markdown, no links)

```markdown
SMSTunnel for WHMCS texts your clients about their own account, and alerts your staff, from **your own Android phone and your own number**. Clients see a number they know and can reply to it.

Your phone runs the free SMSTunnel app from Google Play and is linked to your SMSTunnel account. WHMCS hands each text to your account, and your phone sends it from its own SIM. You can cut WHMCS off at any time by deleting its key in your SMSTunnel dashboard.

### Texts to your clients

Each text has its own switch and wording:

- Invoice created
- Payment reminder (before the due date) and overdue reminders
- Invoice paid
- Service activated, suspended and unsuspended
- A staff reply on a ticket
- Domain registered and domain renewed

Every text goes to the client's own phone number from their profile, one text per event, only about their own account. The module never texts many clients at once.

### Alerts to your staff

A new paid order, a new ticket, and a client reply on a ticket, to up to 10 staff numbers, one text each.

### Your wording

Write each text with merge fields such as client_first_name, invoice_num, invoice_total, invoice_due_date, service_name, service_domain, ticket_number and domain_name (the {$field} form of WHMCS email templates works too). A counter under each text shows how many SMS parts it takes: 160 characters in one SMS (153 per part when longer), or 70 (67) when a character such as an accented letter or an emoji makes it Unicode. The suggested texts are short, polite and fit in one SMS.

### Your clients choose

The module adds a "No text messages" tickbox to the client profile and the sign-up form. If you need consent first, switch to "Text only clients who opted in": clients then see "Send me text messages" instead. The two answers are kept apart, so switching never turns anyone's No into a Yes.

### Reliable, and WHMCS never waits

- Short timeouts (5 seconds to connect, 10 in all): a page, a payment callback or the cron never waits long.
- If SMSTunnel cannot be reached, the WHMCS cron tries the text again, at most 6 times within 23 hours, always with the same Idempotency-Key, so a text is never sent twice. A refused key or number is not retried.
- When your phone is offline, texts wait in your SMSTunnel account and go out as soon as it is back online.
- A log of every text: time, event, client, number, state, message ID and error, kept 90 days (configurable).
- No texts to numbers that should not get one: missing or invalid numbers, premium-rate numbers, short codes and satellite networks are skipped and logged.

### Sign-in codes by text message (optional)

A Two-Factor Authentication module for staff and clients: each sign-in texts a 6-digit code to the number the person confirmed. Codes last 10 minutes, work once, allow 5 tries, and are stored only as keyed hashes, never logged.

### Security and privacy

- Your API key is stored with WHMCS's own encryption and never shown back in full.
- The admin page is limited to the admin roles you choose, and every form is protected against cross-site requests.
- Only the number and the text go to SMSTunnel. The words of a text are kept only while it waits for a retry.
- Deactivating keeps your settings and log, unless you tick "Delete all data on deactivate".

### Requirements

- Self-hosted WHMCS 8.10 to 9.1, PHP 7.2 to 8.4, with cURL and the WHMCS cron.
- An Android phone with the SMSTunnel app, and an SMSTunnel account (a free plan is available; paid plans raise the daily limits).

### Setup in three steps

1. Upload the modules folder to your WHMCS root and activate SMSTunnel for WHMCS under System Settings, Addon Modules; give your admin roles access.
2. Install the SMSTunnel app on your Android phone and sign in. In the SMSTunnel dashboard, open Apps, Add an app, and copy the key.
3. Open Addons, SMSTunnel for WHMCS, paste the key, and send a test text.

### FAQ

**Does it send from a shared number?** No. Every text leaves from your own phone and SIM, from your own number.

**What if my phone is off?** Texts wait in your SMSTunnel account and go out when the phone is back online.

**Can I text all my clients at once?** No. The module only answers WHMCS events, one client at a time, about their own account.

**Is it affiliated with WHMCS?** No. SMSTunnel for WHMCS is not affiliated with, endorsed by, or sponsored by WHMCS Limited.
```

**Screenshots** (three; take them on a real WHMCS, see below)

1. *Setup*: the key saved (masked), the phone list with one phone online, and a test text that went out.
2. *Client texts*: two or three texts with their merge-field buttons and the part counter.
3. *Log*: a few sent texts, one "Waiting for your phone" and one "Skipped: opted out".

A fourth, if a promotion is bought: the client profile in the Twenty-One theme with the "No text messages" tickbox.

**Icon**: `listing/icon-200.png` (200×200 PNG).

**Group** (the vendor profile the listing belongs to)

- Name: SMSTunnel
- Website URL: https://smstunnel.io
- Support URL: https://smstunnel.io/contact/

## What the owner has to do

1. **Publish the code first** (the download URL must work before the listing is submitted): create the public GitHub repository `narcisbodea/smstunnel-whmcs`, push this repository, run `bash tools/build.sh`, and create the release `v1.0.0` with `build/smstunnel-whmcs-1.0.0.zip` attached:
   ```bash
   git remote add origin https://github.com/narcisbodea/smstunnel-whmcs.git
   git push -u origin main
   bash tools/build.sh
   gh release create v1.0.0 build/smstunnel-whmcs-1.0.0.zip --title "SMSTunnel for WHMCS 1.0.0" --notes-file CHANGELOG.md
   ```
2. **Test it once on a real WHMCS** (the module was built and tested against a WHMCS stand-in; see "Before submitting" below) and take the three screenshots there.
3. **Marketplace account**: register at https://marketplace.whmcs.com (free), then create a **Group** for SMSTunnel (My Account → your profile → Group Membership → create group) with the Website and Support URLs above. Groups own listings, so other people can be added later.
4. **Submit**: My Account → **Submit a Listing**; paste the title, short description, category, versions, price (free), download URL and description above; upload the icon and the three screenshots; submit.
5. **Review**: up to about two weeks. The Marketplace emails the group's address if changes are needed. After approval the listing also shows inside WHMCS admin areas (Apps & Integrations / Marketplace).
6. **Updates**: raise the version in `modules/addons/smstunnel/lib/Version.php` (WHMCS runs the module's upgrade function when it changes), add a CHANGELOG entry, `bash tools/build.sh`, attach the zip to a new GitHub release, then edit the listing: new version number, changelog, and the new download URL.

## Before submitting: test on a real WHMCS

A WHMCS licence is needed to run WHMCS. Options found on 18 Nov 2026:

- **Ask WHMCS for a free development licence.** WHMCS's FAQ: "Our Developer Documentation is open and accessible to everyone... In some cases, you may even qualify for a free development license. Contact our sales team to see if you're eligible." (https://www.whmcs.com/faqs/)
- **Or buy the cheapest self-hosted licence and request its free development key.** Self-hosted plans start with *Plus* (250 active clients, monthly). The FAQ: "all users on our Plus license plans or higher can request a Development License Key to use for a secondary installation in a private testing environment"; the Marketplace help says the same ("A Development License is made available free to all direct license holders"). Development licences are for private test installs only.
- **WHMCS Cloud** (the managed plans) does not accept modules from outside its catalogue, so it cannot be used to test this module.

On the test WHMCS (WHMCS 9.x on PHP 8.3, and if possible 8.13 on PHP 7.4 or 8.1), check what the stand-in could only assume:

1. Activate, set Access Control, open the page; save a key; send a test text.
2. Turn on the opt-in mode and back; look at the two client fields on a client profile and in the client area.
3. Create an invoice, send a payment reminder (and an overdue one, to see the reminder `type` values), mark it paid, accept an order with a product that has a server module (create/suspend/unsuspend), reply to a ticket, open a ticket as a client, register/renew a domain with a registrar module.
4. Stop the network to smstunnel.io (or set the server to an unreachable address under Advanced), trigger a text, restore, run the cron: the text goes out once.
5. Two-factor: activate the module for staff and clients, turn it on for a staff user and a client user, sign in with the code, try a wrong code, use **Text me a new code**, and sign in with the backup code once.
6. Deactivate with and without **Delete all data on deactivate**.
