<?php
/**
 * SMSTunnel for WHMCS.
 *
 * @author    NARBOWEB SRL (https://smstunnel.io)
 * @copyright 2026 NARBOWEB SRL
 * @license   MIT
 */

namespace SmsTunnel\Whmcs\Store;

use SmsTunnel\Whmcs\Whmcs\Platform;
use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

/**
 * Every text the module sent, tried to send or skipped, in mod_smstunnel_log. The daily cron removes
 * rows older than the days set under Setup (90 by default).
 *
 * The request (the number and the words) is kept only while the text may still be retried; once it
 * is sent, waiting for the phone, failed or skipped, the row keeps the number and no words. Sign-in
 * codes never keep their words.
 */
class TextLog
{
    const SENDING = 'sending';
    const SENT = 'sent';
    /** SMSTunnel has the text and the phone sends it when it is online: normal, not an error. */
    const QUEUED = 'queued';
    /** Not sent yet because of a network error, a 5xx or a 429: the cron tries again. */
    const RETRY = 'retry';
    const FAILED = 'failed';
    const SKIPPED = 'skipped';

    const STATUSES = array(self::SENDING, self::SENT, self::QUEUED, self::RETRY, self::FAILED, self::SKIPPED);

    /** A row left in "sending" this long belongs to a request that died midway: the cron retries it. */
    const STALE_SECONDS = 600;

    /** @var Platform */
    private $platform;

    public function __construct(Platform $platform)
    {
        $this->platform = $platform;
    }

