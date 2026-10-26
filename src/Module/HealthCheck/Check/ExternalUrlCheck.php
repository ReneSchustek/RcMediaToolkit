<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Check;

use Doctrine\DBAL\Connection;
use Generator;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Dto\Finding;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Throwable;

/**
 * Validiert externe Bild-URLs in CMS-Slot- und Translation-Spalten via HEAD-Requests.
 *
 * **Opt-in.** Erzeugt Netzwerk-Last auf Drittservern — niemals per Default aktivieren.
 *
 * Sicherheits-Härte (SSRF-Schutz):
 *  - Schema-Whitelist: nur http/https.
 *  - Host-Blocklist: localhost, private RFC1918, AWS-Metadata (169.254.169.254), link-local.
 *  - Optionale Host-Allowlist über Plugin-Config.
 *  - Max-Redirects = 0 — keine Redirect-Chain in interne Hosts.
 *  - Timeout 5 s pro Request, 10 parallele Requests via Pool.
 */
final class ExternalUrlCheck implements MediaHealthCheckInterface
{
    private const URL_REGEX = '/https?:\/\/[^\s"\'<>)]+\.(?:jpe?g|png|webp|svg|gif|mp4)/i';
    private const CONCURRENCY = 10;
    private const TIMEOUT_SECONDS = 5;
    private const MAX_URLS_PER_RUN = 500;

