<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Validation;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Database\Connection\ConnectionConfig;
use VeyroPHP\Database\DatabaseManager;
use VeyroPHP\Http\HttpErrorResponder;
use VeyroPHP\Http\ResponseFactory;
use VeyroPHP\Support\Ulid;
use VeyroPHP\Validation\InvalidRuleException;
use VeyroPHP\Validation\Rule;
use VeyroPHP\Validation\Rules\Exists;
use VeyroPHP\Validation\Rules\Unique;
use VeyroPHP\Validation\ValidationException;
use VeyroPHP\Validation\Validator;

#[CoversClass(Validator::class)]
#[CoversClass(ValidationException::class)]
#[CoversClass(InvalidRuleException::class)]
#[CoversClass(Unique::class)]
#[CoversClass(Exists::class)]
#[CoversClass(HttpErrorResponder::class)]
final class ValidatorTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator();
    }

    public function testReturnsOnlyValidatedFields(): void
    {
        $data = $this->validator->validate(
            ['name' => 'Ravi', 'email' => 'ravi@example.com', 'is_admin' => '1', 'extra' => 'x'],
            ['name' => 'required|string', 'email' => ['required', 'email']],
        );

        self::assertSame(['name' => 'Ravi', 'email' => 'ravi@example.com'], $data);
    }

    public function testRequiredRejectsMissingNullEmptyWhitespaceAndEmptyArray(): void
    {
        foreach ([[], ['f' => null], ['f' => ''], ['f' => "  \t"], ['f' => []]] as $data) {
            self::assertSame(['f' => ['The f field is required.']], $this->validator->errors($data, ['f' => 'required']), json_encode($data) ?: '');
        }
    }

    public function testOptionalFieldsAndNullable(): void
    {
        self::assertSame([], $this->validator->validate([], ['age' => 'integer']), 'missing optional field is skipped');
        self::assertSame(['age' => null], $this->validator->validate(['age' => ''], ['age' => 'nullable|integer']));
        self::assertSame(['age' => ['The age field must not be empty.']], $this->validator->errors(['age' => ''], ['age' => 'integer']));
    }

    /**
     * @return iterable<string, array{string, mixed, bool}>
     */
    public static function ruleCases(): iterable
    {
        yield 'string ok' => ['string', 'abc', true];
        yield 'string int' => ['string', 5, false];
        yield 'integer int' => ['integer', 5, true];
        yield 'integer string' => ['integer', '-42', true];
        yield 'integer leading zeros' => ['integer', '007', true];
        yield 'integer float string' => ['integer', '1.5', false];
        yield 'integer overflow' => ['integer', '99999999999999999999', false];
        yield 'integer spaces' => ['integer', ' 5', false];
        yield 'numeric decimal' => ['numeric', '2.50', true];
        yield 'numeric exponent' => ['numeric', '1e3', true];
        yield 'numeric word' => ['numeric', 'ten', false];
        yield 'numeric hex' => ['numeric', '0x1A', false];
        yield 'boolean string' => ['boolean', 'true', true];
        yield 'boolean zero' => ['boolean', '0', true];
        yield 'boolean yes' => ['boolean', 'yes', false];
        yield 'array ok' => ['array', [1], true];
        yield 'array string' => ['array', 'a', false];
        yield 'email ok' => ['email', 'a.b+c@example.co.in', true];
        yield 'email no domain' => ['email', 'ravi@', false];
        yield 'email header injection' => ['email', "a@b.com\r\nBcc: x@y.com", false];
        yield 'url https' => ['url', 'https://example.com/path?q=1', true];
        yield 'url javascript' => ['url', 'javascript:alert(1)', false];
        yield 'url ftp' => ['url', 'ftp://example.com', false];
        yield 'min string' => ['min:3', 'ab', false];
        yield 'min string multibyte' => ['min:3', 'नमस्', true];
        yield 'max string' => ['max:3', 'abcd', false];
        yield 'max array' => ['max:2', [1, 2, 3], false];
        yield 'in ok' => ['in:draft,published', 'draft', true];
        yield 'in bad' => ['in:draft,published', 'deleted', false];
        yield 'in array value' => ['in:a,b', ['a'], false];
        yield 'not_in' => ['not_in:admin,root', 'admin', false];
        yield 'regex ok' => ['regex:/^[A-Z]{3}\d{2}$/', 'ABC12', true];
        yield 'regex with comma' => ['regex:/^\d{2,4}$/', '123', true];
        yield 'regex bad' => ['regex:/^[A-Z]+$/', 'abc', false];
        yield 'date ok' => ['date', '2026-10-26', true];
        yield 'date impossible' => ['date', '2026-02-30', false];
        yield 'date format' => ['date_format:d/m/Y', '26/10/2026', true];
        yield 'date format wrong' => ['date_format:d/m/Y', '2026-10-26', false];
        yield 'ulid ok' => ['ulid', '01ARYZ6S41TSV4RRFFQ69G5FAV', true];
        yield 'ulid bad' => ['ulid', '1', false];
        yield 'alpha_dash ok' => ['alpha_dash', 'post_title-1', true];
        yield 'alpha_dash unicode' => ['alpha_dash', 'हिंदी', true];
        yield 'alpha_dash space' => ['alpha_dash', 'a b', false];
        // A trailing newline must not pass ("$" without D accepts it; VQ-001).
        yield 'alpha_dash trailing newline' => ['alpha_dash', "abc\n", false];
        yield 'alpha_dash trailing crlf' => ['alpha_dash', "abc\r\n", false];
        yield 'alpha_dash leading newline' => ['alpha_dash', "\nabc", false];
        yield 'numeric trailing newline' => ['numeric', "12\n", false];
        yield 'numeric decimal trailing newline' => ['numeric', "2.50\n", false];
        yield 'integer trailing newline' => ['integer', "12\n", false];
        yield 'integer negative trailing newline' => ['integer', "-42\n", false];
        yield 'ulid trailing newline' => ['ulid', "01ARYZ6S41TSV4RRFFQ69G5FAV\n", false];
        yield 'string keeps newlines' => ['string', "line one\nline two\n", true];
        // VQ-047: bcrypt reads 72 bytes, and a Devanagari letter is 3 bytes in UTF-8.
        yield 'max_bytes ascii at the limit' => ['max_bytes:72', str_repeat('a', 72), true];
        yield 'max_bytes ascii over' => ['max_bytes:72', str_repeat('a', 73), false];
        yield 'max_bytes multibyte at 72 characters' => ['max_bytes:72', str_repeat('अ', 72), false];
        yield 'max_bytes multibyte at 24 characters' => ['max_bytes:72', str_repeat('अ', 24), true];
        yield 'max_bytes array' => ['max_bytes:72', ['a'], false];
    }

    public function testMaxBytesHasAMessageWithoutTheWordBytes(): void
    {
        self::assertSame(
            ['password' => ['The password field is too long.']],
            $this->validator->errors(['password' => str_repeat('अ', 30)], ['password' => 'string|max_bytes:72']),
        );
    }

    #[DataProvider('ruleCases')]
    public function testBuiltInRules(string $rule, mixed $value, bool $valid): void
    {
        $errors = $this->validator->errors(['f' => $value], ['f' => [$rule]]);

        self::assertSame($valid, $errors === [], json_encode($errors) ?: '');
    }

    public function testNumericSizeRulesCompareValuesNotLength(): void
    {
        self::assertSame([], $this->validator->errors(['age' => '120'], ['age' => 'integer|between:18,120']));
        self::assertSame(['age' => ['The age field must be at least 18.']], $this->validator->errors(['age' => '9'], ['age' => 'integer|min:18']));
        self::assertSame(['name' => ['The name field must not be longer than 3 characters.']], $this->validator->errors(['name' => '1234'], ['name' => 'string|max:3']));
    }

    public function testConfirmedAndSame(): void
    {
        $rules = ['password' => 'required|confirmed', 'repeat' => 'same:password'];

        self::assertSame([], $this->validator->errors(['password' => 'x1', 'password_confirmation' => 'x1', 'repeat' => 'x1'], $rules));
        self::assertSame(
            ['password' => ['The password confirmation does not match.'], 'repeat' => ['The repeat field must match password.']],
            $this->validator->errors(['password' => 'x1', 'password_confirmation' => 'x2', 'repeat' => 'x3'], $rules),
        );
    }

    /**
     * HTTP-4: the confirmation of a nested field sits next to it, not at the top level.
     */
    public function testConfirmedOnNestedAndWildcardFields(): void
    {
        $nested = ['account.password' => 'confirmed'];
        self::assertSame([], $this->validator->errors(['account' => ['password' => 'x1', 'password_confirmation' => 'x1']], $nested));
        self::assertSame(['account.password' => ['The account password confirmation does not match.']], $this->validator->errors(['account' => ['password' => 'x1', 'password_confirmation' => 'x2']], $nested));
        self::assertNotSame([], $this->validator->errors(['account' => ['password' => 'x1']], $nested), 'a missing confirmation fails');
        self::assertNotSame([], $this->validator->errors(['account' => ['password' => 'x1'], 'account.password_confirmation' => 'x1'], $nested), 'a flat key is not the nested confirmation');

        $wildcard = ['users.*.pin' => 'confirmed'];
        self::assertSame([], $this->validator->errors(['users' => [['pin' => '1234', 'pin_confirmation' => '1234'], ['pin' => '9', 'pin_confirmation' => '9']]], $wildcard));
        self::assertSame(['users.1.pin'], array_keys($this->validator->errors(['users' => [['pin' => '1234', 'pin_confirmation' => '1234'], ['pin' => '9', 'pin_confirmation' => '8']]], $wildcard)));
    }

    public function testNestedFieldsWithDotNotation(): void
    {
        $data = $this->validator->validate(['address' => ['city' => 'Alwar', 'pin' => '301001']], ['address.city' => 'required|string', 'address.pin' => 'required|integer']);

        self::assertSame(['address.city' => 'Alwar', 'address.pin' => '301001'], $data);
    }

    /**
     * VQ-017: a parent array returns only the sub-keys that have rules.
     */
    public function testParentArrayContainsOnlyValidatedSubKeys(): void
    {
        $data = $this->validator->validate(
            ['address' => ['city' => 'Alwar', 'is_admin' => true, 'pin' => '301001']],
            ['address' => 'required|array', 'address.city' => 'required|string', 'address.pin' => 'nullable|integer'],
        );

        self::assertSame(['address' => ['city' => 'Alwar', 'pin' => '301001'], 'address.city' => 'Alwar', 'address.pin' => '301001'], $data);
    }

    public function testArrayWithoutRulesBelowItIsReturnedAsGiven(): void
    {
        $data = $this->validator->validate(['tags' => ['a', 'b']], ['tags' => 'array|max:5']);

        self::assertSame(['tags' => ['a', 'b']], $data, 'unchanged behaviour');
    }

    public function testWildcardValidatesEveryItem(): void
    {
        $data = $this->validator->validate(['tags' => ['php', 'web', 'x' => 'api']], ['tags' => 'required|array', 'tags.*' => 'string|max:5']);
        self::assertSame(['tags' => ['php', 'web', 'x' => 'api'], 'tags.0' => 'php', 'tags.1' => 'web', 'tags.x' => 'api'], $data);

        self::assertSame(
            ['tags.1' => ['The tags 1 field must be text.'], 'tags.2' => ['The tags 2 field must not be longer than 5 characters.']],
            $this->validator->errors(['tags' => ['ok', ['nested'], 'far-too-long']], ['tags' => 'array', 'tags.*' => 'string|max:5']),
        );
    }

    public function testNestedWildcardsAndIndexedErrorKeys(): void
    {
        $input = ['items' => [['id' => '7', 'qty' => '2', 'secret' => 'x'], ['id' => 'abc', 'qty' => '1'], ['qty' => '3']]];
        $rules = ['items' => 'required|array', 'items.*.id' => 'required|integer', 'items.*.qty' => 'integer'];

        self::assertSame(
            ['items.1.id' => ['The items 1 id field must be a whole number.'], 'items.2.id' => ['The items 2 id field is required.']],
            $this->validator->errors($input, $rules),
        );

        $valid = $this->validator->validate(['items' => [['id' => '7', 'qty' => '2', 'secret' => 'x'], ['id' => '8']]], $rules);
        self::assertSame([['id' => '7', 'qty' => '2'], ['id' => '8']], $valid['items'], 'only validated sub-keys, per item');
        self::assertSame('8', $valid['items.1.id']);
        self::assertArrayNotHasKey('items.1.qty', $valid, 'a missing optional item field is skipped');
    }

    public function testDeeperWildcards(): void
    {
        $errors = $this->validator->errors(
            ['groups' => ['a' => ['members' => ['x@example.com', 'bad']], 'b' => ['members' => ['y@example.com']]]],
            ['groups.*.members.*' => 'email'],
        );

        self::assertSame(['groups.a.members.1' => ['The groups a members 1 field must be a valid email address.']], $errors);
    }

    public function testWildcardOverMissingOrScalarMatchesNothing(): void
    {
        self::assertSame([], $this->validator->validate([], ['tags.*' => 'required|string']), 'require the list with its own rule');
        self::assertSame([], $this->validator->validate(['tags' => 'not-a-list'], ['tags.*' => 'string']));
        self::assertSame(['tags' => ['The tags field must be a list.']], $this->validator->errors(['tags' => 'not-a-list'], ['tags' => 'array', 'tags.*' => 'string']));
    }

    public function testTooManyWildcardItemsAreRejected(): void
    {
        $errors = $this->validator->errors(['ids' => range(1, Validator::MAX_WILDCARD_PATHS + 1)], ['ids' => 'array', 'ids.*' => 'integer']);

        self::assertSame(['ids.*' => ['The ids * field has too many items.']], $errors);
    }

    public function testTenThousandItemsValidateQuickly(): void
    {
        $items = [];
        for ($i = 0; $i < 10_000; $i++) {
            $items[] = ['id' => (string) $i, 'name' => 'Item ' . $i];
        }

        $start = hrtime(true);
        $data = $this->validator->validate(['items' => $items], ['items' => 'required|array', 'items.*.id' => 'required|integer', 'items.*.name' => 'required|string|max:50']);
        $seconds = (hrtime(true) - $start) / 1e9;

        self::assertIsArray($data['items']);
        self::assertCount(10_000, $data['items']);
        self::assertLessThan(2.0, $seconds, sprintf('%.2f s for 10,000 items (20,000 checks)', $seconds));
    }

    /**
     * With a rule on every item ("items.*" => "array") each item is a parent to rebuild.
     * A first version compared every key with every key: 10,000 items took 42 s. The
     * rebuild must stay linear.
     */
    public function testRebuildingManyParentsStaysLinear(): void
    {
        $items = [];
        for ($i = 0; $i < 10_000; $i++) {
            $items[] = ['id' => (string) $i, 'name' => 'Item ' . $i, 'extra' => 'dropped'];
        }

        $start = hrtime(true);
        $data = $this->validator->validate(['items' => $items], ['items' => 'required|array', 'items.*' => 'array', 'items.*.id' => 'required|integer', 'items.*.name' => 'string']);
        $seconds = (hrtime(true) - $start) / 1e9;

        self::assertIsArray($data['items']);
        self::assertSame(['id' => '9999', 'name' => 'Item 9999'], $data['items'][9999]);
        self::assertSame(['id' => '5', 'name' => 'Item 5'], $data['items.5']);
        self::assertLessThan(2.0, $seconds, sprintf('%.2f s for 10,000 parents', $seconds));
    }

    public function testFirstFailingRuleOnlyAndCustomMessages(): void
    {
        $errors = (new Validator(['email' => 'Bad email address.']))->errors(
            ['email' => 5, 'name' => ''],
            ['email' => 'string|email', 'name' => 'required'],
            ['name.required' => 'Please enter your name.'],
        );

        self::assertSame(['email' => ['The email field must be text.'], 'name' => ['Please enter your name.']], $errors);
        self::assertSame(['email' => ['Bad email address.']], (new Validator(['email' => 'Bad email address.']))->errors(['email' => 'x'], ['email' => 'email']));
    }

    public function testCustomRuleObject(): void
    {
        $evenOnly = new class implements Rule {
            public function validate(string $field, mixed $value, array $data): ?string
            {
                return is_numeric($value) && (int) $value % 2 === 0 ? null : 'The :field field must be even.';
            }
        };

        self::assertSame(['lucky_number' => ['The lucky number field must be even.']], $this->validator->errors(['lucky_number' => '3'], ['lucky_number' => ['integer', $evenOnly]]));
    }

    public function testValidationExceptionCarriesErrorsAnd422(): void
    {
        try {
            $this->validator->validate(['email' => 'x'], ['email' => 'email', 'name' => 'required']);
            self::fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getStatusCode());
            self::assertSame(['email' => ['The email field must be a valid email address.'], 'name' => ['The name field is required.']], $e->errors());
            self::assertSame(['errors' => $e->errors()], $e->getDetails());
        }
    }

    public function testErrorResponderRendersValidationErrors(): void
    {
        $psr17 = new Psr17Factory();
        $responder = new HttpErrorResponder(new ResponseFactory($psr17, $psr17));
        $exception = new ValidationException(['name' => ['The name field is required.'], 'bio' => ['<script>alert(1)</script>']]);

        $json = $responder->respond($exception, new ServerRequest('POST', '/', ['Accept' => 'application/json']));
        $html = $responder->respond($exception, new ServerRequest('POST', '/'));

        self::assertSame(422, $json->getStatusCode());
        self::assertSame(
            '{"error":{"status":422,"message":"The submitted data failed validation.","errors":{"name":["The name field is required."],"bio":["<script>alert(1)</script>"]}}}',
            (string) $json->getBody(),
        );
        self::assertStringContainsString('<li>The name field is required.</li>', (string) $html->getBody());
        self::assertStringContainsString('&lt;script&gt;', (string) $html->getBody());
        self::assertStringNotContainsString('<script>alert', (string) $html->getBody());
    }

    public function testInvalidRuleDefinitionsThrow(): void
    {
        foreach ([['f' => 'unknown_rule'], ['f' => 'min'], ['f' => 'min:abc'], ['f' => 'in'], ['f' => 'regex:not-a-regex']] as $rules) {
            try {
                $this->validator->errors(['f' => 'value'], $rules);
                self::fail('Expected InvalidRuleException for ' . json_encode($rules));
            } catch (InvalidRuleException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testUniqueAndExistsRules(): void
    {
        $db = (new DatabaseManager(['m' => new ConnectionConfig('m', 'sqlite', ':memory:')], 'm'))->connection();
        $db->statement('create table users (id integer primary key, email text)');
        $db->table('users')->insert([['id' => 1, 'email' => 'taken@example.com'], ['id' => 2, 'email' => 'other@example.com']]);

        self::assertSame(['email' => ['The email has already been taken.']], $this->validator->errors(['email' => 'taken@example.com'], ['email' => [new Unique($db, 'users', 'email')]]));
        self::assertSame([], $this->validator->errors(['email' => 'new@example.com'], ['email' => [new Unique($db, 'users', 'email')]]));
        self::assertSame([], $this->validator->errors(['email' => 'taken@example.com'], ['email' => [new Unique($db, 'users', 'email', 1)]]), 'own record is ignored');
        self::assertSame([], $this->validator->errors(['user_id' => '2'], ['user_id' => ['integer', new Exists($db, 'users')]]));
        self::assertSame(['user_id' => ['The selected user id is invalid.']], $this->validator->errors(['user_id' => '9'], ['user_id' => ['integer', new Exists($db, 'users')]]));
        self::assertSame(['email' => ['The selected email is invalid.']], $this->validator->errors(['email' => ['x']], ['email' => [new Exists($db, 'users', 'email')]]));
        self::assertTrue(Ulid::isValid(Ulid::generate()));
    }
}
