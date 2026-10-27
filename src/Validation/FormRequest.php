<?php

declare(strict_types=1);

namespace VeyroPHP\Validation;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use VeyroPHP\Http\Exception\HttpException;
use VeyroPHP\Http\FromRequest;

/**
 * A request's input rules in one class, checked before the handler runs:
 *
 *     final class StoreEnquiry extends FormRequest
 *     {
 *         public function rules(): array
 *         {
 *             return ['name' => 'required|string|max:100', 'phone' => 'required|digits:10'];
 *         }
 *     }
 *
 *     $router->post('/enquiries', function (StoreEnquiry $form) {
 *         $data = $form->validated();   // only the fields with rules
 *     });
 *
 * - The handler receives it already authorized and validated. authorize() false gives
 *   403; invalid input throws ValidationException (422 for JSON clients, or back to the
 *   form with RedirectBackOnValidationError).
 * - Input is the query string for GET and HEAD, otherwise the parsed body with the
 *   uploaded files.
 * - The class is created by the container, so its constructor can take services
 *   (a Gate for authorize(), a Connection for Unique rules).
 */
abstract class FormRequest implements FromRequest
{
    private ?ServerRequestInterface $request = null;

    /** @var array<string, mixed> */
    private array $validated = [];

    /**
     * @return array<string, string|list<string|Rule>>
     */
    abstract public function rules(): array;

    /**
     * Custom messages: "field.rule" or "rule" => text.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [];
    }

    /**
     * False stops the request with 403 before validation. The request is available
     * through request().
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Changes the input before validation, e.g. trims or lower-cases an email.
     *
     * @param array<array-key, mixed> $input
     *
     * @return array<array-key, mixed>
     */
    protected function prepare(array $input): array
    {
        return $input;
    }

    /**
     * @throws HttpException 403 when authorize() returns false
     * @throws ValidationException
     */
    final public static function fromRequest(ServerRequestInterface $request, ContainerInterface $container): static
    {
        $form = $container->get(static::class);

        if (!$form instanceof static) {
            throw new \LogicException(sprintf('The container did not create %s.', static::class));
        }

        $form->request = $request;

        if (!$form->authorize()) {
            throw new HttpException(403, 'You do not have permission to do this.');
        }

        $validator = $container->has(Validator::class) ? $container->get(Validator::class) : null;
        $validator = $validator instanceof Validator ? $validator : new Validator();
        $form->validated = $validator->validate($form->prepare(self::input($request)), $form->rules(), $form->messages());

        return $form;
    }

    /**
     * The validated fields (see Validator::validate()).
     *
     * @return array<string, mixed>
     */
    final public function validated(): array
    {
        return $this->validated;
    }

    /**
     * One validated field ("address.city" for nested ones), or $default.
     */
    final public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->validated) ? $this->validated[$key] : $default;
    }

    /**
     * @throws \LogicException before the form was filled from a request
     */
    final public function request(): ServerRequestInterface
    {
        return $this->request ?? throw new \LogicException(static::class . ' has no request yet; let the handler receive it as a parameter.');
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function input(ServerRequestInterface $request): array
    {
        if (in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $request->getQueryParams();
        }

        $body = $request->getParsedBody();

        return array_replace_recursive(is_array($body) ? $body : [], $request->getUploadedFiles());
    }
}
