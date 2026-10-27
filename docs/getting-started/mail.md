# Mail

`VeyroPHP\Mail` sends email through SMTP (any provider, or a local test server), writes it to the log during development, or keeps it in memory for tests. Design: [ADR 0022](../architecture/adr/0022-mail.md).

## Setup

Register `VeyroPHP\Mail\MailServiceProvider` (the starter app does) and configure `config/mail.php` through `.env`:

```ini
MAIL_MAILER=smtp
MAIL_FROM_ADDRESS=hello@yourshop.in
MAIL_FROM_NAME="Your Shop"
MAIL_HOST=smtp.yourprovider.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=...
MAIL_PASSWORD=...
```

| `MAIL_ENCRYPTION` | Port | Meaning |
| --- | --- | --- |
| `tls` | 587 | Starts plain, then STARTTLS. If the server does not offer STARTTLS, nothing is sent. |
| `ssl` | 465 | TLS from the first byte. |
| `none` | 25, 1025 | No encryption: only for local test servers such as Mailpit. A username and password are refused unless the host is `localhost`. |

The server's certificate is always checked. `MAIL_VERIFY_PEER=false` turns that off for a local test server with a self-signed certificate; never in production.

Check the settings by sending a test message:

```bash
php veyro mail:test you@example.com
php veyro mail:test you@example.com --mailer=smtp
```

The starter app uses `MAIL_MAILER=log` by default, so nothing leaves your computer while you develop; messages appear in `storage/logs/veyrophp.log`. Its tests force the `array` mailer.

## Sending a message

```php
use VeyroPHP\Mail\Attachment;
use VeyroPHP\Mail\Mailer;
use VeyroPHP\Mail\Message;

public function ship(Mailer $mailer, Order $order): void
{
    $mailer->send(
        (new Message())
            ->to($order->email, $order->name)
            ->subject('Your order has shipped')
            ->view('mail.order-shipped', ['order' => $order])
            ->attach(Attachment::fromPath($invoicePath, 'Invoice.pdf')),
    );
}
```

| Method | |
| --- | --- |
| `to()`, `cc()`, `bcc()`, `replyTo()` | Call several times for several addresses. Accept `'a@b.com'`, `('a@b.com', 'Name')`, `'Name <a@b.com>'` or an `Address`. Bcc recipients get the message but never appear in it. |
| `from()` | Optional; defaults to `mail.from`. |
| `subject()` | Any language; non-English text is encoded for you. |
| `text()`, `html()` | Bodies. With only HTML, a plain-text version is generated (spam filters and some readers prefer it). |
| `view($name, $data)`, `textView(...)` | Bodies rendered from views (`resources/views/mail/order-shipped.php`); escape with `$e()` as in any view. |
| `attach(Attachment::fromPath(...))`, `Attachment::fromData($bytes, $name, $type)` | Files; the type is detected from the content when not given. |
| `header('List-Unsubscribe', '<https://...>')` | Extra headers. Headers the message sets itself (From, To, Subject, Content-Type…) cannot be overridden. |

`send()` returns a `SentMessage` with `messageId`, `recipients` and the `raw` MIME text, and dispatches `VeyroPHP\Mail\Events\MessageSent` when events are registered.

## Mail classes

For mail sent from several places, make a class:

```bash
php veyro make:mail OrderShipped
```

This creates `app/Mail/OrderShipped.php` and the view `resources/views/mail/order-shipped.php`:

```php
final class OrderShipped extends Mailable
{
    public function __construct(private readonly string $email) {}

    public function build(Message $message): void
    {
        $message->to($this->email)
            ->subject('OrderShipped')
            ->view('mail.order-shipped', ['email' => $this->email]);
    }
}

$mailer->send(new OrderShipped($user->email));
```

Override `mailer()` in the class to send it with another configured mailer.

## Errors

