# ADR 0043: Mail API transports and the SMS channel

- Status: Accepted
- Date: 2026-10-27

## Context

Many hosts (shared hosting, some cloud providers) block outgoing SMTP. Indian applications also need SMS for OTPs and alerts. The framework had only SMTP and a hand-written-channel example.

## Decisions

### Raw MIME where the API allows it

- **Mailgun** (`messages.mime`) and **Amazon SES v2** (raw content) receive the MIME text the mailer already builds. Headers, encoded words, attachments and the Message-ID are then identical to SMTP. All envelope recipients, Bcc included, are passed separately, because the MIME text never contains Bcc.
- **Brevo** has no raw-MIME API. Its transport maps the message to Brevo's JSON fields and sends the Message-ID as a header.
- No provider SDKs (dependency policy). Requests go through any PSR-18 client: the bound `ClientInterface`, else Guzzle when installed.
- SES uses the existing Signature Version 4 code. That code moved from `Filesystem\S3Signer` to the internal `Support\AwsSignatureV4`, so Mail does not depend on Filesystem. `S3Signer` wraps it and keeps its exception type.
- **Failures:**
  - Every failure becomes `TransportException`, with the new `httpStatus` and the provider's short error text (control characters removed, 200 characters at most).
  - `isRetryable()` is true for 429, 5xx and network errors. A 401 or 422 is not retried by the queue.
  - One internal helper (`Support\ApiCall`) does this for mail and SMS.

### SMS: a built-in channel with gateways

- `SmsChannel` reads `toSms(Model): SmsMessage` from the notification. The number comes from `SmsMessage::to()`, `routeNotificationForSms()` or the `phone` column, and is normalised to E.164.
  - A number without `+` gets the configured country code. Without one it is refused, because guessing a country sends OTPs to the wrong person.
  - `919876543210` (country code already there, no `+`) is recognised.
- `toSms()` is **not** a method of `Notification`. Existing applications followed the earlier guide and wrote their own `toSms()` with other return types. A base method would make those classes fatal errors. The channel checks the method and its result instead.
- **Gateways:**
  - MSG91 Flow API. Indian DLT rules allow only registered templates, so a template id is required, and the text itself is not sent.
  - Twilio Messaging (a number or a Messaging Service SID).
  - `log` for development and `array` for tests.
  - Your own `SmsGateway` class.
- A channel configured under `notifications.channels.sms` still replaces the built-in one.

## Consequences

- Applications can send mail without SMTP and SMS without writing an HTTP client.
- Verified against fake HTTP clients that check each documented request format. No request was sent to a real provider: that needs the owner's accounts and is an environment check before claiming support.