    /**
     * Der Client kommt von außen herein, damit sich der Lauf gegen vorbereitete Antworten
     * prüfen lässt. Ohne Übergabe entsteht genau der Client, der hier immer entstanden ist —
     * die Verdrahtung im Betrieb bleibt unverändert.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly SystemConfigService $systemConfigService,
        private readonly ?ClientInterface $httpClient = null,
    ) {
    }

    public function type(): string
    {
        return 'external_url';
    }

    public function configKey(): string
    {
        return 'RcMediaToolkit.config.healthCheckExternalUrls';
    }

    public function run(): Generator
    {
        if (!class_exists(Client::class)) {
            return;
        }

        $allowlist = $this->parseHostAllowlist();
        $urlsToCheck = $this->collectCandidateUrls($allowlist);

        if ($urlsToCheck === []) {
            return;
        }

        $findings = [];
        $client = $this->httpClient ?? new Client([
            'handler' => HandlerStack::create(new CurlMultiHandler()),
            'timeout' => self::TIMEOUT_SECONDS,
            'connect_timeout' => self::TIMEOUT_SECONDS,
            'allow_redirects' => false,
            'http_errors' => false,
            'headers' => [
                'User-Agent' => 'RcMediaToolkit-HealthCheck/0.1',
                'Accept' => '*/*',
            ],
        ]);

        $requests = static function () use ($urlsToCheck): Generator {
            foreach ($urlsToCheck as $url => $_sources) {
                yield new Request('HEAD', $url);
            }
        };

        $pool = new Pool($client, $requests(), [
            'concurrency' => self::CONCURRENCY,
            'fulfilled' => function (ResponseInterface $response, int $index) use ($urlsToCheck, &$findings): void {
                $url = array_keys($urlsToCheck)[$index] ?? null;
                if ($url === null) {
                    return;
                }
                $status = $response->getStatusCode();
                if ($status >= 400) {
                    foreach ($urlsToCheck[$url] as $source) {
                        $findings[] = new Finding(
                            checkType: $this->type(),
                            severity: $status >= 500 ? Finding::SEVERITY_WARNING : Finding::SEVERITY_CRITICAL,
                            sourceType: $source['sourceType'],
                            sourceReference: $source['sourceReference'],
                            urlPath: $url,
                            errorDetail: ['httpStatus' => $status],
                        );
                    }
                }
            },
            'rejected' => function (GuzzleException|Throwable $reason, int $index) use ($urlsToCheck, &$findings): void {
                $url = array_keys($urlsToCheck)[$index] ?? null;
                if ($url === null) {
                    return;
                }
                foreach ($urlsToCheck[$url] as $source) {
                    $findings[] = new Finding(
                        checkType: $this->type(),
                        severity: Finding::SEVERITY_WARNING,
                        sourceType: $source['sourceType'],
                        sourceReference: $source['sourceReference'],
                        urlPath: $url,
                        errorDetail: [
                            'transportError' => $reason->getMessage(),
                        ],
                    );
                }
            },
        ]);

        try {
            $pool->promise()->wait();
        } catch (Throwable) {
            // Pool-Fehler werden bereits pro Request über `rejected` erfasst.
        }

        foreach ($findings as $finding) {
            yield $finding;
        }
    }

    /**
     * Sammelt URL → Liste von Quellen (CMS-Slot oder Translation).
     *
     * @param list<string>|null $allowlist
     * @return array<string, list<array{sourceType: string, sourceReference: string}>>
     */
    private function collectCandidateUrls(?array $allowlist): array
    {
        $urls = [];
        $this->collectFromColumn(
            'cms_slot_translation',
            'config',
            'cms_slot_id',
            'cms_slot',
            $urls,
        );
        $this->collectFromColumn(
            'product_translation',
            'description',
            'product_id',
            'product',
            $urls,
        );
        $this->collectFromColumn(
            'category_translation',
            'description',
            'category_id',
            'category',
            $urls,
        );

        // Hostname-Filter
        $filtered = [];
        foreach ($urls as $url => $sources) {
            if (count($filtered) >= self::MAX_URLS_PER_RUN) {
                break;
            }
            if (!$this->isUrlSafe($url, $allowlist)) {
                continue;
            }
            $filtered[$url] = $sources;
        }
        return $filtered;
    }

    /**
     * @param array<string, list<array{sourceType: string, sourceReference: string}>> $urls
     */
    private function collectFromColumn(
        string $table,
        string $column,
        string $idColumn,
        string $sourceType,
        array &$urls,
    ): void {
        if (!$this->tableExists($table)) {
            return;
        }

        $sql = sprintf(
            'SELECT LOWER(HEX(%s)) AS source_id, %s AS payload FROM %s WHERE %s IS NOT NULL LIMIT 5000',
            $this->connection->quoteIdentifier($idColumn),
            $this->connection->quoteIdentifier($column),
            $this->connection->quoteIdentifier($table),
            $this->connection->quoteIdentifier($column),
        );
        $rows = $this->connection->fetchAllAssociative($sql);

        foreach ($rows as $row) {
            $payload = (string) ($row['payload'] ?? '');
            if ($payload === '' || preg_match_all(self::URL_REGEX, $payload, $matches) === false) {
                continue;
            }
            foreach (array_unique($matches[0]) as $url) {
                $urls[$url][] = [
                    'sourceType' => $sourceType,
                    'sourceReference' => $sourceType . ':' . (string) $row['source_id'],
                ];
            }
        }
    }

    /**
     * @param list<string>|null $allowlist
     */
    private function isUrlSafe(string $url, ?array $allowlist): bool
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        $host = strtolower((string) $parts['host']);
        // parse_url liefert IPv6-Hosts mit Brackets ("[::1]") — Brackets entfernen, damit
        // filter_var() und strikte Listen-Vergleiche greifen.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        if ($this->isBlockedHost($host)) {
            return false;
        }

        // DNS-Rebinding-Härte: Hostnamen vorab auflösen und alle resultierenden IPs
        // gegen die Blocklist prüfen. Wenn auch nur eine intern ist, URL ablehnen.
        // Damit verhindern wir, dass externe Domains auf interne IPs zeigen.
        if (filter_var($host, \FILTER_VALIDATE_IP) === false) {
            foreach ($this->resolveHostToIps($host) as $ip) {
                if ($this->isPrivateOrSpecialIp($ip)) {
                    return false;
                }
            }
        }

        if ($allowlist !== null && $allowlist !== []) {
            foreach ($allowlist as $allowed) {
                if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                    return true;
                }
            }
            return false;
        }
        return true;
    }

    /**
     * @return list<string>
     */
    private function resolveHostToIps(string $host): array
    {
        $ips = [];
        $v4 = gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }
        if (function_exists('dns_get_record')) {
            $aaaa = @dns_get_record($host, \DNS_AAAA);
            if (is_array($aaaa)) {
                foreach ($aaaa as $record) {
                    if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }
        return array_values(array_unique($ips));
    }

    private function isBlockedHost(string $host): bool
    {
        if (in_array($host, ['localhost', '0.0.0.0', '::1', 'broadcasthost'], true)) {
            return true;
        }
        if (filter_var($host, \FILTER_VALIDATE_IP) !== false) {
            return $this->isPrivateOrSpecialIp($host);
        }
        if (str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return true;
        }
        return false;
    }

    private function isPrivateOrSpecialIp(string $ip): bool
    {
        // IPv4-mapped IPv6 (::ffff:127.0.0.1) zuerst gegen die V4-Hälfte prüfen.
        // Reihenfolge wichtig: PHP-FILTER_VALIDATE_IP-Verhalten für IPv4-mapped-IPv6 driftet zwischen
        // PHP-Versionen — daher direkt auf die V4-Komponente runtergehen, statt dem Filter zu vertrauen.
        if (str_starts_with($ip, '::ffff:') || str_starts_with($ip, '::FFFF:')) {
            $v4 = substr($ip, 7);
            if (filter_var($v4, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) !== false) {
                return $this->isPrivateOrSpecialIp($v4);
            }
        }

        $blocked = filter_var(
            $ip,
            \FILTER_VALIDATE_IP,
            \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE,
        );
        if ($blocked === false) {
            return true;
        }
        // Carrier-Grade NAT (100.64.0.0/10) — nicht in FILTER_FLAG_NO_RES_RANGE.
        if ($this->isInIpv4Range($ip, '100.64.0.0', 10)) {
            return true;
        }
        return false;
    }

    private function isInIpv4Range(string $ip, string $networkBase, int $bits): bool
    {
        if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) === false) {
            return false;
        }
        $ipLong = ip2long($ip);
        $baseLong = ip2long($networkBase);
        if ($ipLong === false || $baseLong === false) {
            return false;
        }
        $mask = -1 << (32 - $bits);
        return ($ipLong & $mask) === ($baseLong & $mask);
    }

    /**
     * @return list<string>|null Allowlist aus Config oder null wenn ungenutzt
     */
    private function parseHostAllowlist(): ?array
    {
        $raw = $this->systemConfigService->get('RcMediaToolkit.config.healthExternalAllowlist');
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $hosts = array_map('strtolower', array_map('trim', explode(',', $raw)));
        return array_values(array_filter($hosts, static fn (string $h): bool => $h !== ''));
    }

    private function tableExists(string $table): bool
    {
        return $this->connection->createSchemaManager()->tablesExist([$table]);
    }
}
