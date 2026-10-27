<?php

declare(strict_types=1);

namespace AnyLint;

/**
 * Провайдер, чий результат залежить від зовнішнього інструмента, версія
 * якого НЕ зафіксована в цьому репозиторії (напр. встановлений окремо
 * "nx"). Кеш результатів (ResultCache) додає toolFingerprint() до ключа,
 * тож оновлення інструмента скидає кеш, а не віддає старе AST.
 * Провайдери на tools/*-ast-dump цього не потребують: їхні версії
 * закріплені package-lock.json, який і так входить у відбиток anylint.
 */
interface ExternalToolProvider extends LanguageProvider
{
    public function toolFingerprint(): string;
}
