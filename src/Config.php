<?php

declare(strict_types=1);

namespace AnyLint;

/**
 * Налаштування проєкту з anylint.json (або .anylint.json). JSON, а не
 * YAML, свідомо: YAML потребував би symfony/yaml, а composer.lock, зібраний
 * на свіжому PHP, легко підтягнув би версію, несумісну з мінімальним 8.1
 * з CI-матриці. Формат:
 *
 *   {
 *     "rules": {
 *       "todo-tracker": "off",
 *       "empty-catch": "error",
 *       "deep-nesting": { "severity": "warning", "maxDepth": 3 }
 *     },
 *     "exclude": ["generated", "legacy/**\/*.php"]
 *   }
 *
 * Значення правила - або рядок severity ("off"/"info"/"warning"/"error"),
 * або об'єкт: необов'язковий "severity" плюс опції, які розуміє саме це
 * правило (ConfigurableRule). Невідомі ключі - помилка, а не мовчазне
 * ігнорування: друкарська помилка в назві правила інакше виглядала б як
 * "налаштування застосовано", хоча нічого не змінилось.
 */
final class Config
{
    public const FILE_NAMES = ['anylint.json', '.anylint.json'];

    private const SEVERITY_OFF = 'off';

    /**
     * @param array<string, Severity|null> $severities null = правило вимкнено ("off")
     * @param array<string, array<string, mixed>> $options опції правил (без ключа "severity")
     * @param list<string> $exclude glob-шаблони відносно $baseDir
     */
    public function __construct(
        public readonly string $baseDir,
        public readonly array $severities = [],
        public readonly array $options = [],
        public readonly array $exclude = [],
        public readonly string $source = '(вбудований)',
    ) {
    }

    /**
     * Шукає anylint.json від теки аналізованого шляху вгору до кореня ФС -
     * так само, як ESLint/tsconfig: запуск з будь-якої підтеки проєкту
     * бере той самий конфіг, що й запуск з кореня.
     */
    public static function discover(string $path): ?self
    {
        $dir = realpath(is_dir($path) ? $path : dirname($path));
        while ($dir !== false) {
            foreach (self::FILE_NAMES as $name) {
                $candidate = $dir . DIRECTORY_SEPARATOR . $name;
                if (is_file($candidate)) {
                    return self::fromFile($candidate);
                }
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
        return null;
    }

    /** @throws \RuntimeException якщо файл не читається чи має невалідний вміст */
    public static function fromFile(string $file): self
    {
        $json = @file_get_contents($file);
        if ($json === false) {
            throw new \RuntimeException("Не вдалось прочитати конфіг: {$file}");
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("{$file}: невалідний JSON - {$e->getMessage()}");
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new \RuntimeException("{$file}: верхній рівень має бути JSON-об'єктом.");
        }
        $baseDir = realpath(dirname($file));
        return self::fromArray($data, $baseDir === false ? dirname($file) : $baseDir, $file);
    }

    /**
     * @param array<mixed> $data
     * @throws \RuntimeException
     */
    public static function fromArray(array $data, string $baseDir, string $source): self
    {
        foreach (array_keys($data) as $key) {
            // "$schema" - для майбутньої JSON Schema в редакторах, не помилка.
            if (!in_array($key, ['rules', 'exclude', '$schema'], true)) {
                throw new \RuntimeException("{$source}: невідомий ключ '{$key}' (дозволено: rules, exclude).");
            }
        }

        $severities = [];
        $options = [];
        $rules = $data['rules'] ?? [];
        if (!is_array($rules) || ($rules !== [] && array_is_list($rules))) {
            throw new \RuntimeException("{$source}: 'rules' має бути об'єктом {\"назва-правила\": налаштування}.");
        }
        foreach ($rules as $rule => $setting) {
            $rule = (string) $rule;
            if (is_string($setting)) {
                $severities[$rule] = self::parseSeverity($setting, "{$source}: rules.{$rule}");
                continue;
            }
            if (!is_array($setting) || ($setting !== [] && array_is_list($setting))) {
                throw new \RuntimeException(
                    "{$source}: rules.{$rule} має бути рядком severity або об'єктом з опціями.",
                );
            }
            if (array_key_exists('severity', $setting)) {
                if (!is_string($setting['severity'])) {
                    throw new \RuntimeException("{$source}: rules.{$rule}.severity має бути рядком.");
                }
                $severities[$rule] = self::parseSeverity($setting['severity'], "{$source}: rules.{$rule}.severity");
                unset($setting['severity']);
            }
            if ($setting !== []) {
                $ruleOptions = [];
                foreach ($setting as $optionName => $optionValue) {
                    $ruleOptions[(string) $optionName] = $optionValue;
                }
                $options[$rule] = $ruleOptions;
            }
        }

        $exclude = $data['exclude'] ?? [];
        if (!is_array($exclude) || !array_is_list($exclude)) {
            throw new \RuntimeException("{$source}: 'exclude' має бути масивом glob-шаблонів.");
        }
        $patterns = [];
        foreach ($exclude as $pattern) {
            if (!is_string($pattern) || trim($pattern) === '') {
                throw new \RuntimeException("{$source}: кожен елемент 'exclude' має бути непорожнім рядком.");
            }
            $patterns[] = $pattern;
        }

        return new self($baseDir, $severities, $options, $patterns, $source);
    }

    public function isDisabled(string $rule): bool
    {
        return array_key_exists($rule, $this->severities) && $this->severities[$rule] === null;
    }

    private static function parseSeverity(string $value, string $where): ?Severity
    {
        if ($value === self::SEVERITY_OFF) {
            return null;
        }
        $severity = Severity::tryFrom($value);
        if ($severity === null) {
            throw new \RuntimeException("{$where}: '{$value}' - очікується off, info, warning або error.");
        }
        return $severity;
    }
}
