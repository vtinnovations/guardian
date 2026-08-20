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

namespace Vtinnovations\Guardian\BackendModule;

use Contao\System;
use Vtinnovations\Guardian\Checker\TrustAnchors;
use Vtinnovations\Guardian\Service\HostInventory;
use Vtinnovations\Guardian\Service\RegistrationPolicy;
use Vtinnovations\Guardian\Service\RegistrationState;
use Vtinnovations\Guardian\Service\RegistrationStore;

/**
 * Renders Guardian's licence section in Contao → Settings: current state,
 * the key input and the three action buttons, all inside Contao's own
 * single `<form id="tl_settings">`.
 *
 * Every button is a plain, named `<button type="submit">` with no
 * `formaction` — the exact mechanism Contao's own toolbar uses to tell
 * "Save" from "Save and close" apart (both are submit buttons in the same
 * form; only the clicked one's name/value pair reaches the server).
 * {@see \Vtinnovations\Guardian\EventListener\DataContainer\RegistrationPanel::onSubmit()}
 * reads which name is present and dispatches accordingly.
 *
 * The state is resolved server-side on every render, so it can never be a
 * stale copy or a permanent "loading" placeholder. The key input never
 * carries a value: it is emitted as a literal empty attribute, not a Contao
 * widget bound to stored state, so there is no code path that could echo an
 * authenticated key back into the page.
 *
 * Contao's `input_field_callback` instantiates this class with `new` and no
 * constructor arguments (System::importStatic bypasses the container
 * entirely for this callback shape), so collaborators are fetched from the
 * container inside build() instead of via constructor injection. This is
 * why RegistrationPolicy/TrustAnchors/RegistrationStore/HostInventory carry
 * an explicit `public: true` in services.yaml — a private service cannot be
 * retrieved by `Container::get()`.
 */
final class RegistrationSummary
{
    /** @var array<string, string> */
    private array $labels = [];

    public function render(mixed $dc = null, string $xlabel = ''): string
    {
        try {
            return $this->build();
        } catch (\Throwable) {
            // Never leak an internal message to the screen; the reason
            // belongs in the logs, not in the administrator's face.
            return '<div class="widget"><p class="tl_error">'.$this->esc($this->msg('panel_unavailable')).'</p></div>';
        }
    }

    private function build(): string
    {
        $container = System::getContainer();
        $policy    = $container->get(RegistrationPolicy::class);
        $anchors   = $container->get(TrustAnchors::class);
        $store     = $container->get(RegistrationStore::class);
        $inventory = $container->get(HostInventory::class);
        \assert($policy instanceof RegistrationPolicy);
        \assert($anchors instanceof TrustAnchors);
        \assert($store instanceof RegistrationStore);
        \assert($inventory instanceof HostInventory);

        $state = $policy->state();

        $html = '<div class="widget vt-guardian-license" style="max-width:640px">';
        $html .= '<h3>' . $this->esc($GLOBALS['TL_LANG']['tl_settings']['guardianRegistrationSummary'][0] ?? 'Guardian') . '</h3>';
        $html .= '<div style="padding:12px 15px;border:1px solid var(--content-border);border-radius:4px;background:var(--content-bg)">';

        if (!$anchors->isReady()) {
            $html .= sprintf('<p class="tl_error" style="margin:0 0 8px">%s</p>', $this->esc($this->msg('no_crypto')));
        }

        $html .= $this->statusLine($state);
        $html .= $this->detailLine($policy, $store, $state);

        if ([] === $inventory->configured()) {
            $html .= sprintf('<p class="tl_error" style="margin:8px 0 0;font-size:12px">%s</p>', $this->esc($this->msg('no_domain_configured')));
        }

        $html .= $this->controls();
        $html .= '</div></div>';

        return $html;
    }

    /**
     * One line per distinguishable state. The Trial/Free/Pro model has to
     * show unlicensed, Trial active, Free active, Pro active, Pro expired
     * WITH the signed Free fallback, and every unlicensed reason
     * separately — collapsing them into a single licensed/unlicensed
     * boolean would hide exactly what an administrator needs to know to
     * decide what to do next.
     */
    private function statusLine(RegistrationState $state): string
    {
        [$colour, $label] = match ($state->state) {
            RegistrationState::PAID_ACTIVE   => ['var(--green)', $this->msg('state_pro_active')],
            RegistrationState::TRIAL_ACTIVE  => ['var(--green)', $this->msg('state_trial_active')],
            RegistrationState::FREE_ACTIVE   => ['var(--green)', $this->msg('state_free_active')],
            RegistrationState::PAID_FALLBACK => ['var(--orange)', $this->msg('state_paid_fallback')],
            default                          => ['var(--red)', $this->describeUnlicensed($state->reason)],
        };

        return sprintf(
            '<div style="font-size:15px;font-weight:bold;color:%s;margin-bottom:4px">%s</div>',
            $colour,
            $this->esc($label),
        );
    }