    /**
     * Adds a row.
     *
     * @param array<string, mixed> $row the columns (event, audience, recipient, status...)
     *
     * @return int the row's id, or 0 when a row with the same dedupe_key exists (the same text again)
     */
    public function create(array $row): int
    {
        $now = $this->platform->nowSql();
        $data = array(
            'created_at' => $now,
            'updated_at' => $now,
            'event' => Schema::safeText((string) $row['event'], 40),
            'audience' => Schema::safeText((string) $row['audience'], 10),
            'client_id' => isset($row['client_id']) ? max(0, (int) $row['client_id']) : 0,
            'related_type' => isset($row['related_type']) ? Schema::safeText((string) $row['related_type'], 20) : '',
            'related_id' => isset($row['related_id']) ? max(0, (int) $row['related_id']) : 0,
            'recipient' => isset($row['recipient']) ? Schema::safeText((string) $row['recipient'], 40) : '',
            'status' => (string) $row['status'],
            'message_id' => null,
            'error' => isset($row['error']) && $row['error'] !== '' ? Schema::safeText((string) $row['error'], 255) : null,
            'attempts' => 0,
            'first_attempt_at' => null,
            'next_attempt_at' => null,
            'endpoint' => isset($row['endpoint']) ? (string) $row['endpoint'] : 'send',
            'idempotency_key' => isset($row['idempotency_key']) ? (string) $row['idempotency_key'] : null,
            'request' => isset($row['request']) ? (string) $row['request'] : null,
            'dedupe_key' => isset($row['dedupe_key']) && $row['dedupe_key'] !== '' ? substr((string) $row['dedupe_key'], 0, 120) : null,
        );
        if ($data['dedupe_key'] !== null && Capsule::table(Schema::LOG)->where('dedupe_key', $data['dedupe_key'])->exists()) {
            return 0;
        }
        try {
            return (int) Capsule::table(Schema::LOG)->insertGetId($data);
        } catch (\Exception $e) {
            // Two requests with the same text at the same moment: the unique dedupe_key lets one through.
            if ($data['dedupe_key'] !== null && Capsule::table(Schema::LOG)->where('dedupe_key', $data['dedupe_key'])->exists()) {
                return 0;
            }
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id)
    {
        $row = Capsule::table(Schema::LOG)->where('id', $id)->first();

        return $row !== null ? (array) $row : null;
    }

    /**
     * Takes a text for one attempt: status "sending", one more attempt counted. False when another
     * request took it meanwhile (two cron runs at once), so it is never attempted twice at the same time.
     *
     * @param bool $fresh true for the first attempt of a row just created ("sending" already)
     */
    public function claim(int $id, bool $fresh = false): bool
    {
        $row = $this->find($id);
        if ($row === null) {
            return false;
        }
        $now = $this->platform->nowSql();
        $query = Capsule::table(Schema::LOG)->where('id', $id)->where('attempts', (int) $row['attempts']);
        if ($fresh) {
            $query->where('status', self::SENDING);
        } else {
            $stale = $this->platform->nowSql(-self::STALE_SECONDS);
            $query->where(function ($where) use ($stale) {
                $where->where('status', TextLog::RETRY)
                    ->orWhere(function ($inner) use ($stale) {
                        $inner->where('status', TextLog::SENDING)->where('updated_at', '<', $stale);
                    });
            });
        }
        $affected = $query->update(array(
            'status' => self::SENDING,
            'attempts' => (int) $row['attempts'] + 1,
            'first_attempt_at' => $row['first_attempt_at'] !== null ? $row['first_attempt_at'] : $now,
            'updated_at' => $now,
        ));

        return $affected > 0;
    }

    /**
     * Records how a text ended. Every final state drops the request (the number stays in recipient).
     */
    public function finish(int $id, string $status, string $messageId = '', string $error = '')
    {
        Capsule::table(Schema::LOG)->where('id', $id)->update(array(
            'status' => $status,
            'message_id' => $messageId !== '' ? substr($messageId, 0, 64) : null,
            'error' => $error !== '' ? Schema::safeText($error, 255) : null,
            'next_attempt_at' => null,
            'request' => null,
            'updated_at' => $this->platform->nowSql(),
        ));
    }

    /**
     * Leaves a text for the cron to try again at $at (Unix time), with the same request and key.
     */
    public function retryAt(int $id, int $at, string $error)
    {
        Capsule::table(Schema::LOG)->where('id', $id)->update(array(
            'status' => self::RETRY,
            'error' => $error !== '' ? Schema::safeText($error, 255) : null,
            'next_attempt_at' => date('Y-m-d H:i:s', $at),
            'updated_at' => $this->platform->nowSql(),
        ));
    }

    /**
     * Texts the cron should try now: retries that are due, and texts left "sending" by a request that
     * died midway. Oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function due(int $limit): array
    {
        $now = $this->platform->nowSql();
        $stale = $this->platform->nowSql(-self::STALE_SECONDS);
        $rows = Capsule::table(Schema::LOG)
            ->where(function ($where) use ($now, $stale) {
                $where->where(function ($inner) use ($now) {
                    $inner->where('status', TextLog::RETRY)->where('next_attempt_at', '<=', $now);
                })->orWhere(function ($inner) use ($stale) {
                    $inner->where('status', TextLog::SENDING)->where('updated_at', '<', $stale)->whereNotNull('request');
                });
            })
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        $due = array();
        foreach ($rows as $row) {
            $due[] = (array) $row;
        }

        return $due;
    }

    /**
     * One page of the log, newest first.
     *
     * @param array{status?: string, event?: string, client?: int} $filters
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function page(array $filters, int $page, int $perPage): array
    {
        $query = Capsule::table(Schema::LOG);
        if (isset($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['event']) && is_string($filters['event']) && $filters['event'] !== '') {
            $query->where('event', $filters['event']);
        }
        if (isset($filters['client']) && (int) $filters['client'] > 0) {
            $query->where('client_id', (int) $filters['client']);
        }
        $total = (int) (clone $query)->count();
        $rows = array();
        foreach ($query->orderBy('id', 'desc')->offset(max(0, $page - 1) * $perPage)->limit($perPage)->get() as $row) {
            $rows[] = (array) $row;
        }

        return array('rows' => $rows, 'total' => $total);
    }

    /**
     * Removes rows older than $days days (texts still waiting for a retry stay).
     *
     * @return int how many rows went
     */
    public function purge(int $days): int
    {
        $cutoff = $this->platform->nowSql(-max(1, $days) * 86400);

        return (int) Capsule::table(Schema::LOG)
            ->where('created_at', '<', $cutoff)
            ->whereNotIn('status', array(self::RETRY, self::SENDING))
            ->delete();
    }

    /**
     * How many texts are in each state.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach (Capsule::table(Schema::LOG)->select('status', Capsule::raw('count(*) as total'))->groupBy('status')->get() as $row) {
            $counts[(string) $row->status] = (int) $row->total;
        }

        return $counts;
    }
}
