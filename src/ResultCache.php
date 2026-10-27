<?php

declare(strict_types=1);

namespace AnyLint;

/**
 * Кеш знахідок між запусками: незмінений файл не розбирається вдруге.
 *
 * Ключ запису - шлях + sha1 вмісту файлу. Увесь кеш додатково
 * прив'язаний до відбитка (fingerprint) - коду самого anylint (src/,
 * dump.js, lock-файли граматик і парсерів), набору правил з опціями й
 * severity, версій зовнішніх інструментів. Зміна будь-чого з цього скидає
 * кеш повністю, а не лише окремі записи: правило могло змінитись так, що
 * старі знахідки вже неправильні для ВСІХ файлів.
 *
 * Лежить у системній тимчасовій теці, окремо для кожного кореня аналізу
 * (як у PHPStan), а не в самому проєкті - кеш не з'являється в git status
 * і не потребує запису в .gitignore.
 */
final class ResultCache
{
    private const FORMAT = 1;

    /** @var array<string, Finding[]> */
    private array $entries = [];

    /** @var array<string, Finding[]> записи, потрібні в ЦЬОМУ прогоні - лише вони й зберігаються */
    private array $used = [];

    private bool $dirty = false;

    private function __construct(private readonly string $file, private readonly string $fingerprint)
    {
    }

    public static function defaultFile(string $root): string
    {
        $real = realpath($root);
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'anylint-cache' . DIRECTORY_SEPARATOR
            . sha1($real === false ? $root : $real) . '.cache';
    }

    public static function open(string $file, string $fingerprint): self
    {
        $cache = new self($file, $fingerprint);
        $payload = @file_get_contents($file);
        if ($payload === false) {
            return $cache;
        }
        $data = @unserialize($payload, ['allowed_classes' => [Finding::class, Severity::class]]);
        // Інший формат чи відбиток - мовчки починаємо з порожнього кешу:
        // зіпсований/застарілий кеш ніколи не має бути причиною помилки.
        if (
            !is_array($data)
            || ($data['format'] ?? null) !== self::FORMAT
            || ($data['fingerprint'] ?? null) !== $fingerprint
            || !is_array($data['entries'] ?? null)
        ) {
            return $cache;
        }
        foreach ($data['entries'] as $key => $findings) {
            if (is_string($key) && is_array($findings)) {
                $cache->entries[$key] = array_values(array_filter($findings, static fn ($f) => $f instanceof Finding));
            }
        }
        return $cache;
    }

    /**
     * Відбиток коду anylint: вміст усіх PHP-файлів src/, обох dump.js і
     * lock-файлів (версії typescript/tree-sitter/граматик/php-parser).
     */
    public static function codeFingerprint(): string
    {
        $root = dirname(__DIR__);
        $files = [
            $root . '/composer.lock',
            $root . '/tools/js-ast-dump/dump.js',
            $root . '/tools/js-ast-dump/package-lock.json',
            $root . '/tools/treesitter-ast-dump/dump.js',
            $root . '/tools/treesitter-ast-dump/package-lock.json',
        ];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $fileInfo) {
            if ($fileInfo instanceof \SplFileInfo && $fileInfo->getExtension() === 'php') {
                $files[] = $fileInfo->getPathname();
            }
        }
        sort($files);

        $hash = hash_init('sha1');
        foreach ($files as $file) {
            hash_update($hash, $file . "\0");
            if (is_file($file)) {
                hash_update_file($hash, $file);
            }
        }
        return hash_final($hash);
    }

    public static function key(string $filePath, string $source): string
    {
        return sha1($filePath . "\0" . sha1($source));
    }

    /** @return Finding[]|null */
    public function get(string $key): ?array
    {
        if (!isset($this->entries[$key])) {
            return null;
        }
        return $this->used[$key] = $this->entries[$key];
    }

    /** @param Finding[] $findings */
    public function put(string $key, array $findings): void
    {
        $this->entries[$key] = $this->used[$key] = $findings;
        $this->dirty = true;
    }

    /**
     * Зберігає лише записи цього прогону - видалені й змінені файли
     * вилітають самі, кеш не росте безмежно. Атомарно (тимчасовий файл +
     * rename), щоб паралельний прогін не прочитав напівзаписаний кеш.
     * Невдача запису - не помилка аналізу: кеш лише прискорює.
     */
    public function save(): void
    {
        if (!$this->dirty && count($this->used) === count($this->entries)) {
            return;
        }
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $this->file . '.' . getmypid() . '.tmp';
        $payload = serialize(['format' => self::FORMAT, 'fingerprint' => $this->fingerprint, 'entries' => $this->used]);
        if (@file_put_contents($tmp, $payload) !== false) {
            @rename($tmp, $this->file);
        }
        @unlink($tmp);
    }
}
