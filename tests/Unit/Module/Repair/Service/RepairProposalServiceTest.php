<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\Repair\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMediaToolkit\Module\Repair\Dto\RepairProposal;
use Ruhrcoder\RcMediaToolkit\Module\Repair\Service\RepairProposalService;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Die Vorschläge, die einem Befund zur Seite gestellt werden.
 *
 * Sie sind kein Automatismus — jemand sieht sie an und entscheidet. Genau daraus folgt die
 * Anforderung: **Ein Vorschlag muss die Entscheidung tragen können.** Ein falscher, der sicher
 * aussieht, ist schlimmer als gar keiner, denn er wird angenommen; und eine Liste, die den
 * richtigen Treffer nicht enthält, führt dazu, dass jemand einen der falschen nimmt, weil dort ja
 * etwas steht.
 */
#[CoversClass(RepairProposalService::class)]
final class RepairProposalServiceTest extends TestCase
{
    private const MEDIA_ID = 'aabbccddeeff00112233445566778899';

    /**
     * Was: Ein Befundtyp, für den es keine Reparatur gibt.
     * Warum: Dann kommt eine leere Liste — kein erfundener Vorschlag. Für verwaiste Dateien und
     *        falsche Dateitypen ist ein automatischer Eingriff nicht zu verantworten; dass hier
     *        nichts angeboten wird, ist die Entscheidung und nicht eine Lücke.
     */
    #[Test]
    public function anUnsupportedFindingTypeYieldsNoProposals(): void
    {
        $service = $this->service();

        self::assertSame([], $service->proposalsFor(['check_type' => 'orphaned_file', 'media_id' => self::MEDIA_ID]));
        self::assertSame([], $service->proposalsFor(['check_type' => 'mime_mismatch', 'media_id' => self::MEDIA_ID]));
        self::assertSame([], $service->proposalsFor([]));
    }

    /**
     * Was: Ein fehlendes Vorschaubild.
     * Warum: Der einzige Fall, der sich verlässlich beheben lässt — das Bild wird aus dem
     *        Original neu berechnet. Die hohe Zuversicht sagt genau das aus; sie ist die
     *        Grundlage dafür, dass jemand den Vorschlag ungeprüft annimmt.
     */
    #[Test]
    public function aMissingThumbnailIsRegeneratedFromTheOriginal(): void
    {
        $proposals = $this->service()->proposalsFor([
            'check_type' => 'broken_thumbnail',
            'media_id' => self::MEDIA_ID,
        ]);

        self::assertCount(1, $proposals);
        self::assertSame(RepairProposal::TYPE_REGENERATE_THUMBNAIL, $proposals[0]->proposalType);
        self::assertSame(['mediaId' => self::MEDIA_ID], $proposals[0]->payload);
        self::assertGreaterThan(0.8, $proposals[0]->confidence);
    }

    /**
     * Ohne Medienkennung gibt es nichts vorzuschlagen. Der Fall tritt bei Befunden aus dem
     * Dateisystem auf, die keinen Datensatz haben.
     */
    #[Test]
    public function aFindingWithoutAMediaIdYieldsNothing(): void
    {
        $service = $this->service();

        self::assertSame([], $service->proposalsFor(['check_type' => 'broken_thumbnail', 'media_id' => '']));
        self::assertSame([], $service->proposalsFor(['check_type' => 'missing_file']));
    }

