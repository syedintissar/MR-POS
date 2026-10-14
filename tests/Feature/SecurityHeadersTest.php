<?php

namespace Tests\Feature;

use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use ActsAsRoles;
    use RefreshesSeededDatabase;

    public function test_every_page_carries_the_safety_headers_and_loads_nothing_from_other_sites(): void
    {
        foreach (['/login', null] as $uri) {
            if ($uri === null) {
                $this->as('Manager');
                $uri = '/';
            }
            $r = $this->get($uri)->assertOk();
            $csp = $r->headers->get('Content-Security-Policy');
            $this->assertStringContainsString("default-src 'self'", $csp);
            $this->assertStringContainsString("frame-ancestors 'self'", $csp);
            $this->assertStringContainsString("object-src 'none'", $csp);
            $this->assertStringNotContainsString('http://', $csp);
            $this->assertStringNotContainsString('https://', $csp);
            $r->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('Referrer-Policy', 'same-origin');
        }
    }

    public function test_no_page_asks_the_browser_to_fetch_anything_from_the_internet(): void
    {
        $this->as('Manager');
        foreach (['/', '/reports', '/sale-returns', '/products', '/admin/backups'] as $uri) {
            $html = $this->get($uri)->getContent();
            $this->assertDoesNotMatchRegularExpression('#(src|href)="https?://(?!localhost|127\.0\.0\.1)#i', (string) $html, "{$uri} links to another site");
        }
    }
}
