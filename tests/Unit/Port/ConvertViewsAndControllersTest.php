<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Port;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Http\HttpKernel;
use VeyroPHP\Port\Convert\BladeConverter;
use VeyroPHP\Port\Convert\ControllerConverter;
use VeyroPHP\Port\Convert\MigrationConverter;
use VeyroPHP\Routing\Router;
use VeyroPHP\View\Urls;
use VeyroPHP\View\View;

/**
 * Converted Blade templates are rendered with the real View, and converted
 * controllers are checked line by line.
 */
final class ConvertViewsAndControllersTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/veyrophp-views-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/components', 0777, true);
        mkdir($this->dir . '/partials', 0777, true);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->dir);
    }

    private function blade(string $name, string $template): string
    {
        $file = (new BladeConverter())->convert($name . '.blade.php', $template);
        self::assertTrue($file->parses(), $file->content);
        file_put_contents($this->dir . '/' . str_replace('.', '/', $name) . '.php', $file->content);

        return $file->content;
    }

    private function router(): Router
    {
        $router = new Router();
        $router->get('/', fn() => '')->name('home');
        $router->get('/section/{key}', fn() => '')->name('section');
        $router->get('/posts/{post}/comments/{comment}', fn() => '')->name('comment');

        return $router;
    }

    public function testConvertedTemplatesRenderWithTheRealView(): void
    {
        $this->blade('layout', <<<'BLADE'
<html><head><title>@yield('title', 'Site')</title>@stack('head')</head>
<body>
<nav><a href="{{ route('home') }}" @class(['nav', 'active' => request()->is('/')])>Home</a></nav>
<main>@yield('content')</main>
</body></html>
BLADE);
        $this->blade('components.alert', <<<'BLADE'
@props(['type' => 'info'])
<div class="alert alert-{{ $type }}">{{ $slot }}{{ $message ?? '' }}</div>
BLADE);
        $this->blade('partials.item', '<li>{{ $item }}</li>');
        $content = $this->blade('page', <<<'BLADE'
@extends('layout')
@section('title', 'Orders')
@push('head')<meta name="robots" content="noindex">@endpush
@section('content')
    {{-- a comment --}}
    <h1>{{ $title }} &amp; {!! $trusted !!}</h1>
    <x-alert type="error" :message="$count . ' items'" />
    <ul>
    @forelse ($items as $item)
        <li class="@if ($loop->first)first @endif @if ($loop->last)last @endif">{{ $loop->iteration }}/{{ $loop->count }} {{ $item }}</li>
    @empty
        <li>none</li>
    @endforelse
    </ul>
    @foreach ($items as $item) @include('partials.item', ['item' => $item]) @endforeach
    <a href="{{ route('section', 'latest-jobs') }}">Latest</a>
    <a href="{{ url('/css/site.css') }}">CSS</a>
    @isset($missing) never @endisset
    @unless ($count > 5) small @endunless
    write to admin@example.com, keep @media and @{{ raw }}
    @can('edit') secret @endcan
@endsection
BLADE);

        self::assertStringNotContainsString("section('content')", $content, '"content" is the main output in VeyroPHP');

        $view = new View($this->dir);
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/app/')->withAttribute(HttpKernel::PATH_ATTRIBUTE, '/');
        $view->share('urls', new Urls($this->router(), '/app', $request));
        $html = $view->render('page', ['title' => '<Tom>', 'trusted' => '<b>ok</b>', 'items' => ['a', 'b', 'c'], 'count' => 3]);

        foreach ([
            '<title>Orders</title>',
            '<meta name="robots" content="noindex">',
            '<a href="/app/" class="nav active">Home</a>',
            '<h1>&lt;Tom&gt; &amp; <b>ok</b></h1>',
            '<div class="alert alert-error">3 items</div>',
            '<li class="first  ">1/3 a</li>',
            '<li class=" last ">3/3 c</li>',
            '<li>b</li>',
            '<a href="/app/section/latest-jobs">Latest</a>',
            '<a href="/app/css/site.css">CSS</a>',
            'small',
            'write to admin@example.com, keep @media and {{ raw }}',
        ] as $expected) {
            self::assertStringContainsString($expected, $html);
        }

        self::assertStringNotContainsString('never', $html);
        self::assertStringNotContainsString('secret', $html, '@can fails closed until the permission check is ported');
        self::assertStringNotContainsString('none', $html);
        self::assertStringContainsString('<li>none</li>', $view->render('page', ['title' => 't', 'trusted' => '', 'items' => [], 'count' => 0]), '@empty of @forelse');
    }

    public function testBladeNotes(): void
    {
        $file = (new BladeConverter())->convert('v.blade.php', <<<'BLADE'
@section('page-title', 'X')
<form>@csrf @method('PUT')</form>
@php $tag = config('app.name'); $short = Str::limit($tag, 3); $ad = \App\Models\Ad::active(); @endphp
@vite('resources/js/app.js')
@auth hi @endauth
{{ old('email') }}
BLADE);

        self::assertTrue($file->parses());
        self::assertStringContainsString("\$view->section('page_title', (string) 'X')", $file->content);
        self::assertStringContainsString('<input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">', $file->content);
        $todos = implode("\n", $file->todos);

        foreach ([
            'name "page-title" became "page_title"',
            '@csrf: pass $csrfToken',
            '@method(\'PUT\'): method spoofing is not supported',
            'config() is a Laravel helper',
            "Str:: is Laravel's support class",
            'the view calls Ad::active()',
            '@vite has no VeyroPHP equivalent',
            '@auth: pass the logged-in user',
            'old() is a Laravel helper',
        ] as $expected) {
            self::assertStringContainsString($expected, $todos);
        }
    }

    public function testUrls(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/app/admin/users')->withAttribute(HttpKernel::PATH_ATTRIBUTE, '/admin/users');
        $router = $this->router();
        $router->get('/admin/users', fn() => '')->name('admin.users');
        $urls = new Urls($router, '/app', $request);

        self::assertSame('/app/section/news', $urls->route('section', 'news'));
        self::assertSame('/app/section/news', $urls->route('section', ['key' => 'news']));
        self::assertSame('/app/posts/5/comments/9', $urls->route('comment', [5, 9]));
        self::assertSame('/app/css/a.css', $urls->to('css/a.css'));
        self::assertSame('/app/admin/users', $urls->current());
        self::assertTrue($urls->is('admin/*'));
        self::assertTrue($urls->is('/admin/users/'));
        self::assertFalse($urls->is('admin'));
        self::assertTrue($urls->routeIs('admin.*'));
        self::assertFalse($urls->routeIs('home'));
        self::assertSame('/', (new Urls($router, ''))->to('/'));
    }

    public function testController(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PageController extends Controller
{
    public function show(Request $request, string $slug)
    {
        abort_unless(strlen($slug) < 100, 404);
        $page = Page::where('slug', $slug)->firstOrFail();
        $data = $request->validate(['q' => 'nullable|string']);
        $count = Cache::remember('pages', 60, fn () => Page::count());
        Log::info('shown');

        return view('pages.show', compact('page', 'count'));
    }

    public function list()
    {
        $pages = Page::live()->latest()->get(['id', 'title']);
        abort_if($pages === [], 404, 'None');

        return response()->json(['pages' => $pages, 'home' => route('home'), 'size' => config('app.per_page')]);
    }

    public function feed()
    {
        return response(view('feed')->render(), 200, ['Content-Type' => 'application/xml']);
    }
}
PHP;
        $file = (new ControllerConverter())->convert('app/Http/Controllers/PageController.php', $code);
        self::assertNotNull($file);
        self::assertTrue($file->parses(), $file->content);

        foreach ([
            'final class PageController',
            "    public function __construct(\n        private readonly View \$view,\n        private readonly ResponseFactory \$response,\n        private readonly Orm \$orm,\n        private readonly Config \$config,\n        private readonly Router \$router,\n        private readonly Cache \$cache,\n        private readonly LoggerInterface \$logger,\n        private readonly Validator \$validator,\n    ) {}",
            'use Psr\Http\Message\ServerRequestInterface;',
            'public function show(ServerRequestInterface $request, string $slug)',
            'if (!(strlen($slug) < 100)) { throw new \VeyroPHP\Http\Exception\HttpException(404); }',
            "\$page = \$this->orm->query(Page::class)->where('slug', \$slug)->firstOrFail();",
            "\$data = \$this->validator->validate(\$request->getParsedBody() ?? [], ['q' => 'nullable|string']);",
            "\$count = \$this->cache->remember('pages', 60, fn () => \$this->orm->query(Page::class)->count());",
            "\$this->logger->info('shown');",
            "return \$this->view->render('pages.show', compact('page', 'count'));",
            "if (\$pages === []) { throw new \VeyroPHP\Http\Exception\HttpException(404, 'None'); }",
            "return \$this->response->json(['pages' => \$pages, 'home' => \$this->router->path('home'), 'size' => \$this->config->get('app.per_page')]);",
            "return \$this->response->html(\$this->view->render('feed'), 200, ['Content-Type' => 'application/xml']);",
            '// TODO(port): Page::live() (scope or Eloquent feature) has no VeyroPHP equivalent',
        ] as $expected) {
            self::assertStringContainsString($expected, $file->content);
        }

        self::assertStringNotContainsString('extends Controller', $file->content);
        self::assertStringNotContainsString('use Illuminate\Support\Facades', $file->content);
        self::assertNull((new ControllerConverter())->convert('Controller.php', "<?php\n\nnamespace App\\Http\\Controllers;\n\nabstract class Controller\n{\n    //\n}\n"), 'Laravel\'s empty base controller is not needed');
    }

    public function testFrameworkTablesAreFlagged(): void
    {
        $file = (new MigrationConverter())->convert('m.php', "<?php\nuse Illuminate\\Support\\Facades\\Schema;\nreturn new class extends Migration {\n    public function up(): void\n    {\n        Schema::create('jobs', fn (\$t) => \$t->id());\n        Schema::create('sessions', fn (\$t) => \$t->id());\n    }\n};\n");

        self::assertStringContainsString("        // TODO(port): \"jobs\" is a Laravel framework table: the starter creates VeyroPHP's own queue tables (QueueSchema); remove it here\n        \$schema->create('jobs'", $file->content);
        self::assertStringContainsString('"sessions" is a Laravel framework table: VeyroPHP keeps sessions in the cache store, not in a table', $file->content);
    }
}