    /**
     * Was: Eine fehlende Datei mit ähnlich benannten Medien im Bestand.
     * Warum: Der eigentliche Nutzen — nach einem Umbenennen liegt die Datei unter fast demselben
     *        Namen. Die Zuversicht fällt mit dem Abstand: Ein Zeichen Unterschied ist etwas
     *        anderes als drei.
     */
    #[Test]
    public function aMissingFileIsMatchedAgainstSimilarNames(): void
    {
        $service = $this->service(candidates: [
            ['id' => str_repeat('1', 32), 'file_name' => 'produktbild', 'file_extension' => 'jpg'],
            ['id' => str_repeat('2', 32), 'file_name' => 'produktbildX', 'file_extension' => 'jpg'],
            ['id' => str_repeat('3', 32), 'file_name' => 'ganz-anderes-motiv', 'file_extension' => 'jpg'],
        ]);

        $proposals = $service->proposalsFor(['check_type' => 'missing_file', 'media_id' => self::MEDIA_ID]);

        self::assertCount(2, $proposals, 'Der weit entfernte Name gehört nicht in die Liste.');
        self::assertSame(RepairProposal::TYPE_RELINK_BY_NAME, $proposals[0]->proposalType);
        self::assertSame(str_repeat('1', 32), $proposals[0]->payload['replacementMediaId']);
        self::assertSame(0, $proposals[0]->payload['distance']);
    }

    /**
     * Was: Der beste Treffer steht in der Datenbank an vierter Stelle.
     * Warum: **Der Fall, an dem eine zu frühe Kürzung scheitert.** Angeboten werden drei
     *        Vorschläge; würde vor dem Sortieren abgeschnitten, wären es die drei, die zuerst
     *        gelesen werden. Der exakte Treffer fiele weg, und dem Anwender lägen drei
     *        entferntere vor, von denen keiner passt — ohne dass die Liste unvollständig aussähe.
     */
    #[Test]
    public function theThreeOfferedProposalsAreTheClosestOnes(): void
    {
        $service = $this->service(candidates: [
            ['id' => str_repeat('a', 32), 'file_name' => 'produktbildXYZ', 'file_extension' => 'jpg'],
            ['id' => str_repeat('b', 32), 'file_name' => 'produktbildXY', 'file_extension' => 'jpg'],
            ['id' => str_repeat('c', 32), 'file_name' => 'produktbildX', 'file_extension' => 'jpg'],
            ['id' => str_repeat('d', 32), 'file_name' => 'produktbild', 'file_extension' => 'jpg'],
        ]);

        $proposals = $service->proposalsFor(['check_type' => 'missing_file', 'media_id' => self::MEDIA_ID]);

        self::assertCount(3, $proposals);
        self::assertSame(
            str_repeat('d', 32),
            $proposals[0]->payload['replacementMediaId'],
            'Der exakte Treffer muss dabei sein, auch wenn er zuletzt gelesen wird.',
        );
        self::assertSame([0, 1, 2], array_column(array_map(
            static fn (RepairProposal $p): array => $p->payload,
            $proposals,
        ), 'distance'));
    }

    /**
     * Was: Der erlaubte Abstand aus den Einstellungen.
     * Warum: Er entscheidet über die Balance zwischen brauchbaren und irreführenden Vorschlägen.
     *        Griffe die Einstellung nicht, ließe sich ein zu großzügiger Vorgabewert nicht
     *        eindämmen — und die Liste füllte sich mit Namen, die zufällig ähnlich sind.
     */
    #[Test]
    public function theAllowedDistanceComesFromTheSettings(): void
    {
        $candidates = [['id' => str_repeat('1', 32), 'file_name' => 'produktbildXX', 'file_extension' => 'jpg']];

        $strict = $this->service(candidates: $candidates, maxDistance: 1);
        $generous = $this->service(candidates: $candidates, maxDistance: 3);

        self::assertSame([], $strict->proposalsFor(['check_type' => 'missing_file', 'media_id' => self::MEDIA_ID]));
        self::assertCount(1, $generous->proposalsFor(['check_type' => 'missing_file', 'media_id' => self::MEDIA_ID]));
    }

    /**
     * Ohne eingestellten Abstand gilt eine Vorgabe. Ohne sie käme bei nicht gesetzter Einstellung
     * die Null heraus — und damit nur noch der buchstabengetreu gleiche Name.
     */
    #[Test]
    public function withoutASettingADefaultDistanceApplies(): void
    {
        $service = $this->service(
            candidates: [['id' => str_repeat('1', 32), 'file_name' => 'produktbil', 'file_extension' => 'jpg']],
            maxDistance: null,
        );

        self::assertCount(1, $service->proposalsFor(['check_type' => 'missing_file', 'media_id' => self::MEDIA_ID]));
    }

