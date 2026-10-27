<?php

declare(strict_types=1);

namespace AnyLint;

/**
 * Розподіляє файли між кількома дочірніми процесами (pcntl_fork). Саме
 * процеси, а не лише кілька node-воркерів: після переходу на --serve
 * (NodeWorker) приблизно половина часу - це PHP-бік (json_decode
 * багатомегабайтних AST, побудова Node, правила), а він однопотоковий.
 *
 * Кожен дочірній процес піднімає ВЛАСНИЙ NodeWorker (батько до fork-а
 * жодного не запускає), пише свої знахідки в тимчасовий файл і
 * завершується; батько збирає їх у вихідному порядку файлів, тож вивід
 * не залежить від кількості процесів. Без pcntl (Windows, урізані
 * збірки PHP) повертає null - Analyzer тоді йде послідовно.
 */
final class ParallelRunner
{
    public static function isSupported(): bool
    {
        return function_exists('pcntl_fork') && function_exists('pcntl_waitpid');
    }

    /** Кількість логічних ядер; 1, якщо визначити не вдалось. */
    public static function cpuCount(): int
    {
        $env = getenv('NUMBER_OF_PROCESSORS');
        if (is_string($env) && ctype_digit($env)) {
            return max(1, (int) $env);
        }
        $nproc = @shell_exec('nproc 2>/dev/null') ?: @shell_exec('sysctl -n hw.ncpu 2>/dev/null');
        if (is_string($nproc) && ctype_digit(trim($nproc))) {
            return max(1, (int) trim($nproc));
        }
        return 1;
    }

    /**
     * @param list<string> $files
     * @param callable(string): Finding[] $analyze
     * @return list<Finding[]>|null знахідки по кожному файлу в порядку $files; null - fork недоступний
     */
    public static function run(array $files, int $jobs, callable $analyze): ?array
    {
        if (!self::isSupported() || $jobs < 2 || count($files) < 2) {
            return null;
        }
        $jobs = min($jobs, count($files));

        /** @var list<array{pid: int, socket: resource, file: string}> $children */
        $children = [];
        for ($i = 0; $i < $jobs; $i++) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $resultFile = tempnam(sys_get_temp_dir(), 'anylint-job-');
            if ($pair === false || $resultFile === false) {
                break;
            }
            $pid = pcntl_fork();
            if ($pid === -1) {
                @unlink($resultFile);
                break;
            }
            if ($pid === 0) {
                fclose($pair[0]);
                self::runChild($files, $pair[1], $analyze, $resultFile);
            }
            fclose($pair[1]);
            $children[] = ['pid' => $pid, 'socket' => $pair[0], 'file' => $resultFile];
        }
        if ($children === []) {
            return null;
        }

        self::dispatch($files, $children);

        /** @var array<int, Finding[]> $byIndex */
        $byIndex = [];
        foreach ($children as $child) {
            pcntl_waitpid($child['pid'], $status);
            $payload = @file_get_contents($child['file']);
            @unlink($child['file']);
            $result = $payload === false ? false : @unserialize($payload, ['allowed_classes' => [Finding::class, Severity::class]]);

            if (is_array($result) && isset($result['error']) && is_string($result['error'])) {
                throw new \RuntimeException($result['error']);
            }
            // Дочірній процес упав, не записавши результат (OOM, сигнал) -
            // його файли доробляються нижче, послідовно, а не губляться.
            if (!is_array($result) || !isset($result['findings']) || !is_array($result['findings'])) {
                continue;
            }
            foreach ($result['findings'] as $index => $findings) {
                if (is_int($index) && is_array($findings)) {
                    $byIndex[$index] = array_values(array_filter($findings, static fn ($f) => $f instanceof Finding));
                }
            }
        }

        $ordered = [];
        foreach ($files as $index => $file) {
            $ordered[] = $byIndex[$index] ?? $analyze($file);
        }
        return $ordered;
    }

    /**
     * Динамічна черга: кожен процес отримує наступний файл, щойно звітує
     * про попередній. Статичний поділ заздалегідь (навіть за розміром)
     * виявився марним на реальному проєкті: великі .png/.apk/.jar
     * аналізуються миттєво (лише текстові правила), тож "рівні за байтами"
     * частини були дуже нерівні за часом - 4 процеси давали 7.2 -> 6.9 с.
     * Найбільші файли - першими, щоб наприкінці не чекати на один гігант.
     *
     * @param list<string> $files
     * @param list<array{pid: int, socket: resource, file: string}> $children
     */
    private static function dispatch(array $files, array $children): void
    {
        $sizes = [];
        foreach ($files as $index => $file) {
            $sizes[$index] = (int) @filesize($file);
        }
        arsort($sizes);
        $queue = array_keys($sizes);

        /** @var array<int, resource> $active */
        $active = [];
        foreach ($children as $i => $child) {
            $active[$i] = $child['socket'];
            self::sendNext($child['socket'], $queue);
        }

        while ($active !== []) {
            $read = array_values($active);
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, null) === false) {
                break;
            }
            foreach ($read as $socket) {
                $i = array_search($socket, $active, true);
                if ($i === false) {
                    continue;
                }
                $line = fgets($socket);
                if ($line === false || !self::sendNext($socket, $queue)) {
                    // Черга порожня (або процес помер) - закриття сокета
                    // для дочірнього означає "більше файлів не буде".
                    fclose($socket);
                    unset($active[$i]);
                }
            }
        }
    }

    /**
     * @param resource $socket
     * @param list<int> $queue
     */
    private static function sendNext($socket, array &$queue): bool
    {
        if ($queue === []) {
            return false;
        }
        $index = array_shift($queue);
        return @fwrite($socket, $index . "\n") !== false;
    }

    /**
     * @param list<string> $files
     * @param resource $socket
     * @param callable(string): Finding[] $analyze
     */
    private static function runChild(array $files, $socket, callable $analyze, string $resultFile): never
    {
        try {
            $findings = [];
            while (($line = fgets($socket)) !== false) {
                $index = (int) trim($line);
                $findings[$index] = $analyze($files[$index]);
                fwrite($socket, "done\n");
            }
            $payload = ['findings' => $findings];
        } catch (\RuntimeException $e) {
            $payload = ['error' => $e->getMessage()];
        }
        file_put_contents($resultFile, serialize($payload));
        // НЕ exit(): дочірній процес успадкував буфери виводу й shutdown-
        // обробники батька (у тестах - PHPUnit), exit() виконав би їх ще
        // раз і задублював би вивід. SIGKILL обриває процес одразу; його
        // node-воркер отримує EOF на stdin і завершується сам.
        if (function_exists('posix_kill') && function_exists('posix_getpid')) {
            posix_kill(posix_getpid(), SIGKILL);
        }
        exit(0);
    }
}
