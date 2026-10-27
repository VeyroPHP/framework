<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Security;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VeyroPHP\Http\Exception\HttpException;
use VeyroPHP\Http\HttpKernel;
use VeyroPHP\Security\UrlSigner;
use VeyroPHP\Security\ValidateSignature;

#[CoversClass(UrlSigner::class)]
#[CoversClass(ValidateSignature::class)]
final class UrlSignerTest extends TestCase
{
    private int $now = 1_793_000_000;

    private function signer(string $key = '', string ...$previous): UrlSigner
    {
        return new UrlSigner($key === '' ? str_repeat('k', 32) : $key, array_values($previous), fn(): int => $this->now);
    }

    /**
     * @return array{string, array<string, string>}
     */
    private static function split(string $signed): array
    {
        [$path, $query] = explode('?', $signed, 2) + [1 => ''];
        parse_str($query, $params);

        /** @var array<string, string> $params */
        return [$path, $params];
    }

    public function testSignAndVerify(): void
    {
        $signer = $this->signer();
        $signed = $signer->sign('/email/verify/42/abc', ['source' => 'mail'], $this->now + 3600);

        self::assertMatchesRegularExpression('#^/email/verify/42/abc\?source=mail&expires=\d+&signature=[A-Za-z0-9_-]{43}$#', $signed);
        [$path, $query] = self::split($signed);
        self::assertTrue($signer->verify($path, $query));
    }

    public function testParameterOrderDoesNotMatter(): void
    {
        $signer = $this->signer();
        [$path, $query] = self::split($signer->sign('/x', ['b' => '2', 'a' => '1']));

        self::assertTrue($signer->verify($path, array_reverse($query, true)));
    }

    public function testAnyChangeIsRejected(): void
    {
        $signer = $this->signer();
        [$path, $query] = self::split($signer->sign('/invite/5', ['role' => 'member'], $this->now + 60));

        self::assertFalse($signer->verify('/invite/6', $query), 'other path');
        self::assertFalse($signer->verify($path, ['role' => 'admin'] + $query), 'changed parameter');
        self::assertFalse($signer->verify($path, $query + ['extra' => '1']), 'added parameter');
        self::assertFalse($signer->verify($path, ['expires' => (string) ($this->now + 999_999)] + $query), 'longer expiry');
        $withoutExpiry = $query;
        unset($withoutExpiry['expires']);
        self::assertFalse($signer->verify($path, $withoutExpiry), 'expiry removed');
        self::assertFalse($signer->verify($path, ['signature' => 'x' . $query['signature']] + $query), 'other signature');
        self::assertFalse($signer->verify($path, ['role' => ['member']] + $query), 'array parameter');
        $unsigned = $query;
        unset($unsigned['signature']);
        self::assertFalse($signer->verify($path, $unsigned), 'no signature');
        self::assertFalse($this->signer(str_repeat('z', 32))->verify($path, $query), 'other key');
    }

    public function testExpiry(): void
    {
        $signer = $this->signer();
        [$path, $query] = self::split($signer->sign('/x', [], $this->now + 60));

        $this->now += 60;
        self::assertTrue($signer->verify($path, $query));
        $this->now += 1;
        self::assertFalse($signer->verify($path, $query));

        [$path, $query] = self::split($signer->sign('/forever'));
        self::assertArrayNotHasKey('expires', $query);
        $this->now += 10 ** 8;
        self::assertTrue($signer->verify($path, $query));
    }

    public function testPreviousKeysStillVerify(): void
    {
        $old = str_repeat('o', 32);
        [$path, $query] = self::split($this->signer($old)->sign('/x'));

        self::assertTrue($this->signer(str_repeat('n', 32), $old)->verify($path, $query));
        self::assertFalse($this->signer(str_repeat('n', 32))->verify($path, $query));
    }

    public function testSigningKeyIsNotTheRawKey(): void
    {
        $key = str_repeat('k', 32);
        [$path, $query] = self::split($this->signer($key)->sign('/x'));

        $naive = rtrim(strtr(base64_encode(hash_hmac('sha256', '/x?', $key, true)), '+/', '-_'), '=');
        self::assertNotSame($naive, $query['signature'], 'a derived key, so the HMAC does not reuse APP_KEY directly');
    }

    public function testShortKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UrlSigner('short');
    }

    public function testMiddleware(): void
    {
        $signer = $this->signer();
        $middleware = new ValidateSignature($signer);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], 'ok');
            }
        };
        [$path, $query] = self::split($signer->sign('/download/7', [], $this->now + 60));

        // The kernel puts the application path (without base path) on the request.
        $request = (new ServerRequest('GET', '/myapp' . $path))->withQueryParams($query)->withAttribute(HttpKernel::PATH_ATTRIBUTE, $path);
        self::assertSame('ok', (string) $middleware->process($request, $handler)->getBody());

        try {
            $middleware->process($request->withQueryParams(['signature' => 'bad'] + $query), $handler);
            self::fail('expected 403');
        } catch (HttpException $e) {
            self::assertSame(403, $e->getStatusCode());
            self::assertSame('This link is invalid or has expired.', $e->getMessage());
        }
    }
}
