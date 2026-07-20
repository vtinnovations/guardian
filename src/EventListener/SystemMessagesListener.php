<?php

declare(strict_types=1);

/**
 * @package   [updater]
 * @author    V&T Innovations Team
 * @license   GNU/LGPL
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\Guardian\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Vtinnovations\Guardian\Controller\BackendController;
use Vtinnovations\Guardian\Service\UpdateNotifier;

/**
 * Adds a system message on the backend home screen when updates are available.
 * Reads from cached data only — never triggers a fresh Packagist lookup.
 *
 * Note: System messages are rendered inside a modal iframe in Contao 5. Any
 * link inside MUST use target="_top" to break out of the iframe.
 */
#[AsHook('getSystemMessages')]
class SystemMessagesListener
{
    public function __construct(
        private readonly UpdateNotifier $updateNotifier,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(): string
    {
        $updates = $this->updateNotifier->getAvailableUpdates();

        if ($updates['count'] === 0) {
            return '';
        }

        $url = $this->urlGenerator->generate(BackendController::class);

        $count   = $updates['count'];
        $checked = $updates['checked_at']
            ? ' (zuletzt geprüft: ' . htmlspecialchars($this->formatDate($updates['checked_at'])) . ')'
            : '';

        $samples = \array_slice($updates['packages'], 0, 5);
        $names   = [];
        foreach ($samples as $p) {
            $names[] = htmlspecialchars($p['name']);
        }
        if ($count > 5) {
            $names[] = '… und ' . ($count - 5) . ' weitere';
        }

        return sprintf(
            '<p class="tl_info" style="background:#fff3cd;color:#856404;border-left:4px solid #f47c00;padding:.8rem 1rem;">'
            . '<strong>📦 %d Paket-Update%s verfügbar</strong>%s<br>'
            . '<span style="font-size:.9rem;">%s</span><br>'
            . '<a href="%s" target="_top" style="font-weight:bold;">→ Guardian öffnen</a>'
            . '</p>',
            $count,
            $count === 1 ? '' : 's',
            $checked,
            implode(', ', $names),
            htmlspecialchars($url)
        );
    }

    private function formatDate(string $iso): string
    {
        $ts = strtotime($iso);
        return $ts ? date('Y-m-d H:i', $ts) : $iso;
    }
}
