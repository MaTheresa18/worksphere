<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_security_headers_are_present()
    {
        $response = $this->get('/');

        $response->assertStatus(200);

        // Standard Security Headers
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-XSS-Protection', '1; mode=block');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        
        // Content Security Policy
        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp, 'CSP Header is missing');

        // Check for nonce presence in header
        $this->assertStringContainsString("'nonce-", $csp);
        
        // Parse nonce from header to check against body
        preg_match("/'nonce-([^']+)'/", $csp, $matches);
        $nonce = $matches[1] ?? null;
        $this->assertNotNull($nonce, 'Could not extract nonce from CSP header');

        // Check that body contains script with matching nonce
        $content = $response->getContent();
        $this->assertStringContainsString('<script nonce="' . $nonce . '"', $content, 'Script tag with matching nonce not found in response body');
    }

    public function test_csp_includes_correct_directives_in_production()
    {
        // Force production env
        app()->detectEnvironment(fn() => 'production');

        $response = $this->get('/');
        
        $csp = $response->headers->get('Content-Security-Policy');
        
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline' https://fonts.bunny.net", $csp);
        // data: added
        $this->assertStringContainsString("font-src 'self' https://fonts.bunny.net data:", $csp);
        $this->assertStringContainsString("img-src 'self' data: https:", $csp);
        // ws: wss: added
        $this->assertStringContainsString("connect-src 'self' ws: wss:", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    public function test_csp_includes_vite_origin_in_local()
    {
        // Force local env
        app()->detectEnvironment(fn() => 'local');
        
        // Mock hot file
        $path = public_path('hot');
        file_put_contents($path, 'http://localhost:5173');

        try {
            $response = $this->get('/');
            $csp = $response->headers->get('Content-Security-Policy');

            $this->assertStringContainsString("http://localhost:5173", $csp);
            $this->assertStringContainsString("ws://localhost:5173", $csp);
            $this->assertStringContainsString("'unsafe-eval'", $csp);
        } finally {
            unlink($path);
        }
    }
}
