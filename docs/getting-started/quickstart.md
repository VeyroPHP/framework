# Quickstart: from zero to a logged-in page

This guide starts with an empty folder and ends with a running application, an admin account and a first page of your own. It takes about ten minutes.

> VeyroPHP is pre-alpha and not yet on Packagist. Until it is, install from local copies of the framework and the starter app (see "Before the first release" below).

## 1. Requirements

- PHP 8.2 or newer with the extensions `ctype`, `filter`, `mbstring`, `openssl`, `pdo` and `pdo_sqlite` (XAMPP has them all; on a plain Windows PHP, enable `mbstring` in `php.ini`). Composer checks the required ones during install.
- Composer 2

Check with `php -v` and `composer -V`.

## 2. Create the project

```bash
composer create-project veyrophp/app my-app
cd my-app
```

This copies the starter app, installs dependencies, creates `.env` from `.env.example`, creates the SQLite database (`database/database.sqlite`), runs the migrations and creates the roles `super-admin`, `admin` and `user`.

## 3. Create your admin account

```bash
php veyro user:create "Your Name" you@example.com --role=super-admin
```

A random password is printed once. To choose your own, pipe it in: `echo "my-long-password" | php veyro user:create ... --password-stdin`.

## 4. Run it

```bash
php veyro serve
```

Open http://127.0.0.1:8000 for the welcome page and http://127.0.0.1:8000/login to log in. After login, `/dashboard` shows your roles and permissions.

With XAMPP you can instead put the project in `htdocs` and open `http://localhost/my-app/`; the root `.htaccess` sends requests into `public/`.

## 5. Add a page

```bash
php veyro make:model Post --migration
php veyro make:controller PostController
```

Edit the migration in `database/migrations/` to add columns:

```php
$schema->create('posts', static function (Blueprint $table): void {
    $table->id();
    $table->ulid()->unique();
    $table->string('title');
    $table->text('body');
    $table->timestamps();
});
```

Allow those fields in `app/Models/Post.php` (`protected const FILLABLE = ['title', 'body'];`), then run `php veyro migrate`.

Fill in the controller:

```php
use App\Models\Post;
use VeyroPHP\Orm\Orm;

final class PostController
{
    public function index(Orm $orm): array
    {
        return array_map(static fn(Post $p): array => $p->toArray(), $orm->query(Post::class)->orderByDesc('id')->get());
    }
}
```

Register the route in `routes/web.php`:

```php
$router->get('/posts', [App\Http\Controllers\PostController::class, 'index'])->name('posts.index');
```

`php veyro route:list` now shows it, and http://127.0.0.1:8000/posts returns JSON.

## 6. Test it

```bash
composer test
```

The starter app's tests use an in-memory database (see `phpunit.xml`), so they never touch `database/database.sqlite`. Copy `tests/Feature/AuthTest.php` to write your own.

## Where next

- [Routing](routing.md), [HTTP](http.md), [views](views.md)
- [Database](../database/README.md), [migrations](../database/migrations.md), [ORM](../database/orm.md)
- [Authentication and RBAC](../security/authentication.md), [hardening](../security/hardening.md)
- [Console](console.md)

## Before the first release

`veyrophp/app` and `veyrophp/core` are not published yet. With both repositories cloned side by side in the folders `veyrophp/` and `veyrophp-app/` (the folder names the starter's path repositories expect; they predate the VeyroPHP name), create a project next to them:

```bash
cd veyrophp-app && composer archive --format=tar --dir=../export --file=app && cd ..
mkdir export/veyrophp-app && tar -xf export/app.tar -C export/veyrophp-app
composer create-project veyrophp/app my-app 0.1.x-dev \
  --repository='{"type":"path","url":"export/veyrophp-app","options":{"symlink":false,"versions":{"veyrophp/app":"0.1.x-dev"}}}'
```

The new project finds the framework through the starter app's `../veyrophp` path repository, so it must sit in the same parent folder. Its `vendor/veyrophp/core` is then a symlink (a junction on Windows) to your framework copy: delete that link first before deleting the project folder.
