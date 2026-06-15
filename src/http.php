<?php

declare(strict_types=1);

function fuelauHttpBuildUrl(string $baseUrl, array $query = []): string
{
    $query = array_filter(
        $query,
        static fn (mixed $value): bool => $value !== null && $value !== ''
    );

    if ($query === []) {
        return $baseUrl;
    }

    return $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . http_build_query($query);
}

function fuelauHttpJsonRequest(string $url, array $headers = [], int $timeout = 20): array
{
    $headerLines = ["Accept: */*", "User-Agent: FuelAU/0.1"];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException("HTTP request failed for {$url}");
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_USERAGENT => 'FuelAU/0.1',
        ]);

        $body = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($body === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new RuntimeException($error !== '' ? "HTTP request failed for {$url}: {$error}" : "HTTP request failed for {$url}");
        }

        curl_close($curl);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headerLines),
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $statusLine = $responseHeaders[0] ?? 'HTTP/1.1 500 Internal Server Error';
        preg_match('/\s(\d{3})\s/', $statusLine, $matches);
        $statusCode = (int) ($matches[1] ?? 500);

        if ($body === false) {
            throw new RuntimeException("HTTP request failed for {$url}");
        }
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Invalid JSON response from {$url}");
    }

    if ($statusCode >= 400) {
        $message = (string) ($decoded['message'] ?? $decoded['error'] ?? 'Upstream request failed');
        throw new RuntimeException("HTTP {$statusCode} from {$url}: {$message}");
    }

    return $decoded;
}
