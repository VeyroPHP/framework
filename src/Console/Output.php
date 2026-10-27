<?php

declare(strict_types=1);

namespace VeyroPHP\Console;

/**
 * Writes to standard output and standard error.
 *
 * Colors are used only when the stream is a terminal and NO_COLOR is not set,
 * so piped output and logs stay plain text. Text is written as given: escape
 * sequences in user data are not interpreted by this class, but a terminal may
 * still interpret them, so commands should not print untrusted raw bytes.
 */
final class Output
{
    private const COLORS = ['info' => '36', 'success' => '32', 'warning' => '33', 'error' => '31', 'muted' => '90', 'bold' => '1'];

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct($stdout = null, $stderr = null, private readonly bool $decorated = false)
    {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
    }

    /**
     * Output on the process streams, with colors when they are terminals.
     */
    public static function stdio(): self
    {
        $noColor = getenv('NO_COLOR');
        $decorated = ($noColor === false || $noColor === '')
            && stream_isatty(STDOUT)
            && (DIRECTORY_SEPARATOR === '/' || (function_exists('sapi_windows_vt100_support') && @sapi_windows_vt100_support(STDOUT, true)));

        return new self(STDOUT, STDERR, $decorated);
    }

    public function line(string $text = ''): void
    {
        fwrite($this->stdout, $text . PHP_EOL);
    }

    /**
     * Writes text without a line break, e.g. for streamed output.
     */
    public function write(string $text): void
    {
        fwrite($this->stdout, $text);
        fflush($this->stdout);
    }

    public function info(string $text): void
    {
        $this->line($this->style($text, 'info'));
    }

    public function success(string $text): void
    {
        $this->line($this->style($text, 'success'));
    }

    public function warning(string $text): void
    {
        fwrite($this->stderr, $this->style($text, 'warning') . PHP_EOL);
    }

    public function error(string $text): void
    {
        fwrite($this->stderr, $this->style($text, 'error') . PHP_EOL);
    }

    /**
     * Wraps text in a color when output is decorated: info, success, warning, error, muted, bold.
     */
    public function style(string $text, string $style): string
    {
        $code = self::COLORS[$style] ?? throw new ConsoleException(sprintf('Unknown style "%s".', $style));

        return $this->decorated ? "\033[" . $code . 'm' . $text . "\033[0m" : $text;
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    public function table(array $headers, array $rows): void
    {
        $widths = array_map(mb_strlen(...), $headers);

        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen($cell));
            }
        }

        $this->line($this->style(self::row($headers, $widths), 'bold'));
        foreach ($rows as $row) {
            $this->line(self::row($row, $widths));
        }
    }

    /**
     * @param list<string> $cells
     * @param array<int, int> $widths
     */
    private static function row(array $cells, array $widths): string
    {
        $padded = [];
        foreach ($widths as $i => $width) {
            $cell = $cells[$i] ?? '';
            $padded[] = $cell . str_repeat(' ', max(0, $width - mb_strlen($cell)));
        }

        return rtrim(implode('  ', $padded));
    }
}