    private function describeUnlicensed(string $reason): string
    {
        return match ($reason) {
            'expired'                 => $this->msg('state_expired'),
            'not_yet_valid'           => $this->msg('state_not_yet_valid'),
            'host_not_authorised'     => $this->msg('state_host_not_authorised'),
            'issuer_withheld'         => $this->msg('state_issuer_withheld'),
            'tier_not_accepted'       => $this->msg('state_tier_not_accepted'),
            RegistrationStore::ABSENT => $this->msg('state_absent'),
            default                   => $this->msg('state_default'),
        };
    }

    /**
     * Key mask, package and the three dates while a record exists; a plain hint
     * otherwise.
     *
     * Deliberately five facts and no more — the same five every V-T.ONE section
     * on this screen shows. Version, bound domain, the whole signed domain set
     * and the allowance were dropped: they are record internals an
     * administrator never acts on from here, and they crowded out the dates
     * that actually decide whether the installation keeps working.
     */
    private function detailLine(RegistrationPolicy $policy, RegistrationStore $store, RegistrationState $state): string
    {
        if (!$state->hasAuthenticRecord) {
            $text = '' !== $store->carriedKey() ? $this->msg('legacy_key_found') : $this->msg('panel_hint');

            return sprintf('<div class="tl_gray" style="font-size:12px">%s</div>', $this->esc($text));
        }

        $record = $policy->record();

        $parts = [
            $this->msg('detail_key', ['%value%' => $this->mask($record?->key() ?? '')]),
            $this->msg('detail_package', ['%value%' => '' !== $state->tier ? strtoupper($state->tier) : '—']),
            $this->msg('detail_valid_from', ['%value%' => $this->moment($state->startsAt)]),
            $this->msg('detail_valid_until', ['%value%' => $state->lifetime ? $this->msg('detail_unlimited') : $this->moment($state->expiresAt ?? 0)]),
            $this->msg('detail_last_verified', ['%value%' => $this->moment($record?->verifiedAt() ?? 0)]),
        ];

        return sprintf('<div class="tl_gray" style="font-size:12px;line-height:1.7">%s</div>', implode(' · ', $parts));
    }

    /**
     * The key input plus the three action buttons. Every button carries a
     * distinct `name`, no `value` beyond the fixed marker `"1"`, and no
     * `formaction`/`formmethod` — only a plain `confirm()` gate on the
     * destructive one, which does not touch where or how the form submits.
     */
    private function controls(): string
    {
        $html = sprintf(
            '<label style="display:block;margin:12px 0 4px"><strong>%s</strong></label>'
            .'<input type="text" name="guardianRegistrationKey" value="" autocomplete="off" spellcheck="false" '
            .'style="width:100%%;padding:6px;box-sizing:border-box" placeholder="%s">',
            $this->esc($this->msg('panel_key_label')),
            $this->esc($this->msg('panel_key_placeholder')),
        );

        $html .= '<div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">';
        $html .= $this->button('guardianRegistrationActivate', $this->msg('panel_activate'));
        $html .= $this->button('guardianRegistrationRefresh', $this->msg('panel_refresh'));
        $html .= $this->button(
            'guardianRegistrationRemove',
            $this->msg('panel_remove'),
            'onclick="return confirm(\''.$this->escJs($this->msg('panel_remove_confirm')).'\')"',
        );
        $html .= '</div>';

        return $html;
    }

    private function button(string $name, string $label, string $extraAttrs = ''): string
    {
        return sprintf(
            '<button type="submit" name="%s" value="1" class="tl_submit"%s>%s</button>',
            $this->esc($name),
            '' !== $extraAttrs ? ' '.$extraAttrs : '',
            $this->esc($label),
        );
    }

    /** Shows enough of the key to recognise it and no more. */
    private function mask(string $key): string
    {
        $length = \strlen($key);

        if (0 === $length) {
            return '—';
        }

        if ($length <= 8) {
            return str_repeat('•', $length);
        }

        return substr($key, 0, 4).str_repeat('•', max(4, $length - 8)).substr($key, -4);
    }

    private function moment(int $timestamp): string
    {
        return $timestamp > 0 ? date('Y-m-d H:i', $timestamp) : '—';
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
        if ([] === $this->labels) {
            System::loadLanguageFile('guardian');
            $strings       = $GLOBALS['TL_LANG']['license'] ?? [];
            $this->labels  = \is_array($strings) ? $strings : [];
        }

        $value = $this->labels[$key] ?? $key;

        return [] === $params ? $value : strtr($value, $params);
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function escJs(string $value): string
    {
        return str_replace(["'", "\n", "\r"], ["\\'", ' ', ''], $value);
    }
}
