<?php

declare(strict_types=1);

return [
    'users' => [
        'title' => 'Manage users',
        'description' => 'Everyone who can sign into the customer area. Different from the contacts on one account: this is the list you open when somebody cannot get in.',
        'search' => 'User name or email address',
        'two_factor' => 'Two factor',
        'enabled' => 'Enabled',
        'disabled' => 'Disabled',
        'last_login' => 'Last login',
        'never' => 'Never',
        'actions' => 'Manage user',
        'send_reset' => 'Send password reset email',
        'change_password' => 'Change password',
        'reset_sent' => 'A password reset email has been sent.',
        'password_set' => 'The password has been changed and every session ended.',
        'new_password' => 'New password',
        'confirm_password' => 'Confirm password',
        'reason' => 'Why',
        'reason_hint' => "Recorded against your name. Setting somebody else's password is the most abusable thing a support desk can do.",
        'set' => 'Change it',
        'cancel' => 'Cancel',
        'none' => 'No users match',
        'none_description' => 'Only contacts with portal access appear here.',
    ],

    'tokens' => [
        'title' => 'API tokens',
        'description' => 'Tokens act as you, without a password and without a browser. Issue one per integration so you can revoke it on its own.',
        'name' => 'What is it for',
        'name_hint' => 'The only label this token will ever have. Backup script, Zapier, a colleague.',
        'expires' => 'Expires after',
        'expires_hint' => 'Days. Leave empty for a token that does not expire.',
        'create' => 'Create token',
        'created' => 'Token created.',
        'revoked' => 'Token revoked.',
        'revoke' => 'Revoke',
        'none' => 'No tokens yet.',
        'never_used' => 'Never used',
        'last_used' => 'Last used :date',
        'no_expiry' => 'No expiry',
        'copy_once' => 'Copy this now. It will not be shown again - the platform stores only a hash of it.',
        'not_permitted' => 'You do not have access to API tokens on this account.',
        'api_coming' => 'The public API arrives in a later release. Tokens created now will work with it.',
    ],
    'auth' => [
        // One message for every credential failure. Distinguishing "no such
        // account" from "wrong password" is an enumeration oracle.
        'failed' => 'Those credentials do not match our records.',
        'throttled' => 'Too many attempts. Please try again in :seconds seconds.',
        'account_unavailable' => 'This account is not available. Please contact support.',
        'invalid_code' => 'That code is not valid.',
        'invalid_recovery_code' => 'That recovery code is not valid, or it has already been used.',
        'reset_link_sent' => 'If that address has an account, a reset link is on its way.',
        'password_reset' => 'Your password has been reset. You can sign in now.',
        'password_updated' => 'Your password has been updated.',
        'signed_out_others' => 'Signed out of :count other session(s).',

        // Re-confirmation before something irreversible. The wording says why
        // rather than only what: an operator asked for a password out of
        // nowhere assumes something is broken.
        'confirm_title' => 'Confirm it is you',
        'confirm_body' => 'This action cannot be undone, so your password is needed once more. It will not be asked again for :minutes minutes.',
        'confirm_wrong_password' => 'That password is not correct.',
        'recent_required' => 'This action needs a password confirmation, which a token cannot give. Use the admin area.',
    ],

    'staff' => [
        'created' => 'Staff account created. They can set a password through the reset link.',
        'updated' => 'Staff account updated.',
        'deleted' => 'Staff account deleted.',
        'last_super_admin' => 'This is the last super administrator; the installation would have no one who can administer it.',
        'self_delete' => 'You cannot delete your own account.',
    ],

    'statuses' => [
        'active' => 'Active',
        'suspended' => 'Suspended',
        'closed' => 'Closed',
    ],

    'two_factor' => [
        'enabled' => 'Two-factor authentication is on.',
        'disabled' => 'Two-factor authentication is off.',
        'confirm_failed' => 'That code did not match. Check your authenticator app and try again.',
        'recovery_codes_regenerated' => 'New recovery codes generated. Your old codes no longer work.',
    ],

    'mail' => [
        'reset_subject' => 'Reset your password',
        'reset_intro' => 'You are receiving this email because we received a password reset request for your account.',
        'reset_action' => 'Reset password',
        'reset_expiry' => 'This link expires in :minutes minutes.',
        'reset_ignore' => 'If you did not request a password reset, no further action is required.',
    ],

    'impersonation' => [
        'active' => 'You are viewing this account as :name.',
        'stop' => 'Stop',
        'started' => 'You are now acting as :name.',
        'stopped' => 'Impersonation ended.',
        'forbidden' => 'That account is outside your organization.',
        'no_portal_access' => 'That contact has no portal access, so there is no session to act in.',
        'blocked_action' => 'This action is not available while impersonating.',
    ],
    'register' => [
        'email_taken' => 'An account already uses that email address. Sign in instead, or reset the password if you have forgotten it.',
        'welcome' => 'Your account is ready.',
    ],
];
