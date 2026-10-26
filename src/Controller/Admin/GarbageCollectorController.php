<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Controller\Admin;

use InvalidArgumentException;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\MediaQuarantineService;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\OrphanedMediaFinder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Die Endpunkte des Aufräumers für die Oberfläche.
 *
 * Der Zuschnitt der Rechte folgt der Wirkung, nicht der Ordnung: Suchen und Auflisten sind
 * lesend, Wiederherstellen und endgültiges Löschen brauchen das auslösende Recht. Der
 * Unterschied ist hier keine Förmlichkeit — `purge` ist der einzige Vorgang im ganzen
 * Plugin ohne Rückweg.
 *
 * Die Obergrenze je Abfrage ist fest eingebaut (höchstens 500): Eine Oberfläche, die aus
 * Versehen den ganzen Waisenbestand anfordert, bringt sonst die Antwort und den Browser
 * zugleich zum Stehen.
 */
final class GarbageCollectorController extends AbstractController
{
    public function __construct(
        private readonly OrphanedMediaFinder $finder,
        private readonly MediaQuarantineService $quarantineService,
    ) {
    }

    #[Route(
        path: '/api/_action/rc-media-toolkit/gc/scan',
        name: 'api.action.rc_media_toolkit.gc.scan',
        defaults: ['_routeScope' => ['api'], '_acl' => ['rc_media_toolkit.viewer']],
        methods: ['GET'],
    )]
    public function scan(Request $request): JsonResponse
    {
        $limit = max(1, min(500, (int) $request->query->get('limit', 100)));

        $candidates = [];
        foreach ($this->finder->findOrphans(500, $limit) as $candidate) {
            $candidates[] = $candidate->toArray();
        }

        return new JsonResponse([
            'count' => count($candidates),
            'candidates' => $candidates,
        ]);
    }

    #[Route(
        path: '/api/_action/rc-media-toolkit/gc/runs',
        name: 'api.action.rc_media_toolkit.gc.runs',
        defaults: ['_routeScope' => ['api'], '_acl' => ['rc_media_toolkit.viewer']],
        methods: ['GET'],
    )]
    public function runs(): JsonResponse
    {
        $runs = [];
        foreach ($this->quarantineService->listRuns() as $runId) {
            $manifest = $this->quarantineService->loadManifest($runId);
            $runs[] = [
                'runId' => $runId,
                'itemCount' => count($manifest),
            ];
        }
        return new JsonResponse(['runs' => $runs]);
    }

    #[Route(
        path: '/api/_action/rc-media-toolkit/gc/runs/{runId}/restore',
        name: 'api.action.rc_media_toolkit.gc.restore',
        defaults: ['_routeScope' => ['api'], '_acl' => ['rc_media_toolkit.editor']],
        methods: ['POST'],
    )]
    public function restore(string $runId): JsonResponse
    {
        try {
            $restored = $this->quarantineService->restore($runId);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
        return new JsonResponse(['restored' => $restored]);
    }

    #[Route(
        path: '/api/_action/rc-media-toolkit/gc/runs/{runId}/purge',
        name: 'api.action.rc_media_toolkit.gc.purge',
        defaults: ['_routeScope' => ['api'], '_acl' => ['rc_media_toolkit.editor']],
        methods: ['DELETE'],
    )]
    public function purge(string $runId): JsonResponse
    {
        try {
            $count = $this->quarantineService->purge($runId);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
        return new JsonResponse(['purged' => $count]);
    }
}
