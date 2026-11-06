<?php

namespace ESolution\LaravelAccounting\Support;

/**
 * Streaming, order-independent per-month digests used by reporting reconciliation:
 * XOR of per-row SHA-256 values plus per-kind row counts. Rows carry unique ids, so
 * duplicates cannot cancel out.
 */
final class ReportingDigest
{
    private array $digests = [];

    public function add(string $group, string $month, string $kind, string $line): void
    {
        $hash = hash('sha256', $kind.'|'.$line, true);
        $current = $this->digests[$group][$month] ?? ['xor' => str_repeat("\0", 32), 'count' => []];
        $current['xor'] ^= $hash;
        $current['count'][$kind] = ($current['count'][$kind] ?? 0) + 1;
        $this->digests[$group][$month] = $current;
    }

    public function finish(string $group): array
    {
        $result = [];
        foreach ($this->digests[$group] ?? [] as $month => $digest) {
            ksort($digest['count']);
            $result[$month] = bin2hex($digest['xor']).':'.json_encode($digest['count']);
        }

        return $result;
    }
}
