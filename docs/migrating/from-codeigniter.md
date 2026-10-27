# Coming from CodeIgniter

CodeIgniter 4 and VeyroPHP share a lot: plain-PHP views, explicit routes, a light core and Composer. VeyroPHP adds a dependency-injection container, PSR-7/PSR-15 HTTP, an ORM with relations, queues and a scheduler. Start with the [analyzer](porting-assistant.md) on your project. It reads the code without running it and says what maps directly and what must be rewritten.

## At a glance (CodeIgniter 4)

| CodeIgniter 4 | VeyroPHP | Notes |
| --- | --- | --- |
| `php spark` | `php veyro` | `spark make:controller` → `veyro make:controller` |
| `app/Config/Routes.php`, `$routes->get('users/(:num)', 'Users::show/$1')` | `routes/web.php`, `$router->get('/users/{id:\d+}', [Users::class, 'show'])` | named parameters instead of `$1` |
| `(:num)`, `(:segment)`, `(:any)` | `{id:\d+}`, `{slug}`, `{path:.+}` | |
| `$routes->resource()`, `presenter()` | separate routes | |
| `$routes->group('admin', ['filter' => 'auth'], ...)` | `$router->group('/admin', ..., middleware: [Authenticate::class])` | |
| Controllers extending `BaseController` | plain classes; dependencies in the constructor or method | |
| `$this->request->getPost()` | `$request->getParsedBody()` (PSR-7) | |
| `$this->validate([...])`, `$validation->setRules()` | `$validator->validate($data, [...])` | rules like `required|max_length[100]` become `required|max:100` |
| Filters (`app/Filters`, `Config/Filters.php`) | PSR-15 middleware | global, per group, per route |
| `CodeIgniter\Model` with `$table`, `$allowedFields`, `$useTimestamps` | `Model` with `TABLE`, `FILLABLE`, `TIMESTAMPS` constants and the `Orm` service | relations are built in |
| `$model->find($id)`, `->where()->findAll()` | `$orm->find(User::class, $id)`, `$orm->query(User::class)->where()->get()` | |
| `$useSoftDeletes` | not available | |
| Entities | models | casts with `CASTS` |
| Query Builder `$db->table('users')->where()->get()` | query builder `$db->table('users')->where()->get()` | [database](../database/README.md) |
| Migrations with `$this->forge->addField([...])` | schema builder: `$table->string('name')` | rewrite each migration |
| Seeders | `Seeder` classes, `veyro db:seed` | |
| Views `view('users/list', $data)`, `esc($x)` | `$view->render('users.list', $data)`, `$e($x)` | dots instead of slashes |
| View layouts `$this->extend()`, `$this->section()` | `$view->layout()`, `$view->section()` | |
| `session()`, flashdata | `Session`: `put()`, `flash()` | |
| Shield (auth) | built-in authentication and RBAC | [authentication](../security/authentication.md) |
| `.env` keys such as `database.default.hostname` | `.env` keys such as `DB_HOST`, read in `config/*.php` | |
| Helpers (`helper('url')`) | services injected where needed | no global helpers |
| Email library | `Mailer`, `Mailable` | [mail](../getting-started/mail.md) |
| Cache library | PSR-16 cache | [cache](../getting-started/cache.md) |
| Spark commands (`app/Commands`) | console commands with `#[AsCommand]` | [console](../getting-started/console.md) |
| Tasks package / cron | `routes/schedule.php` | [scheduling](../getting-started/scheduling.md) |

## Example: a controller

The CodeIgniter 4 method:

| CodeIgniter 4 |
| --- |
| `public function create() { if (! $this->validate(['name' => 'required|max_length[100]'])) { return redirect()->back(); } (new UserModel())->insert($this->request->getPost(['name'])); }` |

becomes:

```php
public function create(ServerRequestInterface $request, Validator $validator, Orm $orm): array
{
    $data = $validator->validate($request->getParsedBody() ?? [], ['name' => 'required|max:100']);

    return $orm->create(User::class, $data)->toArray();
}
```

A failed validation answers 422 with the errors, or redirects back with them for HTML forms.

## CodeIgniter 3

CodeIgniter 3 projects (an `application/` folder and `system/core/CodeIgniter.php`) predate Composer and namespaces. The analyzer recognizes them and lists controllers, models, views and libraries, but everything is a manual rewrite. Port them area by area, as for CodeIgniter 4.

## What the porting process looks like

1. **Analyze:** `php veyro port:analyze ../my-ci-app --from=codeigniter`. The project is not modified.
2. **Start fresh:** create a new application from the starter. Keep the old project as the reference and the backup.
3. **Port one area at a time,** with tests. Run migrations against a **copy** of the database, never against production.
4. **The port is done when the tests and manual checks pass,** not when the code parses.
