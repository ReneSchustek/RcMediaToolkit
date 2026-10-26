<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SimpleXMLElement;

/**
 * Vergleicht die Verdrahtung in `services.xml` mit den Konstruktor-Signaturen.
 *
 * Hintergrund: Die Konsole instanziiert beim Start alle Task-Handler. Ein einziger
 * Service, dessen Argumentliste nicht zu seinem Konstruktor passt, legt damit jede
 * geplante Aufgabe der Instanz lahm — auch die anderer Plugins. Weder PHPStan noch die
 * Unit-Tests sehen diese Lücke, weil keiner von beiden den Container baut.
 *
 * Bewusste Grenze: geprüft wird die **Anzahl** der Argumente, nicht deren Typen. Genau
 * dieser Fehler (ein toter `TaskScheduler`-Parameter im HealthCheck-Handler) wäre damit
 * aufgefallen; eine Typ-Prüfung bliebe eine Ausbaustufe.
 */
final class ServicesXmlWiringTest extends TestCase
{
    private const SERVICES_XML = __DIR__ . '/../../src/Resources/config/services.xml';

    #[Test]
    public function everyWiredServiceMatchesItsConstructorArity(): void
    {
        $deviations = [];

        foreach ($this->wiredServices() as $service) {
            $serviceClass = (string) $service['id'];

            // Alias-Einträge (Interface → Implementierung) haben keine eigene Argumentliste.
            if (isset($service['alias'])) {
                continue;
            }

            self::assertTrue(
                class_exists($serviceClass),
                \sprintf('services.xml verdrahtet die unbekannte Klasse %s.', $serviceClass),
            );

            $constructor = (new ReflectionClass($serviceClass))->getConstructor();
            $required = $constructor?->getNumberOfRequiredParameters() ?? 0;
            $accepted = $constructor?->getNumberOfParameters() ?? 0;
            $wired = $service->argument->count();

            // Trailing-Parameter mit Standardwert darf Symfony weglassen — deshalb eine Spanne.
            if ($wired >= $required && $wired <= $accepted) {
                continue;
            }

            $deviations[] = \sprintf(
                '%s: services.xml verdrahtet %d Argument(e), der Konstruktor erwartet %s.',
                $serviceClass,
                $wired,
                $required === $accepted ? \sprintf('%d', $required) : \sprintf('%d bis %d', $required, $accepted),
            );
        }

        self::assertSame(
            [],
            $deviations,
            "Verdrahtung passt nicht zur Konstruktor-Signatur:\n" . implode("\n", $deviations),
        );
    }

    /**
     * @return list<SimpleXMLElement>
     */
    private function wiredServices(): array
    {
        $xml = simplexml_load_file(self::SERVICES_XML);
        self::assertInstanceOf(SimpleXMLElement::class, $xml, 'services.xml ist nicht lesbar.');

        $services = [];
        foreach ($xml->services->service as $service) {
            $services[] = $service;
        }

        self::assertNotEmpty($services, 'In services.xml wurde kein einziger Service gefunden.');

        return $services;
    }
}
