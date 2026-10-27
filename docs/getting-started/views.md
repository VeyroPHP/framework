# Views

Status: **implemented** (Phase 2).

Templates are plain PHP files. Register `ViewServiceProvider`, then inject `VeyroPHP\View\View`.

```php
$router->get('/', fn (View $view) => $view->render('welcome', ['name' => 'Ravi']));
```

```php
<!-- resources/views/welcome.php -->
<h1>Hello, <?= $e($name) ?></h1>
<?= $view->render('partials.footer') ?>
```

## Rules

- `pages.about` maps to `resources/views/pages/about.php`. Names may contain letters, digits, `_` and `-`, with `.` between directories. Anything else (for example `../secret`) is rejected.
- Each data key becomes a variable. Keys must be valid PHP variable names and must not be `e`, `view` or `this`.
- **Output is not escaped automatically.** Wrap every value in `$e()`. It escapes `& < > " '` and replaces invalid UTF-8.
- If a template throws, its partial output is discarded and a `ViewException` is thrown with the original as `previous`.
- The template directory is `resources/views`, or the `view.path` config value (relative to the base path).
- Templates are PHP code and must be trusted.

## Layouts, sections, components and stacks

Design: [ADR 0031](../architecture/adr/0031-layouts-and-components.md). These are methods on `$view`; templates stay plain PHP, with no compile step.

### Layouts and sections

```php
<?php // resources/views/pages/orders.php
$view->layout('layouts.app');
$view->section('title', 'Orders');      // a short text section (escaped)
?>
<?php $view->section('sidebar') ?>
    <nav>...</nav>
<?php $view->endSection() ?>

<h1>Orders</h1>
```

```php
<?php // resources/views/layouts/app.php ?>
<!doctype html>
<html lang="<?= $e($locale()) ?>">
<head><title><?= $view->yield('title', 'My shop') ?></title><?= $view->yield('head') ?></head>
<body>
    <?= $view->yield('sidebar') ?>
    <main><?= $content ?></main>
    <?= $view->stack('scripts') ?>
</body>
</html>
```

- **Content:** the page's output arrives in the layout as `$content`.
- **Sections:** `yield('name', 'default')` prints a section, or the escaped default.
- **Nested layouts:** a layout can itself call `$view->layout(...)`, for example an admin layout inside the main layout. Sections set by the page are kept.

### Components

```php
<?= $view->component('alert', ['type' => 'error', 'message' => $error]) ?>

<?php $view->startComponent('card', ['title' => 'Orders']) ?>
    <p>3 open orders</p>
    <?php $view->slot('footer') ?><a href="/orders">All orders</a><?php $view->endSlot() ?>
<?= $view->endComponent() ?>
```

`components/card.php` receives its data, the block's output as `$slot`, and named slots as variables (`$footer`):

```php
<section class="card"><h2><?= $e($title) ?></h2><?= $slot ?><footer><?= $footer ?? '' ?></footer></section>
```

### Stacks

A page or component can add to a stack the layout prints, for example page-specific script files. The CSP blocks inline scripts, so use files:

```php
<?php $view->push('scripts') ?><script src="/js/orders.js"></script><?php $view->endPush() ?>
```

### URLs

Add `VeyroPHP\View\ShareUrlHelpers` to the middleware list (the starter does). Every view then gets:

- **`$urls`**, a `VeyroPHP\View\Urls`, for links that work under a sub-folder (`http://localhost/myapp/`) and at a document root alike.
- **`$assetBase`**, the base path of files in `public/` on its own.

```php
<a href="<?= $e($urls->route('posts.show', ['post' => $post->public_id])) ?>">   <!-- named route -->
<a href="<?= $e($urls->route('section', $key)) ?>">                             <!-- positional, like route() -->
<a href="<?= $e($urls->to('/login')) ?>">                                       <!-- a path the router handles -->
<link rel="stylesheet" href="<?= $e($urls->asset('/css/site.css')) ?>">        <!-- a file in public/, versioned -->
<link rel="canonical" href="<?= $e($urls->current()) ?>">
<a class="<?= $urls->is('admin/*') ? 'active' : '' ?>">                        <!-- like request()->is() -->
<?php if ($urls->routeIs('admin.*')): ?> ... <?php endif ?>                     <!-- like request()->routeIs() -->
```