- `MailException`: the message or configuration is wrong (invalid address, no recipient, no body, unknown mailer). Fix the code or config.
- `TransportException` (a `MailException`): the server could not be reached or refused. `smtpCode` holds the server's reply (`535` wrong password, `550` unknown mailbox, `0` connection problem); `isRetryable()` is true for connection problems and temporary `4xx` replies.

Messages never contain the password or the mail body.

`send()` delivers **during the request**: a slow SMTP server makes the page slow, and a failure reaches the user. Prefer `$mailer->queue(new OrderShipped(...))`, which hands the mail to a [queue worker](queues.md) with retries. When you do send directly and the mail is optional, catch `TransportException`:

```php
try {
    $mailer->send(new OrderShipped($order->email));
} catch (TransportException $e) {
    $logger->warning('Shipping mail not sent', ['order' => $order->getKey(), 'error' => $e->getMessage()]);
}
```

## Security

- **Header injection** is blocked: addresses, names, subjects, header values and attachment names with line breaks are rejected, so form input cannot add recipients or headers.
- **Credentials** go only over encrypted connections (or to `localhost`), and only after the certificate was checked.
- **Logs**: each send is logged with mailer, Message-ID, number of recipients and time, never addresses, subjects or content. The `log` mailer is the exception (it exists to show you the mail); do not use it in production, because mail often contains personal data and links that work like passwords.
- **Spoofing**: your provider decides which From addresses you may use. Set up SPF, DKIM and DMARC for your domain with the provider, or mail will land in spam.

## Testing

Use the `array` mailer and look at what was "sent":

```php
$transport = $container->make(Mailer::class)->transport('array');
// ... run the code under test ...
$sent = $transport->last();
$sent?->message->getSubject();
$transport->sentTo('asha@example.com');   // messages to this address
```

## HTTP API transports (Mailgun, Amazon SES, Brevo)

Some hosts block outgoing SMTP. These mailers send over HTTPS instead. They need `composer require guzzlehttp/guzzle`, or any PSR-18 client bound as `Psr\Http\Client\ClientInterface`.

```php
// config/mail.php, under 'mailers'
'mailgun' => ['transport' => 'mailgun', 'domain' => 'mg.example.com', 'secret' => $env->string('MAILGUN_SECRET', ''),
              'endpoint' => 'https://api.mailgun.net'],   // EU accounts: https://api.eu.mailgun.net
'ses' => ['transport' => 'ses', 'key' => $env->string('AWS_ACCESS_KEY_ID', ''), 'secret' => $env->string('AWS_SECRET_ACCESS_KEY', ''),
          'region' => 'ap-south-1', 'configuration_set' => ''],
'brevo' => ['transport' => 'brevo', 'key' => $env->string('BREVO_API_KEY', '')],
```

| Mailer | What is sent | Notes |
| --- | --- | --- |
| `mailgun` | the MIME message the mailer built (`messages.mime`) | identical to SMTP: headers, attachments, encoding |
| `ses` | the MIME message (SES v2, raw content), signed with AWS Signature Version 4 | the IAM user needs `ses:SendEmail` |
| `brevo` | JSON fields: sender, to/cc/bcc, the first reply-to, subject, bodies, attachments, extra headers | Brevo has no raw-MIME API |

- Bcc addresses go only into the envelope, never into the message.
- A refusal throws `TransportException` with `httpStatus` and the provider's short error text. `isRetryable()` is true for HTTP 429, 5xx and network errors, so queued mail is retried only then.
- Keep API keys in `.env`. The transports take them as `#[SensitiveParameter]`, so stack traces do not show them.
- These transports were checked against the providers' documented request formats, not yet against real accounts.

## Your own transport

To send through another provider's HTTP API instead of SMTP, implement `VeyroPHP\Mail\Transport\Transport` (`send(SentMessage $message): void`, throwing `TransportException` on failure) and configure it: `'api' => ['transport' => App\Mail\ApiTransport::class]`. It is created by the container, so it can ask for `Config` or a PSR-18 HTTP client.
