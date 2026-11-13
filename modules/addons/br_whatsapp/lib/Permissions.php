<?php
/**
 * Decides which admins may do each job, using the roles chosen in the module settings:
 *   send     = send a WhatsApp message to one client (default: every role that can open the module)
 *   bulk     = start, pause or cancel marketing campaigns (default: Full Administrator only)
 *   settings = change settings, messages and templates, view logs and GDPR tools (default: Full Administrator only)
 * Full Administrators can always do everything, so nobody gets locked out.
 * Checked on the server for every action, not only by hiding buttons.
 */

declare(strict_types=1);

namespace BinaryRacks\WhatsApp;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    exit;
}

final class Permissions
{
    public const JOBS = ['send', 'bulk', 'settings'];

    /** WHMCS's built-in "Full Administrator" role always has this ID. */
    public const FULL_ADMIN_ROLE_ID = 1;

    /** Returns the ID of the admin who is logged in, or 0 if none. */
    public static function currentAdminId(): int
    {
        return (int) ($_SESSION['adminid'] ?? 0);
    }

    /** Returns the role ID of an admin, or 0 if the admin is missing or disabled. */
    public static function roleOf(int $adminId): int
    {
        if ($adminId <= 0) {
            return 0;
        }
        $admin = Capsule::table('tbladmins')->where('id', $adminId)->first(['roleid', 'disabled']);
        if ($admin === null || (int) $admin->disabled === 1) {
            return 0;
        }
        return (int) $admin->roleid;
    }

    /** Returns true if the logged-in admin may do the job. */
    public static function can(string $job): bool
    {
        return self::adminCan(self::currentAdminId(), $job);
    }

    /** Returns true if the given admin may do the job. */
    public static function adminCan(int $adminId, string $job): bool
    {
        if (!in_array($job, self::JOBS, true)) {
            return false;
        }
        $role = self::roleOf($adminId);
        if ($role === 0) {
            return false;
        }
        if ($role === self::FULL_ADMIN_ROLE_ID) {
            return true;
        }
        $allowed = Settings::roleIds('roles_' . $job);
        if ($job === 'send' && $allowed === []) {
            return true;   // default for "send": any role that WHMCS lets open the module
        }
        return in_array($role, $allowed, true);
    }

    /** Returns all admin roles as [id => name], for the settings page. */
    public static function allRoles(): array
    {
        $roles = [];
        foreach (Capsule::table('tbladminroles')->orderBy('id')->get(['id', 'name']) as $row) {
            $roles[(int) $row->id] = (string) $row->name;
        }
        return $roles;
    }
}
