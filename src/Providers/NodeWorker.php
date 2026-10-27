<?php

declare(strict_types=1);

namespace AnyLint\Providers;

/**
 * Один довгоживучий процес "node <dump.js> --serve" на весь прогін замість
 * окремого запуску node на КОЖЕН файл. Виміряно на реальному проєкті
 * (obriy, 180 JS/Java-файлів - 125 с): ~0.59 с з 0.62 с на файл ішло
 * лише на require('typescript'), сам розбір - соті частки секунди.
 *
 * Протокол - рядок JSON на запит у stdin, рядок JSON на відповідь у
 * stdout (див. serve() у tools/*-ast-dump/dump.js). Запити строго
 * послідовні: наступний пишеться лише після прочитаної відповіді.
 *
 * stderr процесу йде у тимчасовий файл, а НЕ в pipe: pipe, який ніхто не
 * читає, при переповненні буфера (~64 КБ попереджень Node) заблокував би
 * node посеред відповіді, і anylint завис би назавжди.
 */
final class NodeWorker
{
    /** Скільки чекати відповідь на один файл, перш ніж вважати процес завислим. */
    private const TIMEOUT_SECONDS = 60;

    /** @var array<string, self> спільні екземпляри: ключ - node + скрипт */
    private static array $shared = [];

    /** @var resource|null */
    private $process = null;

    /** @var resource|null */
    private $stdin = null;

    /** @var resource|null */
    private $stdout = null;

    private ?string $stderrFile = null;

    public function __construct(
        private readonly string $nodeExecutable,
        private readonly string $scriptPath,
    ) {
    }

    /**
     * Усі провайдери, що ходять в один dump.js (напр. 15 tree-sitter-мов),
     * ділять один процес - інакше кожна мова тягла б власний node.
     */
    public static function shared(string $nodeExecutable, string $scriptPath): self
    {
        $key = $nodeExecutable . "\0" . $scriptPath;
        return self::$shared[$key] ??= new self($nodeExecutable, $scriptPath);
    }

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * @param array<string, mixed> $request
     * @return array{code: int, ast?: array<mixed>, error?: string}
     * @throws \RuntimeException процес не стартує, падає чи зависає навіть
     *         після одного перезапуску
     */
    public function request(array $request): array
    {
        $line = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            throw new \RuntimeException('Не вдалось закодувати запит до dump.js.');
        }

        // Одна повторна спроба зі свіжим процесом: node міг упасти на
        // попередньому файлі (OOM на гігантському згенерованому бандлі
        // тощо) - через це не мають ставати parse-error УСІ наступні файли.
        try {
            return $this->roundTrip($line);
        } catch (\RuntimeException) {
            $this->stop();
            try {
                return $this->roundTrip($line);
            } catch (\RuntimeException $e) {
                $stderr = $this->stderrTail();
                $this->stop();
                throw new \RuntimeException(
                    "'{$this->nodeExecutable} {$this->scriptPath} --serve': {$e->getMessage()}"
                    . ($stderr !== '' ? "\n{$stderr}" : '')
                    . "\nПеревір, що Node.js у PATH (або NODE_EXE) і що в "
                    . \dirname($this->scriptPath) . " виконано 'npm install'.",
                );
            }
        }
    }

    /** @return array{code: int, ast?: array<mixed>, error?: string} */
    private function roundTrip(string $line): array
    {
        $this->start();
        if ($this->stdin === null || $this->stdout === null) {
            throw new \RuntimeException('процес не запущено');
        }

        if (@fwrite($this->stdin, $line . "\n") === false || @fflush($this->stdin) === false) {
            throw new \RuntimeException('процес закрив stdin (упав?)');
        }

        $buffer = '';
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        while (!str_ends_with($buffer, "\n")) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new \RuntimeException('немає відповіді за ' . self::TIMEOUT_SECONDS . ' с');
            }
            $read = [$this->stdout];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, (int) $remaining, (int) (fmod($remaining, 1) * 1e6));
            if ($ready === false) {
                throw new \RuntimeException('stream_select() не вдався');
            }
            if ($ready === 0) {
                continue;
            }
            $chunk = fread($this->stdout, 65536);
            if ($chunk === false || ($chunk === '' && feof($this->stdout))) {
                throw new \RuntimeException('процес завершився, не відповівши');
            }
            $buffer .= $chunk;
        }

        $decoded = json_decode($buffer, true);
        if (!is_array($decoded) || !is_int($decoded['code'] ?? null)) {
            throw new \RuntimeException('невалідна відповідь: ' . substr($buffer, 0, 200));
        }
        $response = ['code' => $decoded['code']];
        if (is_array($decoded['ast'] ?? null)) {
            $response['ast'] = $decoded['ast'];
        }
        if (is_string($decoded['error'] ?? null)) {
            $response['error'] = $decoded['error'];
        }
        return $response;
    }

    private function start(): void
    {
        if ($this->process !== null && proc_get_status($this->process)['running']) {
            return;
        }
        $this->stop();

        $stderrFile = tempnam(sys_get_temp_dir(), 'anylint-node-');
        if ($stderrFile === false) {
            throw new \RuntimeException('не вдалось створити тимчасовий файл для stderr');
        }
        $this->stderrFile = $stderrFile;

        $process = @proc_open(
            [$this->nodeExecutable, $this->scriptPath, '--serve'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderrFile, 'w']],
            $pipes,
        );
        if ($process === false) {
            throw new \RuntimeException("не вдалось запустити '{$this->nodeExecutable}'");
        }
        $this->process = $process;
        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        stream_set_blocking($this->stdout, false);
    }

    private function stop(): void
    {
        if ($this->stdin !== null) {
            @fclose($this->stdin);
        }
        if ($this->stdout !== null) {
            @fclose($this->stdout);
        }
        if ($this->process !== null) {
            // Закритий stdin - сигнал serve() завершитись самому; terminate
            // лише на випадок, якщо процес завис і не читає stdin.
            if (proc_get_status($this->process)['running']) {
                proc_terminate($this->process);
            }
            proc_close($this->process);
        }
        if ($this->stderrFile !== null) {
            @unlink($this->stderrFile);
        }
        $this->process = $this->stdin = $this->stdout = $this->stderrFile = null;
    }

    private function stderrTail(): string
    {
        if ($this->stderrFile === null) {
            return '';
        }
        $content = @file_get_contents($this->stderrFile);
        return $content === false ? '' : trim(substr($content, -2000));
    }
}
