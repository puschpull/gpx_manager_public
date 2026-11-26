<?php
declare(strict_types=1);

namespace GpxManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/i18n.php';

/**
 * t_html(): the translation is escaped, {placeholders} get the caller's HTML.
 */
final class THtmlTest extends TestCase
{
    public function testFallbackIsEscapedAndPlaceholdersFilled(): void
    {
        $out = t_html('__no_such_key__', 'A < B: {n} & {m}', ['n' => '<strong>5</strong>', 'm' => '<em>x</em>']);
        $this->assertSame('A &lt; B: <strong>5</strong> &amp; <em>x</em>', $out);
    }

    public function testUnknownPlaceholderStaysVisible(): void
    {
        $this->assertSame('{x} ok', t_html('__no_such_key__', '{x} ok'));
    }

    public function testQuotesEscaped(): void
    {
        $this->assertSame('&quot;a&quot; &#039;b&#039;', t_html('__no_such_key__', '"a" \'b\''));
    }
}
