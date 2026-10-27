<?php

declare(strict_types=1);

namespace VeyroPHP\Console\Make;

use VeyroPHP\Console\Argument;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;

#[AsCommand('make:request', 'Create a form request (validation rules for one route) in app/Http/Requests')]
final class MakeRequestCommand extends Command
{
    public function __construct(private readonly Generator $generator) {}

    public function definition(): array
    {
        return [Argument::required('name', 'Class name, e.g. StoreEnquiry')];
    }

    public function execute(Input $input, Output $output): int
    {
        [$namespace, $class, $path] = Generator::target((string) $input->argument('name'), 'App\\Http\\Requests', 'app/Http/Requests');

        $contents = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use VeyroPHP\\Validation\\FormRequest;

            /**
             * Take it as a handler parameter: function ({$class} \$form) { \$form->validated(); }
             * The handler only runs when authorize() is true (else 403) and the input is valid
             * (else 422, or back to the form with RedirectBackOnValidationError).
             */
            final class {$class} extends FormRequest
            {
                public function rules(): array
                {
                    return [
                        'name' => 'required|string|max:100',
                    ];
                }

                public function authorize(): bool
                {
                    return true;
                }
            }

            PHP;

        $output->success('Created ' . $this->generator->write($path, $contents));

        return self::SUCCESS;
    }
}
