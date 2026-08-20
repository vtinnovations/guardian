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

use Symfony\Component\HttpFoundation\JsonResponse;
use Vtinnovations\Guardian\Service\RegistrationState;

/**
 * The refusal an endpoint returns when the installation is not entitled to
 * the operation being asked for.
 *
 * Only the response shape lives here. Each endpoint still has to name the
 * capability it needs and check it against the evaluated state itself, so
 * there is no shared "is unlocked" call that could be removed or stubbed to
 * open everything at once — deleting this trait breaks compilation rather
 * than quietly granting access.
 *
 * `reason: license` lets the backend interface tell this apart from an
 * authentication or CSRF failure and show the upgrade notice instead of an
 * error.
 */
trait EntitlementResponses
{
    private function entitlementDenied(string $capability): JsonResponse
    {
        $message = RegistrationState::CAP_BACKUP === $capability
            ? 'Diese Funktion benötigt mindestens eine gültige Free-Lizenz von v-t.one. '
                . 'Trage deinen Lizenzschlüssel unter Contao → Einstellungen → V-T.ONE Licence management → Guardian ein. '
                . 'Ohne Lizenz sind nur Dashboard und Einstellungen erreichbar.'
            : 'Diese Funktion erfordert eine gültige Pro-Lizenz von v-t.one. '
                . 'Trage deinen Lizenzschlüssel unter Contao → Einstellungen → V-T.ONE Licence management → Guardian ein, '
                . 'um Updates, Wiederherstellung, geplante Backups und das Recovery-Panel freizuschalten.';

        return new JsonResponse([
            'success' => false,
            'reason'  => 'license',
            'error'   => $message,
        ], 403);
    }
}
