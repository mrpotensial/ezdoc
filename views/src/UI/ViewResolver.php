<?php

declare(strict_types=1);

namespace Ezdoc\UI;

use Ezdoc\Exceptions\NotFoundException;
use Ezdoc\Exceptions\ValidationException;

/**
 * Ezdoc\UI\ViewResolver — chain-of-responsibility view file lookup.
 *
 * Search a list of directories (highest priority first) for a view file.
 * Consumers add their own theme/override directory at the top of the chain
 * so their file wins over the library default.
 *
 * View name format: 'designer' -> resolves to 'designer.php' (or
 * 'designer.blade.php' if present, ready for future Blade support).
 * Subfolder OK: 'designer/toolbar' -> 'designer/toolbar.php'.
 *
 * Names are sanitized to `[a-zA-Z0-9_/-]+` — no dots, no backslashes, no
 * path traversal.
 *
 * PHP 7.4+ compatible.
 */
final class ViewResolver
{
    /** @var array<int,string> Absolute paths, highest priority first. */
    private $searchPaths;

    /** @var array<int,string> Filename suffixes to probe, in order. */
    private $extensions;

    /**
     * @param array<int,string> $searchPaths Highest priority first.
     */
    public function __construct(array $searchPaths = [])
    {
        $this->searchPaths = [];
        foreach ($searchPaths as $p) {
            $this->addPath((string) $p, false);
        }
        // Order matters: '.blade.php' tried first so future Blade
        // installations shadow plain PHP transparently.
        $this->extensions = ['.blade.php', '.php'];
    }

    /**
     * Add a directory to the search chain.
     *
     * @param bool $prepend true (default) = higher priority than existing.
     */
    public function addPath(string $path, bool $prepend = true): void
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');
        if ($normalized === '') {
            return;
        }
        // De-dup: if already present, remove old copy so re-add reorders.
        $existing = array_search($normalized, $this->searchPaths, true);
        if ($existing !== false) {
            array_splice($this->searchPaths, (int) $existing, 1);
        }
        if ($prepend) {
            array_unshift($this->searchPaths, $normalized);
        } else {
            $this->searchPaths[] = $normalized;
        }
    }

    /**
     * Resolve a view name to an absolute file path.
     *
     * @throws NotFoundException if no matching file exists in any path.
     * @throws ValidationException if $viewName contains illegal characters.
     */
    public function resolve(string $viewName): string
    {
        $safe = $this->sanitize($viewName);
        foreach ($this->searchPaths as $dir) {
            foreach ($this->extensions as $ext) {
                $candidate = $dir . '/' . $safe . $ext;
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }
        throw NotFoundException::forResource('view', $viewName);
    }

    /**
     * Check if a view can be resolved without throwing.
     */
    public function exists(string $viewName): bool
    {
        try {
            $safe = $this->sanitize($viewName);
        } catch (ValidationException $e) {
            return false;
        }
        foreach ($this->searchPaths as $dir) {
            foreach ($this->extensions as $ext) {
                if (is_file($dir . '/' . $safe . $ext)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @return array<int,string>
     */
    public function getSearchPaths(): array
    {
        return $this->searchPaths;
    }

    /**
     * Render a view file with extracted $data and return captured output.
     *
     * Note: only plain-PHP views can be rendered here. If the resolved path
     * is a `.blade.php` file this method throws — Blade rendering is out of
     * scope; wire your Blade engine and call {@see resolve()} yourself.
     *
     * @param array<string,mixed> $data
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function render(string $viewName, array $data = []): string
    {
        $path = $this->resolve($viewName);

        if (substr($path, -10) === '.blade.php') {
            throw new ValidationException(
                "ViewResolver::render() cannot execute Blade template '{$viewName}'. "
                . 'Resolve the path and delegate to a Blade engine.'
            );
        }

        // Prevent variables from leaking to the view scope by name-collision
        // with $path / $data / $viewName / this / __* — use EXTR_SKIP.
        // phpcs:ignore
        extract($data, EXTR_SKIP);

        ob_start();
        try {
            /** @psalm-suppress UnresolvableInclude */
            include $path;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        $out = ob_get_clean();
        return $out === false ? '' : $out;
    }

    /**
     * Sanitize a view name.
     *
     * @throws ValidationException on illegal characters or empty input.
     */
    private function sanitize(string $viewName): string
    {
        $trimmed = trim($viewName);
        if ($trimmed === '') {
            throw new ValidationException('View name cannot be empty.');
        }
        if (!preg_match('#^[a-zA-Z0-9_/\\-]+$#', $trimmed)) {
            throw new ValidationException(
                "Invalid view name '{$viewName}'. Allowed: [a-zA-Z0-9_/-]."
            );
        }
        // Belt-and-braces: reject any '..' segment even though the regex
        // already forbids '.'.
        if (strpos($trimmed, '..') !== false) {
            throw new ValidationException("Illegal view name '{$viewName}'.");
        }
        return $trimmed;
    }
}
