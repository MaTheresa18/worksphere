<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SecureOpenGraph
{
    /**
     * Private/Internal IP ranges to block.
     */
    protected array $blockedRanges = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '255.255.255.255/32',
    ];

    /**
     * Fetch OpenGraph data securely.
     */
    public function fetch(string $url): array
    {
        $maxRedirects = 5;
        $currentUrl = $url;
        
        for ($i = 0; $i < $maxRedirects; $i++) {
            if (!$this->validateUrl($currentUrl)) {
                throw new \Exception("Invalid or prohibited URL: " . $currentUrl);
            }

            $curl = curl_init($currentUrl);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false); // Manual redirect handling
            curl_setopt($curl, CURLOPT_TIMEOUT, 10);
            curl_setopt($curl, CURLOPT_MAXREDIRS, 0);
            curl_setopt($curl, CURLOPT_USERAGENT, 'WorkSphere Link Crawler / 1.0');
            
            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $redirectUrl = curl_getinfo($curl, CURLINFO_REDIRECT_URL);
            curl_close($curl);

            if ($httpCode >= 300 && $httpCode < 400 && $redirectUrl) {
                $currentUrl = $this->resolveRedirect($currentUrl, $redirectUrl);
                continue;
            }

            if ($httpCode !== 200) {
                throw new \Exception("Failed to fetch URL, HTTP Code: " . $httpCode);
            }

            return $this->parseOpenGraph($response, $currentUrl);
        }

        throw new \Exception("Too many redirects");
    }

    /**
     * Validate URL and its resolved IP address.
     */
    protected function validateUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!$parts || !isset($parts['host']) || !in_array($parts['scheme'], ['http', 'https'])) {
            return false;
        }

        $host = $parts['host'];
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);

        // Resolve IP
        $ips = gethostbynamel($host);
        if (!$ips) {
            return false;
        }

        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip)) {
                Log::warning("SSRF Attempt Blocked: Host {$host} resolved to blocked IP {$ip}");
                return false;
            }
        }

        return true;
    }

    /**
     * Check if an IP address is in a blocked range.
     */
    protected function isBlockedIp(string $ip): bool
    {
        foreach ($this->blockedRanges as $range) {
            if ($this->ipInRage($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if IP is in CIDR range.
     */
    protected function ipInRage(string $ip, string $range): bool
    {
        if (strpos($range, '/') === false) {
            $range .= '/32';
        }
        
        list($subnet, $bits) = explode('/', $range);
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - $bits);
        $subnet &= $mask;
        
        return ($ip & $mask) == $subnet;
    }

    /**
     * Resolve relative redirect URLs.
     */
    protected function resolveRedirect(string $baseUrl, string $redirectUrl): string
    {
        if (parse_url($redirectUrl, PHP_URL_SCHEME) != '') {
            return $redirectUrl;
        }

        $parts = parse_url($baseUrl);
        $scheme = $parts['scheme'];
        $host = $parts['host'];
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        if (strpos($redirectUrl, '//') === 0) {
            return $scheme . ':' . $redirectUrl;
        }

        if (strpos($redirectUrl, '/') === 0) {
            return $scheme . '://' . $host . $port . $redirectUrl;
        }

        $path = isset($parts['path']) ? $parts['path'] : '/';
        $path = substr($path, 0, strrpos($path, '/') + 1);
        
        return $scheme . '://' . $host . $port . $path . $redirectUrl;
    }

    /**
     * Parse OpenGraph tags from HTML.
     */
    protected function parseOpenGraph(string $html, string $url): array
    {
        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML($html);
        libxml_clear_errors();

        $tags = [
            'title' => '',
            'description' => '',
            'image' => '',
            'url' => $url,
            'site_name' => '',
        ];

        // Try OpenGraph tags first
        $metas = $doc->getElementsByTagName('meta');
        foreach ($metas as $meta) {
            $property = $meta->getAttribute('property');
            $content = $meta->getAttribute('content');

            if (strpos($property, 'og:') === 0) {
                $key = substr($property, 3);
                if (array_key_exists($key, $tags)) {
                    $tags[$key] = $content;
                }
            }

            // Fallback for some common names
            $name = $meta->getAttribute('name');
            if (!$tags['description'] && $name === 'description') {
                $tags['description'] = $content;
            }
        }

        // Fallback for title
        if (!$tags['title']) {
            $titleNodes = $doc->getElementsByTagName('title');
            if ($titleNodes->length > 0) {
                $tags['title'] = $titleNodes->item(0)->textContent;
            }
        }

        return $tags;
    }
}
