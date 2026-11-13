<?php
/**
 * A tiny pretend WHMCS for tests: creates the WHMCS tables the module reads, adds sample data,
 * and answers the two localAPI commands the module uses (GetClientsDetails, UpdateClient).
 */

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;

final class FakeWhmcs
{
    /** Hooks registered with add_hook(): name => callbacks. */
    public static array $hooks = [];

    /** Every localAPI call: [command, values]. */
    public static array $apiCalls = [];

    /** Texts written with logActivity(). */
    public static array $activity = [];

    /** Calling codes for the test countries. */
    private const CALLING_CODES = ['GB' => '44', 'US' => '1', 'AU' => '61', 'DE' => '49', 'IT' => '39'];

    /** Starts a fresh, empty database with the WHMCS tables (in memory unless a file path is given). */
    public static function reset(string $database = ':memory:'): void
    {
        self::$apiCalls = [];
        self::$activity = [];
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => $database, 'prefix' => '']);
        $capsule->setAsGlobal();
        $schema = Manager::schema();

        $schema->create('tblclients', function (Blueprint $t) {
            $t->increments('id');
            $t->string('firstname')->default('');
            $t->string('lastname')->default('');
            $t->string('companyname')->default('');
            $t->string('phonenumber')->default('');
            $t->string('country', 2)->default('GB');
            $t->string('state')->default('');
            $t->string('status')->default('Active');
            $t->integer('currency')->default(1);
            $t->integer('marketing_emails_opt_in')->default(0);
        });
        $schema->create('tblcustomfields', function (Blueprint $t) {
            $t->increments('id');
            foreach (['type', 'fieldname', 'fieldtype', 'description', 'fieldoptions', 'regexpr', 'adminonly', 'required', 'showorder', 'showinvoice'] as $c) {
                $t->text($c)->default('');
            }
            $t->integer('relid')->default(0);
            $t->integer('sortorder')->default(0);
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });
        $schema->create('tblcustomfieldsvalues', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('fieldid');
            $t->integer('relid');
            $t->text('value');
        });
        $schema->create('tblinvoices', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('userid');
            $t->string('invoicenum')->default('');
            $t->date('duedate');
            $t->decimal('total', 10, 2);
            $t->string('status')->default('Unpaid');
        });
        $schema->create('tblhosting', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('userid');
            $t->integer('packageid');
            $t->string('domain')->default('');
            $t->text('suspendreason')->default('');
        });
        $schema->create('tblproducts', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name');
        });
        $schema->create('tbltickets', function (Blueprint $t) {
            $t->increments('id');
            $t->string('tid');
            $t->integer('userid');
            $t->string('title');
        });
        $schema->create('tbldomains', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('userid');
            $t->string('domain');
            $t->date('expirydate');
            $t->string('status')->default('Active');
        });
        $schema->create('tblcurrencies', function (Blueprint $t) {
            $t->increments('id');
            $t->string('code');
            $t->string('prefix');
            $t->string('suffix');
            $t->integer('default')->default(0);
        });
        $schema->create('tblconfiguration', function (Blueprint $t) {
            $t->string('setting')->primary();
            $t->text('value');
        });
        $schema->create('tbladmins', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('roleid');
            $t->integer('disabled')->default(0);
        });
        $schema->create('tbladminroles', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name');
        });
        $schema->create('tbladdonmodules', function (Blueprint $t) {
            $t->string('module');
            $t->string('setting');
            $t->text('value');
        });

        Manager::table('tblcurrencies')->insert(['id' => 1, 'code' => 'GBP', 'prefix' => '£', 'suffix' => ' GBP', 'default' => 1]);
        Manager::table('tblconfiguration')->insert([['setting' => 'CompanyName', 'value' => 'Binary Racks'],
                                                    ['setting' => 'SystemURL', 'value' => 'https://billing.example.com/']]);
        Manager::table('tbladminroles')->insert([['id' => 1, 'name' => 'Full Administrator'], ['id' => 2, 'name' => 'Sales Operator'], ['id' => 3, 'name' => 'Support Operator']]);
        Manager::table('tbladmins')->insert([['id' => 1, 'roleid' => 1], ['id' => 2, 'roleid' => 2], ['id' => 3, 'roleid' => 3]]);
        Manager::table('tbladdonmodules')->insert(['module' => 'br_whatsapp', 'setting' => 'version', 'value' => '1.0.0']);
        Manager::table('tblproducts')->insert(['id' => 1, 'name' => 'VPS Small']);
    }

    /** Adds a client and returns its ID. */
    public static function addClient(array $data = []): int
    {
        return (int) Manager::table('tblclients')->insertGetId($data + [
            'firstname' => 'Alex', 'lastname' => 'Smith', 'phonenumber' => '+44.7911123456', 'country' => 'GB',
            'status' => 'Active', 'marketing_emails_opt_in' => 1,
        ]);
    }

    /** Answers localAPI calls the way WHMCS would (only what the module uses). */
    public static function api(string $command, array $values): array
    {
        self::$apiCalls[] = [$command, $values];
        if ($command === 'GetClientsDetails') {
            $client = Manager::table('tblclients')->where('id', (int) ($values['clientid'] ?? 0))->first();
            if ($client === null) {
                return ['result' => 'error', 'message' => 'Client Not Found'];
            }
            $data = (array) $client + ['countrycode' => $client->country, 'phonecc' => self::CALLING_CODES[$client->country] ?? ''];
            return ['result' => 'success', 'client' => $data] + $data;
        }
        if ($command === 'UpdateClient') {
            $clientId = (int) ($values['clientid'] ?? 0);
            $fields = unserialize(base64_decode((string) ($values['customfields'] ?? '')), ['allowed_classes' => false]);
            foreach (is_array($fields) ? $fields : [] as $fieldId => $value) {
                Manager::table('tblcustomfieldsvalues')->where('fieldid', $fieldId)->where('relid', $clientId)->delete();
                Manager::table('tblcustomfieldsvalues')->insert(['fieldid' => $fieldId, 'relid' => $clientId, 'value' => $value]);
            }
            return ['result' => 'success', 'clientid' => $clientId];
        }
        return ['result' => 'error', 'message' => 'Not faked: ' . $command];
    }

    /** Runs every callback registered for a hook and returns their results. */
    public static function runHook(string $name, array $vars = []): array
    {
        $results = [];
        foreach (self::$hooks[$name] ?? [] as $callback) {
            $results[] = $callback($vars);
        }
        return $results;
    }
}
