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

    /**
     * Правила після застосування Config (вимкнені прибрано, опції
     * застосовано) - перебудовується на початку кожного analyzePath(),
     * тож порядок withRule()/withConfig() не має значення.
     *
     * @var Rule[]
     */
    private array $activeRules = [];

    private ?Config $config = null;

    private ?PathMatcher $excluded = null;

    /**
     * Знахідки, які створює сам Analyzer, а не якесь правило - їх теж
     * можна вимкнути/перевизначити в конфігу чи директивою (напр.
     * parse-error на файлах, для яких не встановлено nx/node).
     */
    private const BUILTIN_RULES = ['parse-error', 'unreadable-file'];

    /**
     * @var string[] теки, які ніколи не скануємо. dist/build - виявлено
     * емпірично: прогін на fl-launcher-code без цього затопив звіт
     * тисячами "знахідок" з ВБУДОВАНОГО в Electron-білд рантайму Node.js
     * (dist/linux-unpacked/...) - TODO-коментарі самих розробників Node,
     * не користувача. target/out - той самий клас проблеми для
     * Rust/Java-подібних збірок. var - Symfony-кеш (var/cache): згенеровані
     * файли перекладів з рядками на кшталт "password"/"passphrase" як
     * значеннями словника, а не реальними секретами - виявлено на
     * OpenSourceBikeShare (109 хибних hardcoded-secret знахідок).
     */
    private const SKIP_DIRS = ['.git', 'vendor', 'node_modules', 'data', 'dist', 'build', 'target', 'out', 'var'];

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

    public function withConfig(Config $config): self
    {
        $this->config = $config;
        return $this;
    }

    /**
     * @return Finding[]
     * @throws \RuntimeException шлях не існує або конфіг посилається на
     *         невідоме правило / містить неприйнятні опції
     */
    public function analyzePath(string $path): array
    {
        $this->applyConfig();

        if (is_file($path)) {
            return $this->isExcluded($path) ? [] : $this->analyzeFile($path);
        }
        if (!is_dir($path)) {
            throw new \RuntimeException("Шлях не існує: {$path}");
        }

        $findings = [];
        /** @var iterable<\SplFileInfo> $iterator */
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $fileInfo) {
            foreach (self::SKIP_DIRS as $skip) {
                if (str_contains($fileInfo->getPathname(), DIRECTORY_SEPARATOR . $skip . DIRECTORY_SEPARATOR)) {
                    continue 2;
                }
            }
            if ($fileInfo->isFile() && !$this->isExcluded($fileInfo->getPathname())) {
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
            return $this->postProcess([new Finding(
                $filePath,
                1,
                'unreadable-file',
                Severity::Error,
                'Не вдалось прочитати файл: ' . ($error['message'] ?? 'невідома причина'),
            )], null);
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

        foreach ($this->activeRules as $rule) {
            $findings = [...$findings, ...$rule->check($root, $source, $filePath)];
        }

        return $this->postProcess($findings, $source);
    }

    /**
     * Директиви приглушення і severity з конфігу застосовуються ТУТ,
     * централізовано, а не в кожному правилі - нове правило автоматично
     * їх підтримує, нічого про них не знаючи.
     *
     * @param Finding[] $findings
     * @return Finding[]
     */
    private function postProcess(array $findings, ?string $source): array
    {
        $suppressions = $source === null ? null : Suppressions::parse($source);
        $result = [];
        foreach ($findings as $finding) {
            if ($suppressions?->isSuppressed($finding)) {
                continue;
            }
            if ($this->config !== null && array_key_exists($finding->rule, $this->config->severities)) {
                $severity = $this->config->severities[$finding->rule];
                if ($severity === null) {
                    continue;
                }
                $finding = $finding->withSeverity($severity);
            }
            $result[] = $finding;
        }
        return $result;
    }

    private function applyConfig(): void
    {
        $config = $this->config;
        if ($config === null) {
            $this->activeRules = $this->rules;
            $this->excluded = null;
            return;
        }

        $known = [...self::BUILTIN_RULES, ...array_map(static fn (Rule $r) => $r->name(), $this->rules)];
        foreach ([...array_keys($config->severities), ...array_keys($config->options)] as $name) {
            if (!in_array($name, $known, true)) {
                throw new \RuntimeException(
                    "{$config->source}: невідоме правило '{$name}'. Відомі: " . implode(', ', $known) . '.',
                );
            }
        }

        $active = [];
        foreach ($this->rules as $rule) {
            $name = $rule->name();
            if ($config->isDisabled($name)) {
                continue;
            }
            $options = $config->options[$name] ?? [];
            if ($options !== []) {
                if (!$rule instanceof ConfigurableRule) {
                    throw new \RuntimeException("{$config->source}: правило '{$name}' не має опцій (лише severity).");
                }
                try {
                    $rule = $rule->withOptions($options);
                } catch (\InvalidArgumentException $e) {
                    throw new \RuntimeException("{$config->source}: rules.{$name}: {$e->getMessage()}");
                }
            }
            $active[] = $rule;
        }
        $this->activeRules = $active;
        $this->excluded = new PathMatcher($config->baseDir, $config->exclude);
    }

    private function isExcluded(string $filePath): bool
    {
        return $this->excluded !== null && $this->excluded->matches($filePath);
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
