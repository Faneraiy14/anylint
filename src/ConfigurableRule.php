<?php

declare(strict_types=1);

namespace AnyLint;

/**
 * Правило, яке приймає опції з anylint.json (поріг глибини, довжини
 * тощо). Окремий інтерфейс, а не метод у Rule: більшість правил опцій не
 * має, і тоді будь-яка опція в конфігу для них - помилка користувача, яку
 * Analyzer має показати, а не мовчки проковтнути.
 */
interface ConfigurableRule extends Rule
{
    /**
     * Повертає НОВИЙ екземпляр з застосованими опціями - сам екземпляр
     * незмінний, щоб одне й те саме правило можна було безпечно ділити
     * між кількома Analyzer.
     *
     * @param array<string, mixed> $options
     * @throws \InvalidArgumentException невідома опція або неприйнятне значення
     */
    public function withOptions(array $options): static;
}
