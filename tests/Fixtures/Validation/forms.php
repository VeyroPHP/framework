<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Fixtures\Validation;

use VeyroPHP\Validation\FormRequest;

final class EnquiryLog
{
    /** @var list<string> */
    public array $authorized = [];
}

final class StoreEnquiry extends FormRequest
{
    public function __construct(private readonly EnquiryLog $log) {}

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'email' => 'required|email',
            'phone' => 'required|digits:10',
            'type' => 'required|in:person,company',
            'company' => 'required_if:type,company|string',
        ];
    }

    public function messages(): array
    {
        return ['phone.digits' => 'Enter a 10-digit mobile number.'];
    }

    public function authorize(): bool
    {
        $this->log->authorized[] = $this->request()->getMethod();

        return $this->request()->getHeaderLine('X-Blocked') === '';
    }

    protected function prepare(array $input): array
    {
        if (is_string($input['email'] ?? null)) {
            $input['email'] = strtolower(trim($input['email']));
        }

        return $input;
    }
}

final class SearchJobs extends FormRequest
{
    public function rules(): array
    {
        return ['q' => 'required|string|max:50', 'page' => 'nullable|integer|min:1'];
    }
}

final class UploadResume extends FormRequest
{
    public function rules(): array
    {
        return ['name' => 'required|string', 'resume' => 'required|file|max_size:100'];
    }
}
