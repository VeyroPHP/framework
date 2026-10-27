<?php

declare(strict_types=1);

namespace VeyroPHP\Console\Make;

use VeyroPHP\Console\Argument;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;

#[AsCommand('make:mail', 'Create a mail class in app/Mail and its view in resources/views/mail')]
final class MakeMailCommand extends Command
{
    public function __construct(private readonly Generator $generator) {}

    public function definition(): array
    {
        return [Argument::required('name', 'Class name, e.g. WelcomeMail')];
    }

    public function execute(Input $input, Output $output): int
    {
        [$namespace, $class, $classPath, $subPath] = Generator::target((string) $input->argument('name'), 'App\\Mail', 'app/Mail');

        $view = 'mail.' . strtolower(str_replace('/', '.', $subPath)) . self::snake($class);

        $contents = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use VeyroPHP\\Mail\\Mailable;
            use VeyroPHP\\Mail\\Message;

            /**
             * Send with \$mailer->send(new {$class}(...)).
             */
            final class {$class} extends Mailable
            {
                public function __construct(private readonly string \$email)
                {
                }

                public function build(Message \$message): void
                {
                    \$message->to(\$this->email)
                        ->subject('{$class}')
                        ->view('{$view}', ['email' => \$this->email]);
                }
            }

            PHP;

        $template = <<<'PHP'
            <?php
            /**
             * @var Closure(mixed): string $e
             * @var string $email
             */
            ?>
            <!doctype html>
            <html>
            <body style="font-family: Arial, sans-serif; line-height: 1.5; color: #222;">
                <p>Hello <?= $e($email) ?>,</p>
                <p>Write your message here.</p>
            </body>
            </html>

            PHP;

        $viewPath = 'resources/views/' . str_replace('.', '/', $view) . '.php';

        foreach ([$classPath, $viewPath] as $path) {
            if (file_exists($this->generator->path($path))) {
                $output->error($path . ' already exists; nothing was created.');

                return self::FAILURE;
            }
        }

        $output->success('Created ' . $this->generator->write($classPath, $contents));
        $output->success('Created ' . $this->generator->write($viewPath, $template));

        return self::SUCCESS;
    }

    private static function snake(string $class): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $class));
    }
}
