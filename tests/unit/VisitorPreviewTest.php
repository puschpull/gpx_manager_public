<?php
declare(strict_types=1);

namespace GpxManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/visitor_preview.php';

/**
 * Visitor preview switch: works for the IP admin and (since 25 Nov 2026) for
 * the admin who logged in with the password; never for a plain visitor.
 */
final class VisitorPreviewTest extends TestCase
{
    public function testPlainVisitorCannotSwitch(): void
    {
        $s = [];
        $this->assertNull(visitor_preview_apply($s, '1', false, false, 'detail.php?id=1'));
        $this->assertNull(visitor_preview_apply($s, '0', false, false, 'detail.php?id=1'));
        $this->assertSame([], $s, 'visitor must not get is_admin');
    }

    public function testIpAdminRoundTrip(): void
    {
        $s = ['is_admin' => true, 'admin_via' => 'IP'];
        $this->assertSame('detail.php?id=1', visitor_preview_apply($s, '1', true, false, 'detail.php?id=1'));
        $this->assertFalse($s['is_admin']);
        $this->assertTrue($s['visitor_preview']);
        $this->assertSame('stats.php', visitor_preview_apply($s, '0', true, false, 'stats.php'));
        $this->assertTrue($s['is_admin']);
        $this->assertSame('IP', $s['admin_via']);
        $this->assertArrayNotHasKey('visitor_preview', $s);
    }

    public function testLoginAdminRoundTripFromAnotherNetwork(): void
    {
        $s = ['is_admin' => true, 'admin_via' => 'login'];
        $this->assertSame('detail.php?id=5', visitor_preview_apply($s, '1', false, false, 'detail.php?id=5'));
        $this->assertFalse($s['is_admin']);
        $this->assertSame('login', $s['preview_via']);
        // leaving preview restores the password login, not "IP"
        $this->assertSame('detail.php?id=5', visitor_preview_apply($s, '0', false, false, 'detail.php?id=5'));
        $this->assertTrue($s['is_admin']);
        $this->assertSame('login', $s['admin_via']);
        $this->assertArrayNotHasKey('preview_via', $s);
    }

    public function testAdminPagesStartPreviewOnIndex(): void
    {
        $s = ['is_admin' => true, 'admin_via' => 'login'];
        $this->assertSame('index.php', visitor_preview_apply($s, '1', false, true, 'admin.php'));
    }

    public function testFormerIpAdminSessionAwayFromHomeIsNotEnough(): void
    {
        // is_admin from IP, but the request comes from elsewhere: no switch
        $s = ['is_admin' => true, 'admin_via' => 'IP'];
        $this->assertNull(visitor_preview_apply($s, '1', false, false, 'index.php'));
        $this->assertTrue($s['is_admin']);
    }
}
