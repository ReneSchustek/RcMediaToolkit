<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\GarbageCollector\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Exception\InvalidExclusionPatternException;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\ExclusionMatcher;
use Shopware\Core\System\SystemConfig\SystemConfigService;

#[CoversClass(ExclusionMatcher::class)]
final class ExclusionMatcherEdgeCasesTest extends TestCase
{
    #[Test]
    public function refreshReloadsPatterns(): void
    {
        $patterns = "favicon\n";
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(static function () use (&$patterns): string {
            return $patterns;
        });

        $matcher = new ExclusionMatcher($config);
        self::assertTrue($matcher->isExcluded('favicon.ico'));

        $patterns = "logo\n";
        $matcher->refresh();
        self::assertFalse($matcher->isExcluded('favicon.ico'));
        self::assertTrue($matcher->isExcluded('site-logo.png'));
    }

    /**
     * Ein ungültiges Muster hält den Lauf an und nennt das Muster. Lieferte es still `false`, damit
     * nichts abstürzt, gälten die Dateien, die jemand schützen wollte, als nicht ausgeschlossen und
     * landeten beim nächsten Lauf in der Quarantäne.
     */
    #[Test]
    public function invalidPatternAbortsTheRun(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn('/(invalid[regex/');

        $matcher = new ExclusionMatcher($config);

        $this->expectException(InvalidExclusionPatternException::class);
        $this->expectExceptionMessageMatches('/\(invalid\[regex/');
        $matcher->isExcluded('any.png');
    }

    #[Test]
    public function invalidPatternIsReportedWithTheReasonFromPhp(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn('/(invalid[regex/');

        $matcher = new ExclusionMatcher($config);

        try {
            $matcher->isExcluded('any.png');
            self::fail('Erwartet wurde ein Abbruch wegen des ungültigen Musters.');
        } catch (InvalidExclusionPatternException $exception) {
            // Die aufgefangene PHP-Warnung sagt am genauesten, was am Muster nicht stimmt.
            self::assertStringContainsString('preg_match', $exception->getMessage());
        }
    }

    #[Test]
    public function validPatternsNextToEachOtherKeepWorking(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn("/^logo/
favicon
");

        $matcher = new ExclusionMatcher($config);

        self::assertTrue($matcher->isExcluded('logo-header.png'));
        self::assertTrue($matcher->isExcluded('favicon.ico'));
        self::assertFalse($matcher->isExcluded('produktbild.jpg'));
    }

    #[Test]
    public function unicodeFileNamesAreSupported(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn('üöä');

        $matcher = new ExclusionMatcher($config);
        self::assertTrue($matcher->isExcluded('großüöä-bild.jpg'));
    }
}
