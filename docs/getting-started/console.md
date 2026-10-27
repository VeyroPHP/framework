# Console

`php veyro` runs console commands. Design: [ADR 0017](../architecture/adr/0017-console.md).

```bash
php veyro                      # list commands
php veyro help migrate:rollback  # or: php veyro migrate:rollback --help
php veyro --version
```

Exit codes: `0` success, `1` failure (including uncaught exceptions), `2` usage error (unknown command or option, missing argument). Scripts and CI can rely on them.

## Built-in commands

| Command | What it does |
| --- | --- |
| `migrate [--force]` | Runs pending migrations. In production (`app.env`) it needs `--force`. |
| `migrate:rollback [--step=N] [--force]` | Rolls back the last N batches. |
| `migrate:status` | Shows which migrations have run. |
| `route:list [--method=POST] [--path=/admin]` | Lists routes with names, handlers and middleware. |
| `view:lint [--path=resources/views] [--strict]` | Reports template output that is not escaped (exit code 1), and `$e()` inside URL attributes (warnings). See [views](views.md#checking-templates-viewlint). |
| `config:cache`, `config:clear` | Caches `.env` + `config/` in one file for production, or removes it. |
| `cache:prune [--store=file]` | Deletes expired cache entries and sessions (run from cron). |
| `make:model Post [--table=posts] [--migration]` | Creates `app/Models/Post.php` (and a migration). |
| `make:controller Admin/UserController` | Creates `app/Http/Controllers/Admin/UserController.php`. |
| `key:generate [--force] [--show]` | Writes a new `APP_KEY` to `.env`; `--force` rotates and keeps the old key in `APP_PREVIOUS_KEYS` ([encryption](encryption.md)). |
| `make:event UserRegistered` | Creates `app/Events/UserRegistered.php` ([events](events.md)). |
| `token:create`, `token:list`, `token:revoke`, `token:prune` | API tokens ([guide](../security/api-tokens.md)); need `AuthServiceProvider::COMMANDS`. |
| `lang:missing` | Lists translation keys missing per language; exit code 1 if any ([translation](translation.md)). |
| `package:discover` | Lists installed VeyroPHP packages and their providers; rebuilds the package cache (Composer runs it after every install). |
| `vendor:publish [--tag=] [--provider=] [--force] [--list]` | Copies files packages offer (config, migrations) into the application. |
| `port:analyze <path> [--from=] [--format=json] [--output=]` | Reads a Laravel or CodeIgniter project (never runs or changes it) and reports how it maps to VeyroPHP. See [porting assistant](../migrating/porting-assistant.md). |
| `port:convert <source> <target> [--only=] [--dry-run] [--force]` | Converts a Laravel project's migrations, models, routes, Blade views, controllers and public files into a separate VeyroPHP application, with TODO(port) notes; never changes the source. |
| `security:audit [--format=json] [--output=] [--no-dev] [--sources=] [--check=] [--max-age=]` | Checks composer.lock against published advisories (composer audit, OSV.dev); exit 0 passed, 1 findings, 3 incomplete. See [dependency audit](../packages/dependency-audit.md). |
| `storage:link` | Links `public/storage` to the root of the `public` disk, by default `storage/app/public` (junction on Windows) ([storage](storage.md)). |
| `schedule:list`, `schedule:run`, `schedule:work` | Scheduled tasks ([scheduling](scheduling.md)); `schedule:run` belongs in cron, every minute. |
| `make:factory ProductFactory [--model=Product]`, `make:seeder ProductSeeder` | Factories and seeders ([guide](../database/factories-and-seeders.md)). |
| `db:seed [--class=...] [--force]` | Runs `Database\Seeders\DatabaseSeeder` (or `--class`); production needs `--force`. |
| `make:notification OrderShipped` | Creates `app/Notifications/OrderShipped.php` ([notifications](notifications.md)). |
| `make:job SendInvoice` | Creates `app/Jobs/SendInvoice.php` ([queues](queues.md)). |
| `make:listener SendWelcomeMail --event=UserRegistered` | Creates `app/Listeners/SendWelcomeMail.php` with `handle(UserRegistered $event)`. |
| `make:middleware EnsureUserIsActive` | Creates a PSR-15 middleware in `app/Http/Middleware` (sub-folders allowed: `Admin/RequireTwoFactor`). Use it globally (bootstrap/app.php), on a route (`->middleware(...)`) or on a group. |
| `make:mail OrderShipped` | Creates `app/Mail/OrderShipped.php` and `resources/views/mail/order-shipped.php` ([mail](mail.md)). |
| `make:migration create_posts_table` | Creates a timestamped migration. `create_<table>_table` gets a create/drop body. |
| `serve [--host=127.0.0.1] [--port=8000]` | PHP's built-in server for development. |

Optional modules' commands are added by the application, for example `rbac:seed` (`VeyroPHP\Auth\Rbac\Console\RbacSeedCommand`).

`make:*` commands **never overwrite** a file. Names are checked: StudlyCase class names (no PHP reserved words), snake_case migration names.

`serve` is single-threaded and for development only. `--host=0.0.0.0` makes the app reachable from other computers and prints a warning.

## Writing a command

```php
namespace App\Console\Commands;

use VeyroPHP\Console\Argument;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Option;
use VeyroPHP\Console\Output;

#[AsCommand('report:send', 'Email the daily report')]
final class SendReportCommand extends Command
{
    public function __construct(private readonly ReportMailer $mailer) {} // autowired

    public function definition(): array
    {
        return [
            Argument::required('email', 'Recipient'),
            Option::value('days', 'How many days to include', '1'),
            Option::flag('dry-run', 'Show what would be sent'),
        ];
    }

    public function execute(Input $input, Output $output): int
    {
        $days = $input->positiveInt('days');      // usage error (exit 2) if not a positive integer
        if ($input->flag('dry-run')) { ... }

        $output->success('Report sent.');

        return self::SUCCESS;
    }
}
```

Register it in `bootstrap/app.php`:

```php
$app->register(new ConsoleServiceProvider([SendReportCommand::class]));
```

or list it in `config/console.php` under `commands`.

- Options are long only: `--flag`, `--name=value` or `--name value`. Everything after `--` is an argument.
- Commands are constructed only when they run. `php veyro list` does not connect to the database.
- Throw `UsageException` for wrong input the parser cannot detect (exit 2). Other exceptions are logged and print `Error: <message>` (exit 1); with `app.debug` the class, file and trace are added. Validation errors print each message, without a trace.
- Read secrets from standard input (`$input->readLine()`), not from arguments, which end up in shell history.
- `Output` uses colors only on a terminal and when `NO_COLOR` is not set. `warning()` and `error()` write to standard error.

## Testing HTTP behavior

`VeyroPHP\Testing\TestClient` sends requests to the kernel in-process, keeps cookies like a browser and reuses the CSRF token from the last form:

```php
$client = new TestClient($app->container()->make(HttpKernel::class));
$client->get('/login');
$response = $client->post('/login', ['email' => 'a@b.test', 'password' => 'secret'], withToken: true);
$client->follow($response); // GET the redirect target
```

The starter app's `tests/` folder shows a complete setup with an in-memory database.
