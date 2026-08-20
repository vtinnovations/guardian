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

use Contao\CoreBundle\DataContainer\PaletteManipulator;
use Contao\System;
use Vtinnovations\Guardian\BackendModule\RegistrationSummary;
use Vtinnovations\Guardian\EventListener\DataContainer\RegistrationPanel;

/*
 * Guardian's licence management section, added to Contao → Settings.
 *
 * This is the one and only administrator-facing licence surface for the
 * bundle. The field name is prefixed so several V&T products can add their
 * own field without colliding; the legend key is deliberately shared
 * ("vtone_licence_legend"), so every product's field lands in the same
 * fieldset rather than each opening its own.
 *
 * The section is ONE render-only field (`input_field_callback` short-
 * circuits `DataContainer::row()`, so Contao builds no widget for it and
 * stores nothing): RegistrationSummary renders the current state, the key
 * input and three named `<button type="submit">` action controls, all
 * inside Contao's own single `<form id="tl_settings">`. None of those
 * buttons carries `formaction` — each submits to the form's own default
 * action, exactly like Contao's own Save / Save-and-close buttons.
 * RegistrationPanel::onSubmit() (a `config.onsubmit` callback) reads which
 * button's name is present in the submission and dispatches accordingly.
 * The bundle therefore owns no backend route for licence management and
 * depends on no formaction override, nested form or JavaScript beyond a
 * confirmation prompt on the destructive button.
 *
 * RegistrationPanel::onSubmit() is NOT registered here: its
 * #[AsCallback(target: 'config.onsubmit')] attribute already merges it into
 * config.onsubmit_callback via Contao's own DI compiler pass. Registering
 * it again here would run the dispatcher twice per submission.
 */

$GLOBALS['TL_DCA']['tl_settings']['config']['onload_callback'][] = static function ($dc): void {
    System::getContainer()->get(RegistrationPanel::class)->onLoad($dc);
};

$GLOBALS['TL_DCA']['tl_settings']['fields']['guardianRegistrationSummary'] = [
    'label'                 => &$GLOBALS['TL_LANG']['tl_settings']['guardianRegistrationSummary'],
    'input_field_callback' => [RegistrationSummary::class, 'render'],
    'eval'                 => ['tl_class' => 'clr'],
];

PaletteManipulator::create()
    ->addLegend('vtone_licence_legend', null, PaletteManipulator::POSITION_PREPEND)
    ->addField('guardianRegistrationSummary', 'vtone_licence_legend', PaletteManipulator::POSITION_APPEND)
    ->applyToPalette('default', 'tl_settings')
;
