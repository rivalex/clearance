<?php

declare(strict_types=1);

namespace Rivalex\Clearance\Support;

use Throwable;

/**
 * Maps the English exception messages thrown by the services to translated UI messages.
 *
 * The services keep throwing English messages. This class matches a message against a table of templates
 * (the same strings as `clearance::ui.errors.*` in the English locale, with `:name` placeholders) and returns
 * the translation of the matching key with the captured values. An unknown message falls back to
 * `clearance::ui.errors.refused`, which shows the original message as `:detail`.
 */
final class ErrorMessage
{
    /**
     * Translation key (under `clearance::ui.errors.`) => English message template.
     *
     * @var array<string, string>
     */
    private const TEMPLATES = [
        'role_name_reserved' => 'The name super_admin is reserved and cannot be used for a role.',
        'role_name_invalid' => 'A role name may contain only letters, digits, spaces, underscores, dots and hyphens (1 to 255 characters).',
        'super_admin_no_rename' => 'The super_admin role cannot be renamed.',
        'role_locked_rename' => 'This role is locked and cannot be renamed.',
        'super_admin_no_delete' => 'The super_admin role cannot be deleted.',
        'role_locked_delete' => 'This role is locked and cannot be deleted.',
        'guard_mismatch' => 'Permission \':name\' guard \':given\' does not match role guard \':expected\'.',
        'clearance_permission_super_admin' => 'Permission [:permission] is a built-in clearance permission and cannot be added or removed from the super_admin role.',
        'non_delegable_role' => 'Permission [:permission] is non-delegable and cannot be added to role [:role] while users hold it.',
        'add_to_held_role' => 'Only super_admin may add permissions to role [:role] while holding it themselves.',
        'clearance_permission_role' => 'Permission [:permission] is a built-in clearance permission and cannot be added to any role via the panel.',
        'cannot_add_permission' => 'Cannot add permission [:permission] to role [:role]: you do not hold it yourself.',
        'super_admin_scope' => 'The scope of the super_admin role cannot be changed.',
        'role_scope_locked' => 'The scope of role [:role] cannot be changed while users hold it.',
        'role_context_types_locked' => 'The context types of role [:role] cannot be narrowed while users hold it.',
        'context_type_undeclared' => 'Context type [:types] is not declared in clearance.contextual_models.',
        'ceiling_self' => 'Role [:role] cannot be its own ceiling parent.',
        'ceiling_guard' => 'Role [:role] and parent [:parent] must share the same guard.',
        'ceiling_super_admin' => 'The super_admin role cannot take part in a ceiling relationship.',
        'ceiling_already_parent' => 'Role [:role] already acts as a parent and cannot become a child.',
        'ceiling_already_child' => 'Role [:role] is already a child role and cannot act as a parent.',
        'guard_exists' => 'Guard [:guard] already exists in auth.guards and cannot be redefined.',
        'guard_defined' => 'Guard [:guard] is defined in auth.guards and cannot be redefined.',
        'provider_undefined' => 'Provider [:provider] is not defined in auth.providers.',
        'permission_name_format' => 'Permission name \':name\' must follow format gruppo-azione: lowercase, underscores allowed, no spaces/dots/other symbols, at least one separator, no bare action.',
        'permission_name_reserved' => 'Permission name [:name] is reserved for built-in clearance permissions and cannot be used.',
        'permission_builtin_rename' => 'Permission [:permission] is a built-in clearance permission and cannot be renamed.',
        'permission_non_delegable_rename' => 'Permission [:permission] has holders and cannot be renamed to the non-delegable name [:name].',
        'permission_builtin_delete' => 'Permission [:permission] is a built-in clearance permission and cannot be deleted.',
        'permission_unknown' => 'Permission [:permission] does not exist on guard [:guard].',
        'default_super_admin' => 'The super_admin role cannot be the default role.',
        'default_protected_permission' => 'Role [:role] contains the protected permission [:permission] and cannot be the default role.',
        'remove_holder_unresolved' => 'Cannot remove role [:role]: holder [:holder] cannot be resolved.',
        'reassign_holder_unresolved' => 'Cannot reassign role [:role]: holder [:holder] cannot be resolved.',
        'remove_context_unresolved' => 'Cannot remove role [:role]: context [:context] cannot be resolved.',
        'reassign_context_unresolved' => 'Cannot reassign role [:role]: context [:context] cannot be resolved.',
        'remove_binding_deny' => 'Cannot remove role [:role]: a binding carries denied permissions and only a super_admin may remove it.',
        'super_admin_only_remove' => 'Only super_admin may remove the super_admin role.',
        'user_lacks_role' => 'User does not hold role [:role].',
        'super_admin_only_change_roles' => 'Only super_admin may change the roles of a super_admin.',
        'remove_direct_missing' => 'Cannot remove role [:role]: the user holds direct permission(s) [:permissions] that you do not hold.',
        'revoke_direct_missing' => 'Cannot revoke from role [:role]: the user holds direct permission(s) [:permissions] that you do not hold.',
        'reassign_itself' => 'Cannot reassign a role to itself.',
        'super_admin_no_reassign' => 'The super_admin role cannot be reassigned.',
        'super_admin_only_reassign' => 'Only super_admin may reassign a role they hold.',
        'last_super_admin' => 'The last super_admin cannot be removed.',
        'role_non_delegable_remove' => 'Role [:role] contains non-delegable permissions and can only be removed by a super_admin.',
        'role_contextual_only' => 'Role [:role] is contextual; it cannot be assigned globally.',
        'role_global_only' => 'Role [:role] is global; it cannot be assigned contextually.',
        'role_context_type_refused' => 'Role [:role] does not accept context type [:type].',
        'role_non_delegable_assign' => 'Role [:role] contains non-delegable permissions and can only be assigned by the system.',
        'super_admin_self_assign' => 'Only super_admin may assign roles to themselves.',
        'super_admin_only_assign' => 'Only super_admin may assign the super_admin role.',
        'assign_missing' => 'Cannot assign role [:role]: you do not hold permission(s) [:permissions].',
        'reassign_missing' => 'Cannot reassign role [:role]: you do not hold permission(s) [:permissions].',
        'remove_missing' => 'Cannot remove role [:role]: you do not hold permission(s) [:permissions].',
        'deny_carried' => 'This assignment carries a deny override set by the application; only super_admin can remove it.',
        'super_admin_own_permissions' => 'Only super_admin may modify their own direct permissions.',
        'grant_builtin' => 'Permission [:permission] is a built-in clearance permission and cannot be granted directly.',
        'grant_non_delegable' => 'Permission [:permission] is non-delegable and cannot be granted through the panel.',
        'grant_missing' => 'Cannot grant permission [:permission]: you do not hold it yourself.',
        'no_binding' => 'No binding for this user, role and context.',
        'super_admin_role_change' => 'Only super_admin may change the super_admin role.',
        'rename_held_role' => 'Only super_admin may rename a role they hold.',
        'default_role_rename' => 'Only super_admin may rename the default role.',
        'rename_missing' => 'Cannot rename role [:role]: you do not hold permission(s) [:permissions].',
        'role_remove_missing' => 'Cannot remove permission(s) [:permissions] from role [:role]: you do not hold them.',
        'cascade_locked_child' => 'Cannot remove permission(s) from role [:role]: its child role [:child] is locked.',
        'role_locked_change' => 'This role is locked and cannot be changed.',
        'role_lock_super_admin' => 'Only super_admin may lock or unlock a role.',
        'role_scope_invalid' => 'Scope [:scope] is not valid: use global or contextual.',
        'delete_missing' => 'Cannot delete role [:role]: you do not hold permission(s) [:permissions].',
        'default_role_no_delete' => 'The default role cannot be deleted; choose another default role first.',
        'holder_out_of_scope' => 'Cannot change role [:role]: it is held by a user you may not manage.',
        'permission_delete_held' => 'Cannot delete permission [:permission]: users hold it and you do not hold it yourself.',
        'permission_delete_non_delegable' => 'Permission [:permission] is non-delegable and can only be deleted by a super_admin.',
        'guard_in_use_update' => 'Guard [:guard] has roles or permissions: its driver and provider cannot be changed.',
        'guard_in_use_delete' => 'Guard [:guard] has roles or permissions and cannot be deleted.',
        'pin_remove_missing' => 'Cannot remove pinned permission(s) [:permissions]: you do not hold them in this context.',
        'ceiling_parent_remove_missing' => 'Cannot remove the ceiling parent [:parent] of role [:role]: you do not hold permission(s) [:permissions].',
        'ceiling_parent_replace_missing' => 'Cannot replace the ceiling parent [:parent] of role [:role]: you do not hold permission(s) [:permissions].',
        'default_role_add_permissions' => 'Only super_admin may add permissions to the default role.',
        'default_role_scope' => 'Only super_admin may change the scope of the default role.',
        'permission_delete_protected_role' => 'Cannot delete permission [:permission]: the super_admin role or a locked role holds it.',
        'role_delete_has_holders' => 'Cannot delete role [:role]: users still hold it; detach or reassign them first.',
        'role_guard_no_provider' => 'Guard [:guard] has no user provider model: a role on it could not be deleted.',
    ];

    /**
     * Returns the translated message for an exception raised by a service.
     */
    public static function for(Throwable $e): string
    {
        $message = $e->getMessage();

        foreach (self::TEMPLATES as $key => $template) {
            $matches = [];

            if (preg_match(self::pattern($template), $message, $matches) !== 1) {
                continue;
            }

            $replace = [];

            foreach ($matches as $name => $value) {
                if (is_string($name)) {
                    $replace[$name] = $value;
                }
            }

            return (string) __('clearance::ui.errors.'.$key, $replace);
        }

        return (string) __('clearance::ui.errors.refused', ['detail' => $message]);
    }

    /**
     * The English templates keyed by translation key, exposed so a test can keep them in step with the locale files.
     *
     * @return array<string, string>
     */
    public static function templates(): array
    {
        return self::TEMPLATES;
    }

    /**
     * Builds the anchored regular expression of a template: every `:name` becomes a named lazy capture.
     */
    private static function pattern(string $template): string
    {
        $quoted = preg_quote($template, '/');
        $regex = (string) preg_replace('/\\\\:([a-z_]+)/', '(?<$1>.+?)', $quoted);

        return '/^'.$regex.'$/s';
    }
}
