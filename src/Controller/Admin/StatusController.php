<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Controller\Admin;

use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Repository\HealthReportRepository;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\EncoderChain;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\WebPRegistry;
use Ruhrcoder\RcMediaToolkit\Service\ModuleRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Ein Endpunkt, der in einem Zug beantwortet, wie es um das Plugin steht.
 *
 * Bewusst eine einzige Antwort statt drei Endpunkten je Modul: Die Oberfläche fragt beim
 * Öffnen genau einmal und zeigt danach alles. Drei Abfragen wären drei Gelegenheiten, dass
 * eine davon hängt und die Seite halb gefüllt stehen bleibt.
 *
 * Er liest nur — deshalb reicht ihm das lesende Recht.
 */
final class StatusController extends AbstractController
{
    public function __construct(
        private readonly ModuleRegistry $moduleRegistry,
        private readonly HealthReportRepository $healthRepository,
        private readonly WebPRegistry $webPRegistry,
        private readonly EncoderChain $encoderChain,
    ) {
    }

    #[Route(
        path: '/api/_action/rc-media-toolkit/status',
        name: 'api.action.rc_media_toolkit.status',
        defaults: ['_routeScope' => ['api'], '_acl' => ['rc_media_toolkit.viewer']],
        methods: ['GET'],
    )]
    public function status(): JsonResponse
    {
        return new JsonResponse([
            'modules' => $this->moduleRegistry->statusMap(),
            'health' => [
                'openBySeverity' => $this->healthRepository->countOpenBySeverity(),
            ],
            'webp' => [
                'stats' => $this->webPRegistry->stats(),
                'encoders' => $this->encoderChain->availabilityMap(),
                'encoderVersions' => $this->encoderChain->versionMap(),
            ],
        ]);
    }
}
