<?php

/**
 * Configuration for qldap_vacation Roundcube plugin
 *
 * Copy this file to config.inc.php and adjust the values as needed.
 * All LDAP settings are required unless marked as optional.
 */

$config['qldap_vacation'] = [
    'ldap' => [
        /**
         * LDAP server address
         * Format: ldap://hostname:port or ldaps://hostname:port
         */
        'server' => 'ldap://localhost',

        /**
         * Distinguished Name to use for binding to LDAP
         * Leave empty for anonymous bind
         */
        'bind_dn' => 'cn=mail,ou=services,dc=example,dc=com',

        /**
         * Password for the bind DN
         * Required if bind_dn is specified
         */
        'bind_pw' => 'secret',

        /**
         * LDAP search base DN
         * The seach base DN where user entries are located
         */
        'base_dn' => 'ou=users,dc=example,dc=com',

        /**
         * LDAP search filter
         * Filter to locate user entries:
         * - %email - uses email address
         * - %login - uses login username
         *
	 * Examples:
         * - Match mail or alternate address
         *    '(|(mail=%email)(mailAlternateAddress=%email))'
         * - Match for user login:
         *    '(uid=%login)'
         */
        'filter' => '(uid=%login)'

        /**
         * LDAP attribute name for vacation reply text
         * Common values: 'mailReplyText', 'vacationMessage'
         */
        'mailreplytext' => 'mailReplyText',

        /**
         * LDAP attribute name for delivery mode
         * Common values: 'deliveryMode', 'mailDeliveryOption'
         */
        'deliverymode' => 'deliveryMode',
    ]
];

/**
 * Advanced LDAP options (optional)
 * Uncomment and configure if needed
 */
/*
$config['qldap_vacation']['ldap']['timeout'] = 10;          // Connection timeout in seconds
$config['qldap_vacation']['ldap']['sizelimit'] = 1;         // Maximum number of entries to return
$config['qldap_vacation']['ldap']['timelimit'] = 10;        // Search time limit in seconds
*/

/**
 * Logging options (optional)
 * Uncomment to enable debug logging
 */
/*
$config['qldap_vacation']['debug'] = true;
*/
