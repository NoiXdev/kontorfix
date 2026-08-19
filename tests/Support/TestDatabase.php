<?php

namespace Tests\Support;

use Dotenv\Dotenv;
use PDO;
use PDOException;

/**
 * Gives every checkout its own Postgres test database.
 *
 * phpunit.xml used to pin `DB_DATABASE=testing`, so the main clone and every git
 * worktree pointed at one database. `RefreshDatabase` runs `migrate:fresh` once per
 * process, and two of those against the same schema deadlock (SQLSTATE 40P01) while
 * one drops tables the other is still adding foreign keys to. The aborted migration
 * leaves the schema half-built, so every later test that touches a missing table dies
 * with 42P01 / 42P07 — a red suite whose failures land wherever the migration happened
 * to stop, which is why the count and the affected files moved between runs.
 *
 * Deriving the name from the working directory removes the shared resource: two suites
 * can run at once and never see each other's schema.
 */
final class TestDatabase
{
    /** Postgres truncates identifiers at 63 bytes; stay well inside it. */
    private const MAX_NAME_LENGTH = 63;

    /**
     * Points the process at this checkout's database and makes sure it exists.
     *
     * Called from tests/bootstrap.php, before Laravel reads its environment — the
     * variable set here wins because phpdotenv never overwrites an existing one.
     */
    public static function isolate(string $root): void
    {
        $configured = getenv('DB_DATABASE');
        $name = self::resolve($root, is_string($configured) ? $configured : null);

        // An explicitly configured name is somebody else's database (CI provisions it
        // through the service container), so claim nothing and create nothing.
        if ($name === $configured) {
            return;
        }

        putenv("DB_DATABASE={$name}");
        $_ENV['DB_DATABASE'] = $name;
        $_SERVER['DB_DATABASE'] = $name;

        self::ensureExists($root, $name);
    }

    /** The configured name if there is one, otherwise this checkout's own. */
    public static function resolve(string $root, ?string $configured): string
    {
        return $configured === null || $configured === '' ? self::nameFor($root) : $configured;
    }

    /**
     * A stable, collision-free, human-recognisable database name for one directory.
     *
     * The slug is what makes a stray database identifiable months later; the hash is
     * what keeps two checkouts that end in the same directory name apart.
     */
    public static function nameFor(string $root): string
    {
        $hash = substr(sha1($root), 0, 8);

        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', basename($root)));
        $slug = trim($slug, '_');

        $room = self::MAX_NAME_LENGTH - strlen('testing__'.$hash);
        $slug = substr($slug, 0, max(0, $room));
        $slug = trim($slug, '_');

        return $slug === '' ? "testing_{$hash}" : "testing_{$slug}_{$hash}";
    }

    /**
     * Creates the database on first use, so a fresh clone or worktree needs no setup
     * step of its own.
     *
     * The credentials come from .env read into an array rather than into the
     * environment: this runs before Laravel boots, and putting the whole file into
     * getenv() here would silently change what the application under test sees.
     */
    private static function ensureExists(string $root, string $name): void
    {
        $env = self::readEnvFile($root);

        $host = $env['DB_HOST'] ?? '127.0.0.1';
        $port = $env['DB_PORT'] ?? '5432';
        $user = $env['DB_USERNAME'] ?? 'db';
        $password = $env['DB_PASSWORD'] ?? '';

        try {
            // `postgres` is the maintenance database every server has; CREATE DATABASE
            // cannot run from inside the database it creates.
            $pdo = new PDO(
                "pgsql:host={$host};port={$port};dbname=postgres",
                $user,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );

            $exists = $pdo->prepare('select 1 from pg_database where datname = ?');
            $exists->execute([$name]);

            if ($exists->fetchColumn() === false) {
                // Identifier, not a value, so it cannot be bound. The name comes from
                // nameFor(), which emits [a-z0-9_] only.
                $pdo->exec("create database \"{$name}\"");
            }
        } catch (PDOException) {
            // Leave the diagnosis to the suite itself: a connection failure here would
            // report the maintenance database, while letting the run continue produces
            // the real error against the real connection.
        }
    }

    /**
     * @return array<string, string|null>
     */
    private static function readEnvFile(string $root): array
    {
        if (! is_file($root.'/.env')) {
            return [];
        }

        return Dotenv::createArrayBacked($root)->safeLoad();
    }
}
