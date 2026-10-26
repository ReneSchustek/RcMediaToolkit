<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Controller\Admin;

use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Message\CheckMediaBatchMessage;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Repository\HealthReportRepository;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Service\HealthCheckRunner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Die Endpunkte der Medienprüfung für die Oberfläche.
 *
 * Das Auflisten ist lesend, das Auslösen eines Laufs nicht — ein Lauf über den gesamten
 * Bestand kostet Zeit und Datenbanklast, und wer ihn starten darf, ist eine andere Frage
 * als wer das Ergebnis sehen darf.
 *
 * Ein unbekannter Prüftyp wird abgewiesen, statt still eine leere Liste zu liefern. Eine
 * leere Antwort auf einen Tippfehler liest sich wie „alles in Ordnung" und ist die
 * gefährlichere der beiden Auskünfte.
 */
final class HealthCheckController extends AbstractController
{
    public function __construct(
        private readonly HealthReportRepository $repository,
        private readonly MessageBusInterface $messageBus,
        private readonly HealthCheckRunner $runner,
    ) {
    }

    #[Route(
        path: '/api/_action/rc-media-toolkit/health/findings',
        name: 'api.action.rc_media_toolkit.health.findings',
        defaults: ['_routeScope' => ['api'], '_acl' => ['rc_media_toolkit.viewer']],
        methods: ['GET'],
    )]
    public function findings(Request $request): JsonResponse
    {
        $checkType = $request->query->get('check');
        $limit = max(1, min(500, (int) $request->query->get('limit', 100)));

        if ($checkType !== null && $checkType !== '' && !in_array($checkType, $this->runner->availableTypes(), true)) {
            return new JsonResponse(['error' => 'Unbekannter check-Parameter.'], 400);
        }

        $findings = $this->repository->listOpenFindings(
            is_string($checkType) && $checkType !== '' ? $checkType : null,
            $limit,
        );

        return new JsonResponse([
            'count' => count($findings),
            'severitySummary' => $this->repository->countOpenBySeverity(),
            'findings' => $findings,
        ]);
    }

    #[Route(
        path: '/api/_action/rc-media-toolkit/health/scan',
        name: 'api.action.rc_media_toolkit.health.scan',
        defaults: ['_routeScope' => ['api'], '_acl' => ['rc_media_toolkit.editor']],
        methods: ['POST'],
    )]
    public function dispatchScan(Request $request): JsonResponse
    {
        $checkType = $request->request->get('check');
        if (!is_string($checkType) || $checkType === '') {
            return new JsonResponse(['error' => 'Parameter "check" fehlt.'], 400);
        }
        if (!in_array($checkType, $this->runner->availableTypes(), true)) {
            return new JsonResponse([
                'error' => 'Unbekannter check-Typ.',
                'available' => $this->runner->availableTypes(),
            ], 400);
        }
        $this->messageBus->dispatch(new CheckMediaBatchMessage($checkType));
        return new JsonResponse(['dispatched' => true, 'checkType' => $checkType]);
    }
}
