<?php

declare(strict_types=1);

namespace VeyroPHP\Console\Make;

use VeyroPHP\Console\Argument;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;

#[AsCommand('make:notification', 'Create a notification class in app/Notifications')]
final class MakeNotificationCommand extends Command
{
    public function __construct(private readonly Generator $generator) {}

    public function definition(): array
    {
        return [Argument::required('name', 'Class name, e.g. OrderShipped')];
    }

    public function execute(Input $input, Output $output): int
    {
        [$namespace, $class, $path] = Generator::target((string) $input->argument('name'), 'App\\Notifications', 'app/Notifications');

        $contents = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use VeyroPHP\\Mail\\Message;
            use VeyroPHP\\Notifications\\Notification;
            use VeyroPHP\\Orm\\Model;

            /**
             * Send with \$notifier->send(\$user, new {$class}(...)).
             * Add "implements \\VeyroPHP\\Queue\\ShouldQueue" to send it from a queue worker.
             */
            final class {$class} extends Notification
            {
                public function __construct()
                {
                }

                public function via(Model \$notifiable): array
                {
                    return ['mail', 'database'];
                }

                public function toMail(Model \$notifiable): Message
                {
                    return (new Message())->subject('{$class}')->text('Write the message here.');
                }

                public function toDatabase(Model \$notifiable): array
                {
                    return ['text' => '{$class}'];
                }
            }

            PHP;

        $output->success('Created ' . $this->generator->write($path, $contents));

        return self::SUCCESS;
    }
}
