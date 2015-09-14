<?php
/*
 * Default configuration settings for qldap_vacation roundcube plugin.
 * Copy this file to config.inc.php, and override the values as need.
 */

$rcmail_config['qldap_vacation'] = array(
    'ldap' => array(
        # LDAP server address
        'server'     => 'ldap://localhost',
        # LDAP Bind DN
        'bind_dn'    => 'cn=mail,ou=services,dc=example,dc=com',
        # Bind password
        'bind_pw'    => 'secret',
        
        # LDAP search base
        'base_dn'    => 'ou=users,dc=example,dc=com',
        # LDAP search filter
        # - Use '%email' as a place holder for the email address
        'filter'     => '(|(mail=%email)(mailAlternateAddrerss=%email))',
        
        # LDAP mailreplytext attribute
        'mailreplytext'  => 'mailReplyText',
        # LDAP delivery mode attribute
        'deliverymode'  => 'deliveryMode',
    )
);
?>
