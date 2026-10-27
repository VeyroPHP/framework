<?php

declare(strict_types=1);

namespace VeyroPHP\Port;

/**
 * Chooses the analyzer for a project (or uses the one asked for) and runs it.
 */
final class PortAnalyzer
{
    /** @var array<string, Analyzer> */
    private array $analyzers = [];

    /**
     * @param list<Analyzer>|null $analyzers
     */
    public function __construct(?array $analyzers = null)
    {
        foreach ($analyzers ?? [new LaravelAnalyzer(), new CodeIgniterAnalyzer()] as $analyzer) {
            $this->analyzers[$analyzer->name()] = $analyzer;
        }
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->analyzers);
    }

    public function analyze(string $path, string $from = 'auto'): Report
    {
        $tree = new SourceTree($path);

        if ($from !== 'auto') {
            $analyzer = $this->analyzers[$from] ?? throw new PortException("Unknown framework \"{$from}\": use " . implode(', ', $this->names()) . ' or auto.');

            if (!$analyzer->detects($tree)) {
                throw new PortException("{$tree->root} does not look like a {$from} project.");
            }

            return $analyzer->analyze($tree);
        }

        foreach ($this->analyzers as $analyzer) {
            if ($analyzer->detects($tree)) {
                return $analyzer->analyze($tree);
            }
        }

        throw new PortException("Could not recognize the framework of {$tree->root}; pass --from=" . implode('|', $this->names()) . '.');
    }
}
