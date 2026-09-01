<?php
declare(strict_types=1);

namespace App\Controller;

use App\Util\Csp as CspUtil;

class Csp
{
    public function report(): string
    {
        http_response_code(204);

        if (!CspUtil::enabled() || strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            return '';
        }

        $raw = (string)file_get_contents('php://input', false, null, 0, CspUtil::maxBodyBytes() + 1);
        if ($raw === '' || strlen($raw) > CspUtil::maxBodyBytes()) {
            return '';
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return '';
        }

        foreach (self::extract($payload) as $report) {
            CspUtil::record($report);
        }

        return '';
    }

    private static function extract(array $payload): array
    {
        if (isset($payload['csp-report']) && is_array($payload['csp-report'])) {
            return [$payload['csp-report']];
        }

        $out = [];
        foreach ($payload as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (isset($entry['body']) && is_array($entry['body'])) {
                $out[] = $entry['body'];
            } elseif (isset($entry['blocked-uri']) || isset($entry['effective-directive'])) {
                $out[] = $entry;
            }
            if (count($out) >= 20) {
                break;
            }
        }

        if ($out === [] && (isset($payload['blocked-uri']) || isset($payload['effective-directive']))) {
            $out[] = $payload;
        }

        return $out;
    }
}
