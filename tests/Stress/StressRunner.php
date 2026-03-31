<?php
declare(strict_types=1);

namespace ScandTicket\Tests\Stress;

use ScandTicket\Core\Container;
use ScandTicket\Core\RedisAdapter;
use ScandTicket\Scanner\ScanProcessor;
use ScandTicket\Tests\Helpers\QrFactory;

final class StressRunner
{
    private ScanProcessor $processor;
    private array $results = [];

    public function __construct() { $this->processor = Container::instance()->make(ScanProcessor::class); }

    public function scenarioRateLimit(): void
    {
        $this->header('Rate Limit Stress — 200 scans, 120/min limit');
        $device = QrFactory::device(1);
        $counts = ['accepted' => 0, 'rate_limited' => 0, 'other' => 0];
        $t = microtime(true);
        for ($i = 0; $i < 200; $i++) {
            $r = $this->processor->process(QrFactory::valid(50000 + $i, 100), $device);
            if (is_array($r)) $counts['accepted']++;
            elseif ($r->get_error_code() === 'rate_limited') $counts['rate_limited']++;
            else $counts['other']++;
        }
        $elapsed = round(microtime(true) - $t, 2);
        $this->log("Elapsed: {$elapsed}s | Accepted: {$counts['accepted']} | Rate limited: {$counts['rate_limited']} | Other: {$counts['other']}");
        $this->assert($counts['accepted'] <= 120, "Accepted ≤ 120, got {$counts['accepted']}");
        $this->assert($counts['rate_limited'] >= 80, "Rate limited ≥ 80, got {$counts['rate_limited']}");
    }

    public function scenarioDuplicateStress(): void
    {
        $this->header('Duplicate Stress — 50 scans, same ticket');
        $device = QrFactory::device(2);
        $counts = ['accepted' => 0, 'duplicate_scan' => 0, 'nonce_replayed' => 0, 'other' => 0];
        for ($i = 0; $i < 50; $i++) {
            $r = $this->processor->process(QrFactory::valid(77777, 100), $device);
            if (is_array($r)) $counts['accepted']++;
            else { $c = $r->get_error_code(); $counts[$c] = ($counts[$c] ?? 0) + 1; }
        }
        $this->log("Accepted: {$counts['accepted']} | Duplicate: {$counts['duplicate_scan']} | Replayed: " . ($counts['nonce_replayed'] ?? 0));
        $this->assert($counts['accepted'] === 1, "Exactly 1 accepted, got {$counts['accepted']}");
    }

    public function scenarioMultiDevice(): void
    {
        $this->header('Multi-Device — 5 devices scan same ticket');
        $counts = ['accepted' => 0, 'other' => 0];
        for ($d = 1; $d <= 5; $d++) {
            $r = $this->processor->process(QrFactory::valid(88888, 100), QrFactory::device($d));
            if (is_array($r)) $counts['accepted']++; else $counts['other']++;
        }
        $this->log("Accepted: {$counts['accepted']} | Other: {$counts['other']}");
        $this->assert($counts['accepted'] === 5, "All 5 accepted with default weights, got {$counts['accepted']}");
    }

    public function scenarioSustainedLoad(): void
    {
        $this->header('Sustained Load — 1000 scans across 5 devices');
        $t = microtime(true); $accepted = 0; $errors = 0;
        for ($i = 0; $i < 1000; $i++) {
            $r = $this->processor->process(QrFactory::valid(60000 + $i, 100), QrFactory::device(($i % 5) + 1));
            if (is_array($r)) $accepted++; else $errors++;
        }
        $elapsed = round(microtime(true) - $t, 2);
        $rate = round(1000 / $elapsed, 1);
        $this->log("Elapsed: {$elapsed}s | Rate: {$rate}/s | Accepted: {$accepted} | Errors: {$errors}");
        $this->assert($accepted === 1000, "All 1000 accepted, got {$accepted}");
        $this->assert($elapsed < 30, "Complete in <30s, took {$elapsed}s");
    }

    public function runAll(): int
    {
        foreach (['scenarioRateLimit', 'scenarioDuplicateStress', 'scenarioMultiDevice', 'scenarioSustainedLoad'] as $m) {
            RedisAdapter::connection()?->flushDb();
            try { $this->$m(); } catch (\Throwable $e) { $this->log("EXCEPTION: {$e->getMessage()}"); }
            $this->log('');
        }
        $this->header('SUMMARY');
        $passed = 0; $failed = 0;
        foreach ($this->results as $r) { echo ($r['passed'] ? '  ✓ ' : '  ✗ FAIL: ') . $r['message'] . "\n"; $r['passed'] ? $passed++ : $failed++; }
        echo "\nPassed: {$passed} | Failed: {$failed}\n";
        return $failed > 0 ? 1 : 0;
    }

    public function runScenario(string $name): int
    {
        RedisAdapter::connection()?->flushDb();
        $method = 'scenario' . str_replace('_', '', ucwords($name, '_'));
        if (!method_exists($this, $method)) { echo "Unknown scenario: {$name}\n"; return 1; }
        $this->$method();
        return count(array_filter($this->results, fn($r) => !$r['passed'])) > 0 ? 1 : 0;
    }

    private function assert(bool $cond, string $msg): void { $this->results[] = ['message' => $msg, 'passed' => $cond]; echo ($cond ? '  ✓ ' : '  ✗ FAIL: ') . $msg . "\n"; }
    private function header(string $t): void { echo "\n" . str_repeat('─', strlen($t) + 4) . "\n  {$t}\n" . str_repeat('─', strlen($t) + 4) . "\n"; }
    private function log(string $t): void { echo "{$t}\n"; }
}