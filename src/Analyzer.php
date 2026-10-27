<?php

declare(strict_types=1);

namespace AnyLint;

use AnyLint\Ast\Node;
use AnyLint\Metrics\Complexity;

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

    /** Скільки процесів аналізують файли паралельно (див. ParallelRunner); 1 - послідовно. */
    private int $jobs = 1;

    /** Файл кешу результатів (див. ResultCache); null - без кешу. */
    private ?string $cacheFile = null;

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
     * .claude - git worktree-и Claude Code (.claude/worktrees/<назва>/):
     * повні КОПІЇ того самого проєкту на інших гілках, тож кожна знахідка
     * дублювалась би за кількістю worktree-ів (на obriy - копії app.js по
     * ~200 КБ з .claude/worktrees/*).
     */
    private const SKIP_DIRS = ['.git', '.claude', 'vendor', 'node_modules', 'data', 'dist', 'build', 'target', 'out', 'var'];

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

    public function withJobs(int $jobs): self
    {
        $this->jobs = max(1, $jobs);
        return $this;
    }

    public function withCache(?string $cacheFile): self
    {
        $this->cacheFile = $cacheFile;
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
        return $this->analyze($path)->findings;
    }

    /**
     * Те саме, що analyzePath(), але з усім, що дізнались про кожен файл
     * (мова, рядки, метрики функцій) - для "anylint report".
     *
     * @throws \RuntimeException
     */
    public function analyze(string $path): AnalysisResult
    {
        $this->applyConfig();

        if (is_file($path)) {
            return $this->analyzeFiles($this->isExcluded($path) ? [] : [$path]);
        }
        if (!is_dir($path)) {
            throw new \RuntimeException("Шлях не існує: {$path}");
        }

        $files = [];
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
                $files[] = $fileInfo->getPathname();
            }
        }

        // Порядок обходу RecursiveDirectoryIterator - це порядок записів у
        // теці файлової системи, і він змінюється навіть від перезапису
        // файлу. Без сортування той самий код давав би знахідки в різному
        // порядку від запуску до запуску (стрибаючі diff-и SARIF/звітів).
        sort($files, SORT_STRING);

        return $this->analyzeFiles($files);
    }

    /** @param list<string> $files */
    private function analyzeFiles(array $files): AnalysisResult
    {
        $cache = $this->cacheFile !== null ? ResultCache::open($this->cacheFile, $this->fingerprint()) : null;

        /** @var array<int, FileResult> $results */
        $results = [];
        /** @var array<int, string> $keys індекс файлу => ключ кешу */
        $keys = [];
        $misses = [];
        foreach ($files as $index => $file) {
            $source = $cache === null ? false : @file_get_contents($file);
            if ($cache !== null && $source !== false) {
                $keys[$index] = ResultCache::key($file, $source);
                $hit = $cache->get($keys[$index]);
                if ($hit !== null) {
                    $results[$index] = $hit;
                    continue;
                }
            }
            $misses[$index] = $file;
        }

        $analyze = fn (string $file): FileResult => $this->analyzeFile($file);
        $missFiles = array_values($misses);
        $missResults = ParallelRunner::run($missFiles, $this->jobs, $analyze) ?? array_map($analyze, $missFiles);
        foreach (array_keys($misses) as $i => $index) {
            $result = $missResults[$i] ?? new FileResult($misses[$index], []);
            $results[$index] = $result;
            if ($cache !== null && isset($keys[$index]) && $this->isCacheable($result->findings)) {
                $cache->put($keys[$index], $result);
            }
        }
        $cache?->save();
        ksort($results);
        $results = array_values($results);

        $results = $this->finishProjectRules($results);

        // array_merge одним викликом, а не [...$findings, ...$new] у циклі -
        // те копіювало весь накопичений масив на кожному файлі (O(n^2)).
        $findings = $results === [] ? [] : array_merge(...array_map(static fn (FileResult $r) => $r->findings, $results));
        return new AnalysisResult($results, $findings);
    }

    /**
     * Другий крок ProjectRule: факти всіх файлів -> знахідки, які далі
     * проходять ті самі директиви й severity, що й знахідки звичайних
     * правил, і додаються до свого файлу (тож порядок виводу - за файлами).
     *
     * @param list<FileResult> $results
     * @return list<FileResult>
     */
    private function finishProjectRules(array $results): array
    {
        $byFile = [];
        foreach ($this->activeRules as $rule) {
            if (!$rule instanceof ProjectRule) {
                continue;
            }
            $facts = [];
            foreach ($results as $result) {
                if (array_key_exists($rule->name(), $result->facts)) {
                    $facts[$result->file] = $result->facts[$rule->name()];
                }
            }
            foreach ($rule->finish($facts) as $finding) {
                $byFile[$finding->file][] = $finding;
            }
        }
        if ($byFile === []) {
            return $results;
        }

        foreach ($results as $i => $result) {
            if (!isset($byFile[$result->file])) {
                continue;
            }
            $source = @file_get_contents($result->file);
            $extra = $this->postProcess($byFile[$result->file], $source === false ? null : $source);
            $results[$i] = $result->withFindings([...$result->findings, ...$extra]);
        }
        return $results;
    }

    /**
     * parse-error/unreadable-file НЕ кешуються: вони можуть означати не
     * "файл зламаний", а "node/nx не встановлено" чи "немає прав" - після
     * виправлення оточення кеш віддавав би стару помилку для незміненого
     * файлу.
     *
     * @param Finding[] $findings
     */
    private function isCacheable(array $findings): bool
    {
        foreach ($findings as $finding) {
            if (in_array($finding->rule, self::BUILTIN_RULES, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Усе, від чого залежить результат, окрім вмісту самого файлу: код
     * anylint, які правила й провайдери активні, їхні опції, severity з
     * конфігу, версії зовнішніх інструментів.
     */
    private function fingerprint(): string
    {
        $parts = [ResultCache::codeFingerprint(), PHP_VERSION];
        foreach ($this->activeRules as $rule) {
            $parts[] = $rule::class . ':' . $rule->name();
        }
        foreach ($this->providers as $provider) {
            $parts[] = $provider::class . ($provider instanceof ExternalToolProvider ? ':' . $provider->toolFingerprint() : '');
        }
        $parts[] = serialize([$this->config?->severities, $this->config?->options]);
        return sha1(implode("\n", $parts));
    }

    private function analyzeFile(string $filePath): FileResult
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
            return new FileResult($filePath, $this->postProcess([new Finding(
                $filePath,
                1,
                'unreadable-file',
                Severity::Error,
                'Не вдалось прочитати файл: ' . ($error['message'] ?? 'невідома причина'),
            )], null));
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

        $language = $provider?->name();
        $facts = [];
        foreach ($this->activeRules as $rule) {
            $findings = [...$findings, ...$rule->check($root, $source, $filePath)];
            if ($rule instanceof ProjectRule) {
                $collected = $rule->collect($root, $source, $filePath, $root === $textOnlyRoot ? null : $language);
                if ($collected !== null) {
                    $facts[$rule->name()] = $collected;
                }
            }
        }

        return new FileResult(
            $filePath,
            $this->postProcess($findings, $source),
            $language,
            Complexity::linesOfCode($source),
            Complexity::functions($root),
            $facts,
        );
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