Use `asset()` or `$assetBase` for files in `public/`, and `route()` or `to()` for pages. When a URL goes through the front controller (`/myapp/public/index.php/...`, used without URL rewriting), `to()` keeps `/index.php` so the router is reached, while `asset()` and `$assetBase` leave it out so the web server serves the file. The kernel resolves both bases once per request and puts them in the request attributes `HttpKernel::BASE_ATTRIBUTE` and `HttpKernel::PUBLIC_BASE_ATTRIBUTE`.

These return paths. Two more helpers cover the rest:

```php
<link rel="canonical" href="<?= $view->url($urls->absolute()) ?>">              <!-- https://example.com/about -->
<meta property="og:url" content="<?= $e($urls->absolute('/about')) ?>">
<link rel="stylesheet" href="<?= $view->url($urls->asset('/css/site.css')) ?>"> <!-- /css/site.css?v=t3k9d2 -->
<script src="<?= $view->url($urls->asset('/js/site.js')) ?>" defer></script>
```

- **`absolute($path = null)`** puts the origin of `APP_URL` (config `app.url`) in front of `$path`, or of the current path. The host never comes from the request, because clients can send any `Host` header. Without a valid http(s) `APP_URL` it throws a `LogicException` instead of building a wrong URL.
- **`asset($path)`** adds `?v=` with the file's modification time from `public/`, so a changed file gets a new URL and browsers can cache assets for a long time (see [static asset caching](../deployment.md#static-assets-and-caching)). A missing file gives the plain path. Paths with `..`, backslashes or NUL bytes throw.

### Escaping

- `$content`, `$slot`, named slots, `yield()` and `stack()` are `VeyroPHP\View\HtmlString`: HTML that was already produced by templates. `$e()` prints them unchanged, so `<?= $e($slot) ?>` and `<?= $slot ?>` are the same.
- Everything else is still escaped by `$e()`.
- Never wrap user input in `HtmlString`.

`$e()` is right for text and for quoted attribute values. Three helpers on `$view` cover the other places:

| Where | Use | What it does |
| --- | --- | --- |
| `href`, `src` and other URLs | `<a href="<?= $view->url($site) ?>">` | Escapes the URL. A URL that is not relative and not `http`, `https`, `mailto` or `tel` becomes `#`. This blocks `javascript:`, `data:` and `vbscript:`, also when they are hidden with mixed case, leading spaces or control characters, or tabs and line breaks inside the scheme. |
| Data inside `<script>` (JSON-LD, initial state) | `<script type="application/ld+json"><?= $view->json($schema) ?></script>` | Writes JSON with `<`, `>`, `&`, `'` and `"` inside values escaped, so the data cannot close the script element. A value that cannot be encoded (for example NAN) throws. |
| Several attributes | `<div<?= $view->attr(['class' => $c, 'hidden' => !$open, 'data-config' => $config]) ?>>` | Escapes each value. `true` writes the name only; `false` and `null` leave the attribute out. Arrays become JSON. URL attributes go through `url()`. Event handlers (`onclick`, ...) are refused: attach them from a script file. |

The helpers return `HtmlString`, so wrapping them in `$e()` does not escape them twice. They are methods rather than template variables, so existing templates whose data uses the names `url`, `json` or `attr` keep working.

Templates see only `$e`, `$view` and their data. Data keys named like PHP's own variables (`_GET`, `_SERVER`, `GLOBALS`, ...) are rejected instead of being dropped silently.

### Checking templates: `view:lint`

`php veyro view:lint` reads every template (it never runs them) and reports output that is not escaped. It exits with 1 when it finds one, so it can run in CI.

- **Accepted output:**
  - `$e(...)`
  - `$view->render/component/endComponent/yield/stack/url/json/attr/escape(...)`
  - `$content` and `$slot`
  - literals, casts to int, float or bool, and ternaries whose branches are accepted
- **Warning:** `$e(...)` inside `href`, `src` and other URL attributes. Use `$view->url()` there. `--strict` fails on warnings too.
- **Data flow is not followed:** `<?= $choice ?>` is reported even when `$choice` comes from a fixed list. Write `$e($choice)`, or add the comment `/* view:lint-ignore */` to output that is safe on purpose.

### Mistakes

These throw `ViewException`, and no output buffer is left open:

- an unclosed `section()`, `push()`, `slot()` or `startComponent()`
- an `end...()` call without its start
- a missing component
- invalid names