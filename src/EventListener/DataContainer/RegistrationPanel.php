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

namespace Vtinnovations\Guardian\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\Message;
use Contao\System;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Vtinnovations\Guardian\Checker\TrustAnchors;
use Vtinnovations\Guardian\Security\BackendAuthChecker;
use Vtinnovations\Guardian\Service\RegistrationCoordinator;
use Vtinnovations\Guardian\Service\RegistrationStore;
use Vtinnovations\Guardian\Service\SessionEntrySignal;

/**
 * Guardian's licence section in Contao → Settings: the page-load lifecycle
 * hook, plus the single submit dispatcher for the three action buttons
 * rendered by {@see \Vtinnovations\Guardian\BackendModule\RegistrationSummary}.
 *
 * The buttons are plain named `<button type="submit">` controls inside
 * Contao's own single settings form — no `formaction`, no bundle route, no
 * JavaScript beyond a confirmation prompt on the destructive one. A browser
 * only ever submits the clicked button's name/value pair, so "which name is
 * present in the request" is the entire action signal; onSubmit() reads it
 * and dispatches to the matching pure method.
 *
 * #[AsCallback(target: 'config.onsubmit')] merges onSubmit() into
 * tl_settings's config.onsubmit_callback via Contao's own DI compiler pass —
 * the same mechanism autoconfigure already uses for other attribute-based
 * callbacks. Do not also register it manually in the DCA file: that would
 * run the dispatcher twice per submission.
 */
final class RegistrationPanel
{
    public function __construct(
        private readonly RegistrationCoordinator $coordinator,
        private readonly RegistrationStore $store,
        private readonly SessionEntrySignal $entrySignal,
        private readonly BackendAuthChecker $backendAuth,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Runs when the settings page — and with it this licence section — is
     * opened. This is the module-entry point the once-per-session vendor
     * signal is tied to.
     */
    public function onLoad(mixed $dc = null): void
    {
        // Retire the pre-signature licence cache the first time an
        // administrator opens this page after upgrading.
        $this->store->adoptLegacy();

        $this->entrySignal->claim();
    }

    /**
     * Unlike a widget-level `save_callback`, Contao core does not catch an
     * exception thrown from `config.onsubmit_callback` — it would otherwise
     * propagate into a generic error page instead of a friendly message on
     * the settings screen. `AccessDeniedException` is the one exception
     * deliberately let through: Symfony's own security exception listener
     * is the correct place for that to turn into a 403.
     */
    #[AsCallback(table: 'tl_settings', target: 'config.onsubmit')]
    public function onSubmit(DataContainer $dc): void
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            return;
        }

        try {
            // A real click only ever produces exactly one of these three
            // name/value pairs; destructive-first only matters for a
            // deliberately forged request carrying more than one.
            if ($request->request->has('guardianRegistrationRemove')) {
                $this->remove();
            } elseif ($request->request->has('guardianRegistrationRefresh')) {
                $this->refresh();
            } elseif ($request->request->has('guardianRegistrationActivate')) {
                $key = trim((string) $request->request->get('guardianRegistrationKey', ''));
                $this->activate($key);
            }
        } catch (AccessDeniedException $e) {
            throw $e;
        } catch (\Throwable) {
            Message::addError($this->msg('explain_default'));
        }
    }

    /** Verifies and activates a freshly entered key. */
    private function activate(string $key): void
    {
        if ('' === $key) {
            return;
        }

        $this->backendAuth->assertAdmin();

        $this->announce($this->coordinator->activate($key), $this->msg('activated'));
    }

    /**
     * Re-authenticates the stored licence. A failure changes nothing:
     * whatever was valid before stays valid.
     */
    private function refresh(): void
    {
        $this->backendAuth->assertAdmin();

        $this->announce($this->coordinator->refresh(), $this->msg('refreshed'));
    }

    /**
     * Removes the licence. Licensed capabilities return to their
     * unlicensed default immediately; backups, jobs and configuration are
     * untouched.
     */
    private function remove(): void
    {
        $this->backendAuth->assertAdmin();

        $this->coordinator->remove();

        Message::addConfirmation($this->msg('removed'));
    }

    /** @param array{ok: bool, category: string, transient: bool} $result */
    private function announce(array $result, string $success): void
    {
        if ($result['ok']) {
            Message::addConfirmation($success);

            return;
        }

        if ($result['transient']) {
            Message::addError($this->msg('server_unreachable'));

            return;
        }

        Message::addError($this->explain($result['category']));
    }

    /**
     * Maps an internal category to a generic administrator message.
     *
     * The categories stay internal: they name which check failed, which is
     * more than belongs on screen.
     */
    private function explain(string $category): string
    {
        return match ($category) {
            'key_missing' => $this->msg('explain_key_missing'),
            'no_configured_domain' => $this->msg('explain_no_configured_domain'),
            'host_not_authorised', 'operation_host_mismatch' => $this->msg('explain_host_not_authorised'),
            'registry_denied' => $this->msg('explain_registry_denied'),
            TrustAnchors::NO_CRYPTO, TrustAnchors::STORE_EMPTY => $this->msg('explain_no_crypto'),
            default => $this->msg('explain_default'),
        };
    }

    /**
     * Looks up a per-locale string from the `guardian` language file's
     * `license` section. Uses `strtr()` rather than Symfony's translator
     * parameter substitution — Contao's `contao_*` domain decorator feeds
     * parameters through `vsprintf()`, which misparses readable `%name%`
     * tokens as sprintf format specifiers.
     */
    private function msg(string $key, array $params = []): string
    {
        System::loadLanguageFile('guardian');

        $value = $GLOBALS['TL_LANG']['license'][$key] ?? $key;

        return [] === $params ? $value : strtr($value, $params);
    }
}
