<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Message;

use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\WebPConverter;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Wandelt ein einzelnes Bild um — der Worker-seitige Teil des Massenlaufs.
 *
 * Ein Fehlschlag wird protokolliert, aber nicht weitergeworfen: Ein einzelnes unlesbares
 * Bild darf nicht die Warteschlange blockieren und den Rest des Bestands aufhalten. Wer
 * wissen will, welche Bilder es getroffen hat, findet sie im Protokoll unter derselben
 * Vorgangskennung.
 */
#[AsMessageHandler]
final class WebPConvertMessageHandler
{
    public function __construct(
        private readonly WebPConverter $converter,
        private readonly MediaToolkitLogger $logger,
    ) {
    }

    public function __invoke(WebPConvertMessage $message): void
    {
        $correlationId = $message->correlationId ?? MediaToolkitLogger::generateCorrelationId();
        $logger = $this->logger
            ->withModule('WebPOptimizer')
            ->withCorrelationId($correlationId);

        $result = $this->converter->convert($message->sourcePath);

        if ($result['status'] === 'failed') {
            $logger->warning('Async-Konvertierung fehlgeschlagen', [
                'path' => $message->sourcePath,
                'reason' => $result['reason'] ?? null,
            ]);
        }
    }
}
