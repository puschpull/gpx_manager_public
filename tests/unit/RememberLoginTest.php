<?php
declare(strict_types=1);

namespace GpxManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/remember_login.php';
require_once __DIR__ . '/../../includes/visitor_preview.php';

/**
 * "Remember me" token (S2, 25 Nov 2026) and the preview exit rule:
 * admin rights come back only after a password login or from an allowed IP.
 */
final class RememberLoginTest extends TestCase
{
    public function testValidTokenAccepted(): void
    {
        if (\ADMIN_PASS_HASH === '') {
            $this->markTestSkipped('ADMIN_PASS_HASH not set');
        }
        $this->assertTrue(remember_token_valid(remember_token_create(time() + 3600)));
    }

    public function testExpiredTokenRejected(): void
    {
        $this->assertFalse(remember_token_valid(remember_token_create(time() - 10)));
    }

    public function testTamperedTokenRejected(): void
    {
        $t = remember_token_create(time() + 3600);
        [$exp, $mac] = explode('.', $t);
        $this->assertFalse(remember_token_valid(($exp + 86400 * 365) . '.' . $mac), 'extended expiry');
        $this->assertFalse(remember_token_valid($exp . '.' . str_repeat('0', 64)), 'forged mac');
        $this->assertFalse(remember_token_valid('garbage'));
        $this->assertFalse(remember_token_valid(''));
    }

    public function testIpPreviewCannotRestoreAdminFromOutside(): void
    {
        // Preview started by an IP admin, the request now comes from a non-admin IP
        $s = ['is_admin' => false, 'visitor_preview' => true, 'preview_via' => 'IP'];
        $this->assertSame('index.php', visitor_preview_apply($s, '0', false, false, 'index.php'));
        $this->assertArrayNotHasKey('is_admin', $s);
        $this->assertArrayNotHasKey('visitor_preview', $s);
    }
}
