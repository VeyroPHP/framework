<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Validation;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use VeyroPHP\Application\Application;
use VeyroPHP\Config\Config;
use VeyroPHP\Http\HttpKernel;
use VeyroPHP\Http\HttpServiceProvider;
use VeyroPHP\Routing\HandlerInvoker;
use VeyroPHP\Routing\Router;
use VeyroPHP\Tests\Fixtures\Validation\EnquiryLog;
use VeyroPHP\Tests\Fixtures\Validation\SearchJobs;
use VeyroPHP\Tests\Fixtures\Validation\StoreEnquiry;
use VeyroPHP\Tests\Fixtures\Validation\UploadResume;
use VeyroPHP\Validation\FormRequest;

/**
 * Form requests as handler parameters, through the HTTP kernel.
 */
#[CoversClass(FormRequest::class)]
#[CoversClass(HandlerInvoker::class)]
final class FormRequestTest extends TestCase
{
    private HttpKernel $kernel;

    private EnquiryLog $log;

    protected function setUp(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->container()->instance(Config::class, new Config([]));
        $app->register(new HttpServiceProvider());
        $app->boot();

        $this->log = new EnquiryLog();
        $app->container()->instance(EnquiryLog::class, $this->log);

        $router = $app->container()->make(Router::class);
        $router->post('/enquiries', static fn(StoreEnquiry $form): array => ['saved' => $form->validated(), 'email' => $form->get('email'), 'missing' => $form->get('nope', 'default')]);
        $router->get('/jobs', static fn(SearchJobs $form): array => $form->validated());
        $router->post('/resumes', static fn(UploadResume $form): array => ['name' => $form->get('name'), 'size' => $form->get('resume') instanceof UploadedFile ? $form->get('resume')->getSize() : null]);

        $this->kernel = $app->container()->make(HttpKernel::class);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private function post(string $path, array $body, array $headers = []): ResponseInterface
    {
        return $this->kernel->handle((new ServerRequest('POST', $path, ['Accept' => 'application/json'] + $headers))->withParsedBody($body));
    }

    /**
     * @return array<mixed>
     */
    private static function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);

        return is_array($data) ? $data : [];
    }

    public function testTheHandlerGetsValidatedInputOnly(): void
    {
        $response = $this->post('/enquiries', ['name' => 'Asha', 'email' => '  Asha@Example.TEST ', 'phone' => '9876543210', 'type' => 'person', 'is_admin' => '1']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['saved' => ['name' => 'Asha', 'email' => 'asha@example.test', 'phone' => '9876543210', 'type' => 'person'], 'email' => 'asha@example.test', 'missing' => 'default'],
            self::json($response),
            'prepare() ran before validation; fields without rules are dropped',
        );
    }

    public function testInvalidInputIs422WithTheFormsMessages(): void
    {
        $response = $this->post('/enquiries', ['name' => 'Asha', 'email' => 'asha@example.test', 'phone' => '12345', 'type' => 'company']);

        self::assertSame(422, $response->getStatusCode());
        $error = self::json($response)['error'] ?? [];
        $errors = is_array($error) ? ($error['errors'] ?? []) : [];
        self::assertIsArray($errors);
        self::assertSame(['Enter a 10-digit mobile number.'], $errors['phone'] ?? null);
        self::assertSame(['The company field is required.'], $errors['company'] ?? null);
    }

    public function testAuthorizeRunsFirstAndCanRefuse(): void
    {
        $response = $this->post('/enquiries', ['name' => ''], ['X-Blocked' => '1']);

        self::assertSame(403, $response->getStatusCode(), '403 before the invalid input is looked at');
        self::assertSame(['POST'], $this->log->authorized, 'constructor injection and request() work in authorize()');
    }

    public function testGetRequestsValidateTheQueryString(): void
    {
        $ok = $this->kernel->handle((new ServerRequest('GET', '/jobs?q=php&page=2', ['Accept' => 'application/json']))->withQueryParams(['q' => 'php', 'page' => '2']));
        self::assertSame(['q' => 'php', 'page' => '2'], self::json($ok));

        $bad = $this->kernel->handle((new ServerRequest('GET', '/jobs', ['Accept' => 'application/json']))->withParsedBody(['q' => 'from the body']));
        self::assertSame(422, $bad->getStatusCode(), 'GET ignores the body');
    }

    public function testUploadedFilesAreValidatedWithTheBody(): void
    {
        $file = new UploadedFile(Stream::create(str_repeat('a', 2048)), 2048, UPLOAD_ERR_OK, 'resume.pdf', 'application/pdf');
        $request = (new ServerRequest('POST', '/resumes', ['Accept' => 'application/json']))->withParsedBody(['name' => 'Asha'])->withUploadedFiles(['resume' => $file]);

        $response = $this->kernel->handle($request);
        self::assertSame(['name' => 'Asha', 'size' => 2048], self::json($response));

        $missing = $this->post('/resumes', ['name' => 'Asha']);
        self::assertSame(422, $missing->getStatusCode());
    }

    public function testRequestIsUnavailableBeforeTheFormIsFilled(): void
    {
        $this->expectException(\LogicException::class);
        (new StoreEnquiry(new EnquiryLog()))->request();
    }
}
