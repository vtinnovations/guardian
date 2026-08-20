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

namespace Vtinnovations\Guardian\EventListener;

use Contao\CoreBundle\Event\ContaoCoreEvents;
use Contao\CoreBundle\Event\MenuEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Vtinnovations\Guardian\Controller\BackendController;
use Vtinnovations\Guardian\Service\UpdateNotifier;

#[AsEventListener(ContaoCoreEvents::BACKEND_MENU_BUILD, priority: -255)]
class BackendMenuListener
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly UpdateNotifier $updateNotifier,
    ) {
    }

    public function __invoke(MenuEvent $event): void
    {
        $factory = $event->getFactory();
        $tree    = $event->getTree();

        if ('mainMenu' !== $tree->getName()) {
            return;
        }

        $contentNode = $tree->getChild('content');
        if (null === $contentNode) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        $updates = $this->updateNotifier->getAvailableUpdates();

        // Plain-text label only — Contao escapes labels, so HTML doesn't render.
        // We append the count in parentheses, which is universally safe.
        $label   = 'Guardian';
        $tooltip = 'Guardian';

        if ($updates['count'] > 0) {
            $label   = sprintf('Guardian (%d)', $updates['count']);
            $tooltip = sprintf('%d update(s) available', $updates['count']);
        }

        $node = $factory
            ->createItem('vtinnovations_guardian', ['route' => BackendController::class])
            ->setLabel($label)
            ->setLinkAttribute('title', $tooltip)
            ->setLinkAttribute('class', 'vtinnovations-guardian')
            ->setCurrent($request?->attributes->get('_controller') === BackendController::class);

        $contentNode->addChild($node);
    }
}
