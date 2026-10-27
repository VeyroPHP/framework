<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Validation\InvalidRuleException;
use VeyroPHP\Validation\Validator;

/**
 * Rules added with the form-request feature: formats, comparisons, dates and
 * conditional "required".
 */
#[CoversClass(Validator::class)]
final class MoreRulesTest extends TestCase
{
    private Validator $validator;

    private string $timezone;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('Asia/Kolkata');
        // 2027-01-15 12:00 in India
        $this->validator = new Validator(clock: static fn(): int => (new \DateTimeImmutable('2027-01-15 12:00:00', new \DateTimeZone('Asia/Kolkata')))->getTimestamp());
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    /**
     * @return iterable<string, array{string, mixed, bool}>
     */
    public static function cases(): iterable
    {
        yield 'accepted on' => ['accepted', 'on', true];
        yield 'accepted yes' => ['accepted', 'yes', true];
        yield 'accepted true' => ['accepted', true, true];
        yield 'accepted no' => ['accepted', 'no', false];
        yield 'accepted 0' => ['accepted', '0', false];

        yield 'alpha hindi' => ['alpha', 'नमस्ते', true];
        yield 'alpha latin' => ['alpha', 'Asha', true];
        yield 'alpha digit' => ['alpha', 'Asha1', false];
        yield 'alpha space' => ['alpha', 'Asha K', false];
        yield 'alpha_num' => ['alpha_num', 'Flat12B', true];
        yield 'alpha_num dash' => ['alpha_num', 'Flat-12', false];

        yield 'digits 6 pin code' => ['digits:6', '301001', true];
        yield 'digits keeps leading zero' => ['digits:4', '0042', true];
        yield 'digits int' => ['digits:3', 123, true];
        yield 'digits too short' => ['digits:6', '30100', false];
        yield 'digits sign' => ['digits:3', '-12', false];
        yield 'digits decimal' => ['digits:3', '1.5', false];
        yield 'digits_between phone' => ['digits_between:10,12', '9876543210', true];
        yield 'digits_between too long' => ['digits_between:10,12', '9876543210123', false];
        yield 'digits_between letters' => ['digits_between:1,5', '12a', false];

        yield 'uuid v4' => ['uuid', '9f1c2a4e-3b5d-4c6e-8f7a-1b2c3d4e5f60', true];
        yield 'uuid upper' => ['uuid', '9F1C2A4E-3B5D-4C6E-8F7A-1B2C3D4E5F60', true];
        yield 'uuid short' => ['uuid', '9f1c2a4e-3b5d-4c6e-8f7a', false];

        yield 'json object' => ['json', '{"a":[1,2]}', true];
        yield 'json scalar' => ['json', '"text"', true];
        yield 'json invalid' => ['json', '{a:1}', false];

        yield 'ip v4' => ['ip', '203.0.113.9', true];
        yield 'ip v6' => ['ip', '2001:db8::1', true];
        yield 'ip invalid' => ['ip', '300.1.1.1', false];
        yield 'ipv4 rejects v6' => ['ipv4', '2001:db8::1', false];
        yield 'ipv6 rejects v4' => ['ipv6', '203.0.113.9', false];

        yield 'lowercase' => ['lowercase', 'asha-k', true];
        yield 'lowercase upper' => ['lowercase', 'Asha', false];
        yield 'uppercase' => ['uppercase', 'IFSC0001', true];
        yield 'uppercase lower' => ['uppercase', 'Ifsc', false];

        yield 'starts_with' => ['starts_with:+91,0', '+919876543210', true];
        yield 'starts_with none' => ['starts_with:+91,0', '919876543210', false];
        yield 'ends_with' => ['ends_with:.in,.com', 'example.in', true];
        yield 'ends_with none' => ['ends_with:.in,.com', 'example.org', false];

        yield 'size string' => ['size:10', 'ABCDE1234F', true];
        yield 'size string wrong' => ['size:10', 'ABCDE1234', false];
        yield 'size numeric' => ['integer|size:18', '18', true];
        yield 'size array' => ['array|size:2', ['a', 'b'], true];

        yield 'after today' => ['after:today', '2027-01-16', true];
        yield 'after today, same day' => ['after:today', '2027-01-15', false];
        yield 'after_or_equal today' => ['after_or_equal:today', '2027-01-15', true];
        yield 'before tomorrow' => ['before:tomorrow', '2027-01-15', true];
        yield 'before yesterday' => ['before:yesterday', '2027-01-14', false];
        yield 'before_or_equal yesterday' => ['before_or_equal:yesterday', '2027-01-14', true];
        yield 'after now, datetime-local' => ['after:now', '2027-01-15T12:30', true];
        yield 'before now, iso with offset' => ['before:now', '2027-01-15T06:00:00+00:00', true];
        yield 'after a fixed date' => ['after:2026-12-31', '2027-01-01', true];
        yield 'after: invalid date' => ['after:today', '2027-02-30', false];
        yield 'after: not a date' => ['after:today', 'next week', false];
    }

    #[DataProvider('cases')]
    public function testRule(string $rule, mixed $value, bool $valid): void
    {
        $errors = $this->validator->errors(['field' => $value], ['field' => $rule]);

        self::assertSame($valid, $errors === [], $valid ? implode(' ', $errors['field'] ?? []) : 'expected an error');
    }

