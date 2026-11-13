<?php
/**
 * Creates and upgrades the module's own database tables.
 * Called on activation, on upgrade and on every admin page load (a quick version check),
 * so uploading a new version upgrades the tables automatically.
 */

declare(strict_types=1);

namespace BinaryRacks\WhatsApp;

use Illuminate\Database\Schema\Blueprint;
use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    exit;
}

final class Schema
{
    /** Increase by one whenever a table changes, and add a step in upgrade(). */
    public const SCHEMA_VERSION = 1;

    /** Every table this module owns. */
    public const TABLES = [
        'mod_brwa_meta', 'mod_brwa_settings', 'mod_brwa_messages', 'mod_brwa_queue',
        'mod_brwa_log', 'mod_brwa_campaigns', 'mod_brwa_contacts', 'mod_brwa_ratelimit',
    ];

    /** Makes sure all tables exist and are at the latest version. Safe to call many times. */
    public static function ensure(): void
    {
        $schema = Capsule::schema();
        if (!$schema->hasTable('mod_brwa_meta')) {
            self::install();
            return;
        }
        $current = (int) Capsule::table('mod_brwa_meta')->where('name', 'schema_version')->value('value');
        if ($current < self::SCHEMA_VERSION) {
            self::upgrade($current);
        }
    }

    /** Creates all tables for a fresh install. */
    private static function install(): void
    {
        $schema = Capsule::schema();

        $schema->create('mod_brwa_meta', function (Blueprint $t) {
            $t->string('name', 64)->primary();
            $t->text('value');
        });

        $schema->create('mod_brwa_settings', function (Blueprint $t) {
            $t->string('setting', 64)->primary();
            $t->text('value');
        });

        // Pre-configured messages: automatic events, manual messages and marketing messages.
        $schema->create('mod_brwa_messages', function (Blueprint $t) {
            $t->increments('id');
            $t->string('msg_key', 64)->unique();
            $t->string('kind', 16);                 // event | manual | marketing
            $t->string('category', 16)->nullable(); // invoices | services | tickets | domains (events only)
            $t->string('label', 100);
            $t->string('template_name', 512)->default('');
            $t->string('language', 16)->nullable(); // empty = use the module's language
            $t->text('params');                     // JSON list of placeholder keys, in blank order
            $t->boolean('enabled')->default(false);
            $t->boolean('builtin')->default(false);
            $t->dateTime('created_at');
            $t->dateTime('updated_at');
        });

        // Messages waiting to be sent. Holds only IDs, never message text (GDPR).
        $schema->create('mod_brwa_queue', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('client_id')->index();
            $t->string('msg_key', 64);
            $t->string('source', 16);               // event | manual | campaign
            $t->unsignedInteger('related_id')->nullable();
            $t->unsignedInteger('campaign_id')->nullable()->index();
            $t->string('dedupe_key', 191)->nullable()->unique();
            $t->boolean('critical')->default(false);
            $t->string('status', 16)->index();      // pending | sending | sent | failed | skipped | paused | cancelled
            $t->unsignedInteger('attempts')->default(0);
            $t->dateTime('send_after')->index();
            $t->dateTime('locked_at')->nullable();
            $t->unsignedInteger('admin_id')->nullable();
            $t->dateTime('created_at');
            $t->dateTime('updated_at');
        });

        // Message log: masked phone only, no message text (GDPR).
        $schema->create('mod_brwa_log', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('client_id')->nullable()->index();
            $t->string('phone_masked', 32)->default('');
            $t->string('msg_key', 64);
            $t->string('source', 16);
            $t->unsignedInteger('related_id')->nullable();
            $t->string('status', 16)->index();      // sent | delivered | read | failed | skipped | stop
            $t->string('wamid', 191)->nullable()->index();
            $t->string('error_code', 32)->nullable();
            $t->string('error_text', 255)->nullable();
            $t->boolean('is_stop')->default(false);
            $t->dateTime('created_at')->index();
            $t->dateTime('updated_at');
        });

        // Bulk marketing campaigns (only totals are kept after they finish).
        $schema->create('mod_brwa_campaigns', function (Blueprint $t) {
            $t->increments('id');
            $t->string('msg_key', 64);
            $t->string('status', 16);               // running | paused | cancelled | completed
            $t->unsignedInteger('total')->default(0);
            $t->unsignedInteger('sent')->default(0);
            $t->unsignedInteger('failed')->default(0);
            $t->unsignedInteger('skipped')->default(0);
            $t->unsignedInteger('created_by')->default(0);
            $t->dateTime('created_at');
            $t->dateTime('updated_at');
        });

        // Links a hashed phone number to a client, so a STOP reply can find the client.
        // Only a one-way hash is stored, never the number itself.
        $schema->create('mod_brwa_contacts', function (Blueprint $t) {
            $t->unsignedInteger('client_id')->primary();
            $t->string('phone_hash', 64)->index();
            $t->dateTime('updated_at');
        });

        // Counters for rate limits. Keys are hashed; rows are deleted after an hour.
        $schema->create('mod_brwa_ratelimit', function (Blueprint $t) {
            $t->string('bucket', 100);
            $t->unsignedInteger('window_start');
            $t->unsignedInteger('hits')->default(0);
            $t->primary(['bucket', 'window_start']);
        });

        Capsule::table('mod_brwa_meta')->insert(['name' => 'schema_version', 'value' => (string) self::SCHEMA_VERSION]);
    }

    /** Runs each upgrade step from the stored version up to SCHEMA_VERSION. */
    private static function upgrade(int $from): void
    {
        // Version 1 is the first release, so there are no steps yet.
        // Future example: if ($from < 2) { Capsule::schema()->table('mod_brwa_log', ...); }
        Capsule::table('mod_brwa_meta')->where('name', 'schema_version')
            ->update(['value' => (string) self::SCHEMA_VERSION]);
    }

    /** Deletes all module tables (only when "Delete data on deactivate" is ticked). */
    public static function dropAll(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Capsule::schema()->dropIfExists($table);
        }
    }

    /** Saves a small internal value, such as the last time the queue ran. */
    public static function setMeta(string $name, string $value): void
    {
        if (Capsule::table('mod_brwa_meta')->where('name', $name)->exists()) {
            Capsule::table('mod_brwa_meta')->where('name', $name)->update(['value' => $value]);
        } else {
            Capsule::table('mod_brwa_meta')->insert(['name' => $name, 'value' => $value]);
        }
    }

    /** Reads a small internal value saved with setMeta(). */
    public static function getMeta(string $name): string
    {
        return (string) Capsule::table('mod_brwa_meta')->where('name', $name)->value('value');
    }
}
