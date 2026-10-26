<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\GarbageCollector\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\ExclusionMatcher;
use Shopware\Core\System\SystemConfig\SystemConfigService;

#[CoversClass(ExclusionMatcher::class)]
final class ExclusionMatcherTest extends TestCase
{
    private function matcherWith(string $patterns): ExclusionMatcher
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn($patterns);
        return new ExclusionMatcher($config);
    }

    #[Test]
    public function emptyConfigExcludesNothing(): void
    {
        self::assertFalse($this->matcherWith('')->isExcluded('beliebig.jpg'));
    }

    #[Test]
    public function literalPatternMatchesSubstring(): void
    {
        $matcher = $this->matcherWith("favicon\nplaceholder");
        self::assertTrue($matcher->isExcluded('favicon-32x32.png'));
        self::assertTrue($matcher->isExcluded('product-placeholder.jpg'));
        self::assertFalse($matcher->isExcluded('hero-image.png'));
    }

    #[Test]
    public function regexWithDelimitersIsRespected(): void
    {
        $matcher = $this->matcherWith('/^default-.*\.jpg$/');
        self::assertTrue($matcher->isExcluded('default-product.jpg'));
        self::assertFalse($matcher->isExcluded('product-default.jpg'));
    }

    #[Test]
    public function blankLinesAreIgnored(): void
    {
        $matcher = $this->matcherWith("\n\nfavicon\n\n");
        self::assertTrue($matcher->isExcluded('site-favicon.ico'));
    }
}