    /**
     * Gibt es den Datensatz zum Befund nicht mehr, entfällt der Vergleich — ohne Namen ist nichts
     * zu vergleichen. Der Fall entsteht, wenn zwischen Prüfung und Reparatur jemand aufgeräumt
     * hat.
     */
    #[Test]
    public function aVanishedRecordEndsTheComparison(): void
    {
        $service = $this->service(original: false);

        self::assertSame([], $service->proposalsFor(['check_type' => 'missing_file', 'media_id' => self::MEDIA_ID]));
    }

    /**
     * Was: Ein kaputter Verweis aus einer Erlebniswelt.
     * Warum: Hier lässt sich der richtige Ersatz nicht erschließen — verglichen werden Kennungen,
     *        und die sagen über Ähnlichkeit nichts aus. Die niedrige Zuversicht ist deshalb die
     *        eigentliche Aussage des Vorschlags: **Das hier muss jemand ansehen.**
     */
    #[Test]
    public function aBrokenCmsReferenceIsOnlyOfferedWithLowConfidence(): void
    {
        $service = $this->service(candidates: [
            ['id' => self::MEDIA_ID, 'file_name' => 'ersatz'],
        ]);

        $proposals = $service->proposalsFor([
            'check_type' => 'broken_cms_reference',
            'media_id' => self::MEDIA_ID,
            'source_reference' => 'slot:1111:' . self::MEDIA_ID,
        ]);

        self::assertCount(1, $proposals);
        self::assertSame(RepairProposal::TYPE_REWRITE_CMS_REFERENCE, $proposals[0]->proposalType);
        self::assertLessThan(0.5, $proposals[0]->confidence);
        self::assertSame('slot:1111:' . self::MEDIA_ID, $proposals[0]->payload['slotReference']);
    }

    /**
     * Ohne Fundstelle lässt sich kein Verweis umbiegen — es fehlte die Angabe, wo. Der Vorschlag
     * entfällt, statt auf einen leeren Ort zu zeigen.
     */
    #[Test]
    public function aCmsReferenceWithoutItsOriginIsNotRepairable(): void
    {
        $proposals = $this->service()->proposalsFor([
            'check_type' => 'broken_cms_reference',
            'media_id' => self::MEDIA_ID,
            'source_reference' => '',
        ]);

        self::assertSame([], $proposals);
    }

    /**
     * Was: Ein Medium, dessen Kennung sich stark von der gesuchten unterscheidet.
     * Warum: Beim Umbiegen eines Verweises werden Kennungen verglichen, und die sagen über
     *        Ähnlichkeit nichts aus. Wer zu weit weg liegt, fällt heraus — sonst stünden bei
     *        jedem Befund die immer gleichen zufälligen Medien in der Liste.
     */
    #[Test]
    public function aDistantIdIsNotOfferedForACmsRewrite(): void
    {
        $service = $this->service(candidates: [
            ['id' => str_repeat('f', 32), 'file_name' => 'weit-weg'],
        ]);

        $proposals = $service->proposalsFor([
            'check_type' => 'broken_cms_reference',
            'media_id' => str_repeat('0', 32),
            'source_reference' => 'slot:1111:' . str_repeat('0', 32),
        ]);

        self::assertSame([], $proposals);
    }

    /**
     * @param list<array<string, mixed>> $candidates
     */
    private function service(
        array $candidates = [],
        ?int $maxDistance = 3,
        bool $original = true,
    ): RepairProposalService {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(
            $original ? ['file_name' => 'produktbild', 'file_extension' => 'jpg'] : false,
        );
        $connection->method('fetchAllAssociative')->willReturn($candidates);

        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn($maxDistance);

        return new RepairProposalService($connection, $config);
    }
}
