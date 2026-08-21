<?php

declare(strict_types=1);

namespace AnyLint;

use AnyLint\Ast\Node;

final class Analyzer
{
    /** @var LanguageProvider[] */
    private array $providers = [];

    /** @var Rule[] */
    private array $rules = [];

    /** @var string[] теки, які ніколи не скануємо */
    private const SKIP_DIRS = ['.git', 'vendor', 'node_modules', 'data'];

    public function withProvider(LanguageProvider $provider): self
    {
        $this->providers[] = $provider;
        return $this;
    }

    public function withRule(Rule $rule): self
    {
        $this->rules[] = $rule;
        return $this;
    }

    /** @return Finding[] */
    public function analyzePath(string $path): array
    {
        if (is_file($path)) {
            return $this->analyzeFile($path);
        }
        if (!is_dir($path)) {
            throw new \RuntimeException("Шлях не існує: {$path}");
        }

        $findings = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            foreach (self::SKIP_DIRS as $skip) {
                if (str_contains($fileInfo->getPathname(), DIRECTORY_SEPARATOR . $skip . DIRECTORY_SEPARATOR)) {
                    continue 2;
                }
            }
            if ($fileInfo->isFile()) {
                $findings = [...$findings, ...$this->analyzeFile($fileInfo->getPathname())];
            }
        }
        return $findings;
    }

    /** @return Finding[] */
    private function analyzeFile(string $filePath): array
    {
        // @ навмисно: file_get_contents() інакше друкує сирий PHP Warning
        // прямо в stderr при недоступному файлі (напр. permission denied),
        // а сам сценарій нижче однаково перетворює це на керовану Finding -
        // подвійне повідомлення про ту саму проблему тільки б заважало.
        $source = @file_get_contents($filePath);
        if ($source === false) {
            $error = error_get_last();
            // Файл, який НЕ вдалось прочитати, - це НЕ "чистий" файл: без
            // цієї Finding --json віддав би "ok": true, приховуючи, що
            // частина коду взагалі не потрапила під аналіз.
            return [new Finding(
                $filePath,
                1,
                'unreadable-file',
                Severity::Error,
                'Не вдалось прочитати файл: ' . ($error['message'] ?? 'невідома причина'),
            )];
        }

        $findings = [];

        // Текстові правила (TodoTrackerRule, HardcodedSecretRule) не
        // потребують AST - працюють навіть без жодного провайдера, що
        // підтримує цей файл. Передаємо порожній Root, щоб не ганяти
        // парсер там, де він нікому не потрібен.
        $textOnlyRoot = new Node('Root', 1);

        $provider = $this->findProvider($filePath);
        $root = $textOnlyRoot;
        if ($provider !== null) {
            try {
                $root = $provider->parse($filePath);
            } catch (\RuntimeException $e) {
                $findings[] = new Finding($filePath, 1, 'parse-error', Severity::Error, $e->getMessage());
                $root = $textOnlyRoot;
            }
        }

        foreach ($this->rules as $rule) {
            $findings = [...$findings, ...$rule->check($root, $source, $filePath)];
        }

        return $findings;
    }

    private function findProvider(string $filePath): ?LanguageProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($filePath)) {
                return $provider;
            }
        }
        return null;
    }
}
