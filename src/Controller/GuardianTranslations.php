<?php

declare(strict_types=1);

/*
 * Guardian
 *
 * Package: vtinnovations/guardian
 * Copyright: V&T Innovations Team
 * Licence: LGPL-3.0-or-later
 * Website: https://www.v-t.one
 */

namespace Vtinnovations\Guardian\Controller;

use Contao\System;

/**
 * Looks up a per-locale JSON response string from the `guardian` language
 * file, the same file and the same `api.*` section every controller shares.
 *
 * Deliberately not going through Symfony's `TranslatorInterface::trans()`
 * with parameters here: Contao's `contao_*` domain decorator feeds
 * placeholders through PHP's `vsprintf()`, which chokes on our readable
 * `%name%`-style tokens (they get parsed as sprintf format specifiers). Since
 * `System::loadLanguageFile()` already populates `$GLOBALS['TL_LANG']` for
 * the current backend locale, reading it directly and substituting with
 * `strtr()` sidesteps that entirely.
 */
trait GuardianTranslations
{
    private function msg(string $key, array $params = []): string
    {
        System::loadLanguageFile('guardian');

        $value = $GLOBALS['TL_LANG']['api'][$key] ?? $key;

        return [] === $params ? $value : strtr($value, $params);
    }
}
