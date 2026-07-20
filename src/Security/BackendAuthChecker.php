<?php

declare(strict_types=1);

/**
 * @package   [updater]
 * @author    V&T Innovations Team
 * @license   GNU/LGPL
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\Guardian\Security;

use Contao\BackendUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Centralised permission gate for the Updater backend controllers.
 *
 * `_scope: backend` ensures the route is only matched inside Contao's backend
 * scope, but Contao itself does NOT restrict backend routes to admins by default —
 * any logged-in backend user can hit them. For an Guardian bundle this is too
 * permissive: a content editor could trigger a real update or rewrite the PHP
 * binary path (effectively RCE).
 *
 * Every backend controller calls `assertAdmin()` at the top of every action
 * to lock things down to admin-flagged users only.
 *
 * Implementation note: we use `Security::isGranted('ROLE_ADMIN')` instead of
 * `$user->isAdmin` because the latter depends on the user model being fully
 * hydrated and the property having a truthy PHP value — which varies between
 * Contao versions and edge cases (the DB stores it as char(1) in older versions
 * and tinyint in newer ones). The ROLE_ADMIN check goes through Symfony's
 * security system which Contao populates correctly on every request.
 */
class BackendAuthChecker
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    /**
     * Throws AccessDeniedException if the current user is not a backend admin.
     */
    public function assertAdmin(): void
    {
        $user = $this->security->getUser();

        if (!$user instanceof BackendUser) {
            throw new AccessDeniedException('No authenticated backend user');
        }

        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Updater actions require admin privileges');
        }
    }

    /**
     * Returns the current BackendUser, or null if no one is logged in
     * (or the logged-in entity is not a BackendUser).
     */
    public function getBackendUser(): ?BackendUser
    {
        $user = $this->security->getUser();
        return $user instanceof BackendUser ? $user : null;
    }

    /**
     * Convenience: returns true if the current user is admin, false otherwise.
     * Use for conditional UI rendering rather than for security gates.
     */
    public function isAdmin(): bool
    {
        return $this->getBackendUser() !== null && $this->security->isGranted('ROLE_ADMIN');
    }
}
