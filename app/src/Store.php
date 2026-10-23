<?php
declare(strict_types=1);

/**
 * SQLite storage for conversation history, handoff pauses and webhook de-duplication.
 * The database file lives in app/data/bot.sqlite and is created automatically.
 */
final class Store
{
    private static ?PDO $pdo = null;

    public static function db(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO('sqlite:' . DATA_DIR . '/bot.sqlite', null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::$pdo->exec('PRAGMA busy_timeout = 5000');
            self::migrate(self::$pdo);
        }
        return self::$pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS conversations (
                id           TEXT PRIMARY KEY,
                channel      TEXT NOT NULL,
                user_id      TEXT NOT NULL,
                paused_until INTEGER NOT NULL DEFAULT 0,
                handoff_note TEXT,
                created_at   INTEGER NOT NULL,
                updated_at   INTEGER NOT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS messages (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                conversation_id TEXT NOT NULL,
                role            TEXT NOT NULL,
                content         TEXT NOT NULL,
                created_at      INTEGER NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_messages_conv ON messages (conversation_id, id)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS conv_state (
                conversation_id TEXT NOT NULL,
                k               TEXT NOT NULL,
                v               TEXT NOT NULL,
                updated_at      INTEGER NOT NULL,
                PRIMARY KEY (conversation_id, k)
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS processed_events (
                event_id   TEXT PRIMARY KEY,
                created_at INTEGER NOT NULL
            )'
        );
    }

    public static function conversationId(string $channel, string $userId): string
    {
        return $channel . ':' . $userId;
    }

    public static function touchConversation(string $channel, string $userId): string
    {
        $id  = self::conversationId($channel, $userId);
        $now = time();
        $stmt = self::db()->prepare(
            'INSERT INTO conversations (id, channel, user_id, created_at, updated_at)
             VALUES (:id, :channel, :user_id, :now, :now)
             ON CONFLICT(id) DO UPDATE SET updated_at = :now'
        );
        $stmt->execute(['id' => $id, 'channel' => $channel, 'user_id' => $userId, 'now' => $now]);
        return $id;
    }

    public static function addMessage(string $conversationId, string $role, string $content): void
    {
        $stmt = self::db()->prepare(
            'INSERT INTO messages (conversation_id, role, content, created_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$conversationId, $role, $content, time()]);
    }

    /**
     * Most recent messages, oldest first.
     * @return list<array{role:string, content:string}>
     */
    public static function history(string $conversationId, int $limit): array
    {
        $stmt = self::db()->prepare(
            'SELECT role, content FROM messages WHERE conversation_id = ? ORDER BY id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $conversationId);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_reverse($stmt->fetchAll());
    }

    public static function isPaused(string $conversationId): bool
    {
        $stmt = self::db()->prepare('SELECT paused_until FROM conversations WHERE id = ?');
        $stmt->execute([$conversationId]);
        return (int) $stmt->fetchColumn() > time();
    }

    public static function pause(string $conversationId, int $hours, ?string $note = null): void
    {
        $stmt = self::db()->prepare('UPDATE conversations SET paused_until = ?, handoff_note = ? WHERE id = ?');
        $stmt->execute([time() + $hours * 3600, $note, $conversationId]);
    }

    public static function resume(string $conversationId): void
    {
        $stmt = self::db()->prepare('UPDATE conversations SET paused_until = 0 WHERE id = ?');
        $stmt->execute([$conversationId]);
    }

    /**
     * Returns false if the event was already handled (Meta retries deliveries).
     */
    public static function markEventProcessed(string $eventId): bool
    {
        $db = self::db();
        if (random_int(1, 200) === 1) {
            $db->prepare('DELETE FROM processed_events WHERE created_at < ?')->execute([time() - 7 * 86400]);
        }
        $stmt = $db->prepare('INSERT OR IGNORE INTO processed_events (event_id, created_at) VALUES (?, ?)');
        $stmt->execute([$eventId, time()]);
        return $stmt->rowCount() === 1;
    }

    /** Small per-conversation values, e.g. the order waiting for the customer's confirmation. */
    public static function setState(string $conversationId, string $key, ?array $value): void
    {
        if ($value === null) {
            self::db()->prepare('DELETE FROM conv_state WHERE conversation_id = ? AND k = ?')->execute([$conversationId, $key]);
            return;
        }
        $stmt = self::db()->prepare(
            'INSERT INTO conv_state (conversation_id, k, v, updated_at) VALUES (?, ?, ?, ?)
             ON CONFLICT(conversation_id, k) DO UPDATE SET v = excluded.v, updated_at = excluded.updated_at'
        );
        $stmt->execute([$conversationId, $key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), time()]);
    }

    public static function getState(string $conversationId, string $key): ?array
    {
        $stmt = self::db()->prepare('SELECT v FROM conv_state WHERE conversation_id = ? AND k = ?');
        $stmt->execute([$conversationId, $key]);
        $v = $stmt->fetchColumn();
        $d = $v === false ? null : json_decode((string) $v, true);
        return is_array($d) ? $d : null;
    }

    public static function clearHistory(string $conversationId): void
    {
        self::db()->prepare('DELETE FROM messages WHERE conversation_id = ?')->execute([$conversationId]);
        self::db()->prepare('DELETE FROM conv_state WHERE conversation_id = ?')->execute([$conversationId]);
        self::resume($conversationId);
    }
}
