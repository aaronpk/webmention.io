<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Tests\Support\IntegrationTestCase;
use Webmention\Tests\Support\Subprocess;
use Webmention\Webmention\Job;
use Webmention\Webmention\Queue;
use Webmention\Webmention\StatusStore;

/**
 * Runs bin/worker as a real process. The jobs here fail before any HTTP
 * request is made (unknown account, target not on the account), so no network
 * is needed.
 */
final class WorkerTest extends IntegrationTestCase
{
    private ?Subprocess $worker = null;

    protected function setUp(): void
    {
        parent::setUp();

        @unlink(self::logFile());
    }

    protected function tearDown(): void
    {
        $this->worker?->stop();
    }

    /** Workers started without --name log here, one line per job. */
    private static function logFile(): string
    {
        return Subprocess::logDir() . '/worker.log';
    }

    private function workerLog(): string
    {
        return (string) @file_get_contents(self::logFile()) . ($this->worker !== null ? $this->worker->output() : '');
    }

    public function testProcessesQueuedJobsAndStopsAfterMaxJobs(): void
    {
        $this->worker = new Subprocess(['php', 'bin/worker', '--max-jobs=2']);

        $this->push('one', accountId: 999);
        $this->push('two', accountId: 999);

        self::assertSame('target_not_found', $this->waitForStatus('one'));
        self::assertSame('target_not_found', $this->waitForStatus('two'));
        self::assertTrue($this->worker->waitForExit(5), 'Worker did not exit after --max-jobs');
        self::assertStringContainsString('Worker stopping after 2 jobs', $this->workerLog());
    }

    public function testSkipsUnreadableQueueEntries(): void
    {
        $this->redis->lPush(Queue::KEY, 'not json');
        $this->worker = new Subprocess(['php', 'bin/worker', '--max-jobs=1']);
        $this->push('after-garbage', accountId: 999);

        self::assertSame('target_not_found', $this->waitForStatus('after-garbage'));
    }

    public function testRetriesAJobWhenTheDatabaseConnectionWasLost(): void
    {
        $account = $this->createAccount('example.com');

        $this->worker = new Subprocess(['php', 'bin/worker', '--max-jobs=2']);

        // The first job opens the worker's database connection.
        $this->push('first', accountId: 999);
        self::assertSame('target_not_found', $this->waitForStatus('first'));

        $ids = $this->db->all(
            "SELECT ID FROM information_schema.PROCESSLIST WHERE USER = ? AND ID <> CONNECTION_ID()",
            [(string) $this->db->value('SELECT SUBSTRING_INDEX(CURRENT_USER(), "@", 1)')],
        );
        self::assertNotEmpty($ids, 'Could not find the worker\'s database connection');
        foreach ($ids as $row) {
            $this->db->pdo()->exec('KILL ' . (int) $row['ID']);
        }

        // Needs the database: the account exists, but not a site for the target.
        $this->push('second', accountId: $account->id, target: 'http://not-on-account.example/post');

        self::assertSame('invalid_target', $this->waitForStatus('second'));
        self::assertStringContainsString('reconnecting and retrying second', $this->workerLog());
    }

    public function testReconnectsWhenRedisDropsTheConnection(): void
    {
        $this->worker = new Subprocess(['php', 'bin/worker', '--max-jobs=1']);
        usleep(700_000); // let it start blocking on the queue

        $this->redis->rawCommand('CLIENT', 'KILL', 'TYPE', 'normal', 'SKIPME', 'yes');

        $this->push('after-drop', accountId: 999);

        self::assertSame('target_not_found', $this->waitForStatus('after-drop', 20));
    }

    public function testStopsOnSigterm(): void
    {
        if (!function_exists('pcntl_signal')) {
            self::markTestSkipped('pcntl is not available');
        }

        $this->worker = new Subprocess(['php', 'bin/worker']);
        usleep(700_000);

        $this->worker->signal(SIGTERM);

        self::assertTrue($this->worker->waitForExit(8), 'Worker did not stop on SIGTERM');
        self::assertStringContainsString('Worker stopping after 0 jobs', $this->workerLog());
    }

    private function push(string $token, int $accountId, string $target = 'http://target.example.com/entry'): void
    {
        $this->service(Queue::class)->push(new Job(
            accountId: $accountId,
            source:    'http://source.example.org/like-of',
            target:    $target,
            token:     $token,
        ));
    }

    private function waitForStatus(string $token, float $seconds = 15): string
    {
        return Subprocess::waitFor(
            fn (): ?string => $this->service(StatusStore::class)->get($token)->status ?? null,
            $seconds,
            "status for job $token\nWorker log:\n" . $this->workerLog(),
        );
    }
}
