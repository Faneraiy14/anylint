<?php

declare(strict_types=1);

namespace AnyLint\Rules;

use AnyLint\Ast\Node;
use AnyLint\Finding;
use AnyLint\Rule;
use AnyLint\Severity;

/**
 * Текстове правило (як TodoTrackerRule) - той самий принцип, що й у
 * сестринському проєкті secretscan (https://github.com/Faneraiy14/secretscan),
 * але навмисно спрощений і самодостатній набір патернів: тут ідея показати,
 * що навіть "безпекове" правило вписується в той самий Rule-інтерфейс, а
 * не окрема інфраструктура - за повноцінним скануванням секретів варто
 * йти саме в secretscan.
 */
final class HardcodedSecretRule implements Rule
{
    /** @var array<string,string> назва правила => regex */
    private const PATTERNS = [
        'GitHub Token' => '/\bghp_[A-Za-z0-9]{36}\b/',
        'AWS Access Key' => '/\bAKIA[0-9A-Z]{16}\b/',
        'Private Key Block' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        'Generic Secret Assignment' => '/\b(password|secret|api[_-]?key)\b\s*[=:]\s*["\']([^"\']{8,})["\']/i',
    ];

    public function name(): string
    {
        return 'hardcoded-secret';
    }

    public function check(Node $root, string $source, string $filePath): array
    {
        $findings = [];
        $lines = explode("\n", $source);
        foreach ($lines as $i => $line) {
            if (str_contains($line, 'anylint:ignore')) {
                continue;
            }
            foreach (self::PATTERNS as $ruleName => $pattern) {
                if (preg_match($pattern, $line, $m) === 1) {
                    $findings[] = new Finding(
                        $filePath,
                        $i + 1,
                        $this->name(),
                        Severity::Error,
                        "Схоже на секрет у коді ({$ruleName}): " . $this->redact($m[0]),
                    );
                }
            }
        }
        return $findings;
    }

    private function redact(string $value): string
    {
        if (strlen($value) <= 8) {
            return str_repeat('*', strlen($value));
        }
        return substr($value, 0, 4) . str_repeat('*', max(3, strlen($value) - 8)) . substr($value, -4);
    }
}