    public function testDatesComparedWithAnotherField(): void
    {
        $rules = ['check_in' => 'required|date|after_or_equal:today', 'check_out' => 'required|after:check_in'];

        self::assertSame([], $this->validator->errors(['check_in' => '2027-02-01', 'check_out' => '2027-02-03'], $rules));
        self::assertSame(
            ['check_out' => ['The check out field must be a date after check in.']],
            $this->validator->errors(['check_in' => '2027-02-01', 'check_out' => '2027-02-01'], $rules),
        );
        self::assertArrayHasKey('check_out', $this->validator->errors(['check_in' => 'soon', 'check_out' => '2027-02-01'], ['check_out' => 'after:check_in']), 'an invalid other field fails');
    }

    public function testDifferent(): void
    {
        $rules = ['new_password' => 'different:current_password'];

        self::assertSame([], $this->validator->errors(['current_password' => 'a', 'new_password' => 'b'], $rules));
        self::assertSame(
            ['new_password' => ['The new password field and current password must be different.']],
            $this->validator->errors(['current_password' => 'a', 'new_password' => 'a'], $rules),
        );
    }

    public function testMessagesNameTheParameters(): void
    {
        self::assertSame(['pin' => ['The pin field must be 6 digits.']], $this->validator->errors(['pin' => '12'], ['pin' => 'digits:6']));
        self::assertSame(['phone' => ['The phone field must be between 10 and 12 digits.']], $this->validator->errors(['phone' => '12'], ['phone' => 'digits_between:10,12']));
        self::assertSame(['site' => ['The site field must end with one of: .in, .com.']], $this->validator->errors(['site' => 'x.org'], ['site' => 'ends_with:.in,.com']));
        self::assertSame(['date' => ['The date field must be a date after today.']], $this->validator->errors(['date' => '2027-01-01'], ['date' => 'after:today']));
        self::assertSame(['pan' => ['The pan field must be 10 characters.']], $this->validator->errors(['pan' => 'ABC'], ['pan' => 'size:10']));
    }

    public function testRequiredIfAndUnless(): void
    {
        $rules = ['type' => 'required|in:person,company', 'gst_number' => 'required_if:type,company|size:15'];

        self::assertSame([], $this->validator->errors(['type' => 'person'], $rules));
        self::assertSame(['gst_number' => ['The gst number field is required.']], $this->validator->errors(['type' => 'company'], $rules));
        self::assertSame(['gst_number' => ['The gst number field is required.']], $this->validator->errors(['type' => 'company', 'gst_number' => '  '], $rules));
        self::assertSame([], $this->validator->errors(['type' => 'company', 'gst_number' => '22AAAAA0000A1Z5'], $rules));

        $unless = ['reason' => 'required_unless:status,approved'];
        self::assertSame([], $this->validator->errors(['status' => 'approved'], $unless));
        self::assertArrayHasKey('reason', $this->validator->errors(['status' => 'rejected'], $unless));
        self::assertArrayHasKey('reason', $this->validator->errors([], $unless), 'a missing field is not "approved"');

        self::assertArrayHasKey('details', $this->validator->errors(['agree' => true], ['details' => 'required_if:agree,1']), 'booleans compare as 1 and 0');
    }

    public function testRequiredWithAndWithout(): void
    {
        $with = ['password_confirmation' => 'required_with:password'];
        self::assertSame([], $this->validator->errors([], $with));
        self::assertSame([], $this->validator->errors(['password' => ''], $with), 'an empty field does not count');
        self::assertArrayHasKey('password_confirmation', $this->validator->errors(['password' => 'secret'], $with));

        $without = ['email' => 'required_without:phone,whatsapp', 'phone' => 'nullable|digits:10', 'whatsapp' => 'nullable|digits:10'];
        self::assertArrayHasKey('email', $this->validator->errors(['phone' => '9876543210'], $without), 'any missing field makes it required');
        self::assertSame([], $this->validator->errors(['phone' => '9876543210', 'whatsapp' => '9876543210'], $without));
        self::assertSame([], $this->validator->errors(['email' => 'a@example.test'], $without));
    }

    public function testConditionalRequiredWorksWithNestedFieldsAndCustomMessages(): void
    {
        $rules = ['address.state' => 'required_if:address.country,IN'];
        $messages = ['address.state.required_if' => 'Choose a state for an Indian address.'];

        self::assertSame(['address.state' => ['Choose a state for an Indian address.']], $this->validator->errors(['address' => ['country' => 'IN']], $rules, $messages));
        self::assertSame([], $this->validator->errors(['address' => ['country' => 'NP']], $rules));
    }

    public function testConditionalRulesValidateTheValueWhenPresent(): void
    {
        $data = $this->validator->validate(['type' => 'company', 'gst_number' => '22AAAAA0000A1Z5'], ['type' => 'required', 'gst_number' => 'required_if:type,company|uppercase']);

        self::assertSame(['type' => 'company', 'gst_number' => '22AAAAA0000A1Z5'], $data);
    }

    public function testBadParametersAreProgrammingErrors(): void
    {
        foreach (['required_if:type', 'digits', 'starts_with', 'after'] as $rule) {
            try {
                $this->validator->errors(['field' => '1', 'type' => 'x'], ['field' => $rule]);
                self::fail('InvalidRuleException expected for ' . $rule);
            } catch (InvalidRuleException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
