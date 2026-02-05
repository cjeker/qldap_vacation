<?php

/**
 * qldap_vacation - Roundcube Plugin for managing qmail-ldap vacation settings
 *
 * @category  RoundcubeMail
 * @package   Plugin
 * @author    Claudio Jeker <cjeker@diehard.n-r-g.com>
 * @license   ISC
 * @version   2.0.0
 */

declare(strict_types=1);

class qldap_vacation extends rcube_plugin
{
    public $task = 'settings';
    public $noajax = true;
    public $noframe = true;

    // LDAP config parameters
    private ?array $config = null;
    private ?array $ldap = null;
    private ?string $server = null;
    private ?string $bind_dn = null;
    private ?string $bind_pw = null;
    private ?string $base_dn = null;
    private ?string $filter = null;
    private array $fields = [];
    private ?string $attr_mailreplytext = null;
    private ?string $attr_deliverymode = null;

    private string $replytext = '';
    private bool $enable = false;

    /**
     * Plugin initialization
     */
    public function init(): void
    {
        try {
            $this->load_config();
            $rcmail = rcmail::get_instance();
            $this->config = $rcmail->config->get('qldap_vacation');

            if (!$this->validate_config()) {
                rcmail::raise_error([
                    'code' => 500,
                    'file' => __FILE__,
                    'line' => __LINE__,
                    'message' => 'qldap_vacation: Invalid or missing configuration'
                ], true, false);
                return;
            }

            // Load LDAP config
            $this->ldap = $this->config['ldap'];
            $this->server = $this->ldap['server'];
            $this->bind_dn = $this->ldap['bind_dn'] ?? null;
            $this->bind_pw = $this->ldap['bind_pw'] ?? null;
            $this->base_dn = $this->ldap['base_dn'];
            $this->filter = $this->ldap['filter'];

            // Attribute names need to be lowercase for the LDAP API
            $this->attr_mailreplytext = strtolower($this->ldap['mailreplytext']);
            $this->attr_deliverymode = strtolower($this->ldap['deliverymode']);
            $this->fields = [$this->attr_mailreplytext, $this->attr_deliverymode];

            $this->add_texts('localization/', true);
            $this->add_hook('settings_actions', [$this, 'settings_actions']);
            $this->register_action('plugin.qldap_vacation', [$this, 'vacation_init']);
            $this->register_action('plugin.qldap_vacation-save', [$this, 'vacation_save']);
            $this->include_script('qldap_vacation.js');
        } catch (Exception $e) {
            rcmail::raise_error([
                'code' => 500,
                'file' => __FILE__,
                'line' => __LINE__,
                'message' => 'qldap_vacation initialization error: ' . $e->getMessage()
            ], true, false);
        }
    }

    /**
     * Validate plugin configuration
     */
    private function validate_config(): bool
    {
        if (!is_array($this->config) || !isset($this->config['ldap'])) {
            rcmail::write_log('qldap_vacation', 'Configuration missing or invalid');
            return false;
        }

        $required_fields = ['server', 'base_dn', 'filter', 'mailreplytext', 'deliverymode'];
        foreach ($required_fields as $field) {
            if (empty($this->config['ldap'][$field])) {
                rcmail::write_log('qldap_vacation', "Required configuration field missing: ldap.$field");
                return false;
            }
        }

        return true;
    }

    /**
     * Add vacation settings to settings menu
     */
    public function settings_actions(array $args): array
    {
        $args['actions'][] = [
            'action' => 'plugin.qldap_vacation',
            'class' => 'qldap_vacation',
            'label' => 'qldap_vacation.qldapvacation',
            'title' => 'qldap_vacation.vacation',
            'domain' => 'vacation',
        ];

        return $args;
    }

    /**
     * Initialize vacation form view
     */
    public function vacation_init(): void
    {
        $this->register_handler('plugin.body', [$this, 'vacation_form']);

        $rcmail = rcmail::get_instance();
        $rcmail->output->set_pagetitle($this->gettext('changevacation'));
        $rcmail->output->send('plugin');
    }

    /**
     * Handle vacation form save
     */
    public function vacation_save(): void
    {
        $this->register_handler('plugin.body', [$this, 'vacation_form']);

        $rcmail = rcmail::get_instance();
        $rcmail->output->set_pagetitle($this->gettext('changevacation'));

        try {
            $this->_save();
            $rcmail->output->show_message($this->gettext('successfullysaved'), 'confirmation');
        } catch (Exception $e) {
            rcmail::write_log('qldap_vacation', 'Save failed: ' . $e->getMessage());
            $rcmail->output->show_message($this->gettext('errorsaving'), 'error');
        }

        $rcmail->overwrite_action('plugin.qldap_vacation');
        $rcmail->output->send('plugin');
    }

    /**
     * Generate vacation form HTML
     */
    public function vacation_form(): string
    {
        $rcmail = rcmail::get_instance();

        try {
            // Load the actual data
            $this->_load();
        } catch (Exception $e) {
            rcmail::write_log('qldap_vacation', 'Load failed: ' . $e->getMessage());
            return html::div(
                ['class' => 'boxerror'],
                $this->gettext('errorloadingdata')
            );
        }

        $table = new html_table(['cols' => 2, 'class' => 'propform']);

        $input_replytext = new html_textarea([
            'name' => 'vacation_body',
            'id' => 'vacation_body',
            'cols' => 80,
            'rows' => 16
        ]);

        $input_checkbox = new html_checkbox([
            'name' => 'vacation_enable',
            'id' => 'vacation_enable',
            'value' => 1
        ]);

        $table->add('title', html::label('vacation_body', rcube::Q($this->gettext('vacation_replytext'))));
        $table->add('', $input_replytext->show($this->replytext));

        $table->add('title', html::label('vacation_enable', rcube::Q($this->gettext('vacation_enable'))));
        $table->add('', $input_checkbox->show($this->enable ? 1 : 0));

        $form = $rcmail->output->form_tag([
            'id' => 'vacation-form',
            'name' => 'vacation-form',
            'method' => 'post',
            'action' => './?_task=settings&_action=plugin.qldap_vacation-save',
        ], $table->show());

        $rcmail->output->add_gui_object('vacationform', 'vacation-form');

        return html::div(['id' => 'prefs-title', 'class' => 'boxtitle'], $this->gettext('changevacation')) .
            html::div(['class' => 'box formcontainer scroller'],
                html::div(['class' => 'boxcontent formcontent'], $form) .
                html::p(['class' => 'formbuttons footerleft'],
                    $rcmail->output->button([
                        'command' => 'plugin.qldap_vacation-save',
                        'class' => 'button mainaction submit',
                        'label' => 'save'
                    ])
                )
            );
    }

    /**
     * Establish LDAP connection
     *
     * @return LDAP\Connection LDAP connection resource
     * @throws RuntimeException on connection or bind failure
     */
    private function _connect()
    {
        $conn = @ldap_connect($this->server);

        if ($conn === false) {
            throw new RuntimeException("Failed to connect to LDAP server: {$this->server}");
        }

        // Set LDAP options
        if (!@ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3)) {
            @ldap_close($conn);
            throw new RuntimeException("Failed to set LDAP protocol version");
        }

        if (!@ldap_set_option($conn, LDAP_OPT_REFERRALS, 0)) {
            rcmail::write_log('qldap_vacation', 'Warning: Failed to disable LDAP referrals');
        }

        // Attempt bind
        if ($this->bind_dn) {
            $bound = @ldap_bind($conn, $this->bind_dn, $this->bind_pw);
        } else {
            $bound = @ldap_bind($conn);
        }

        if (!$bound) {
            $error = ldap_error($conn);
            $errno = ldap_errno($conn);
            @ldap_close($conn);
            throw new RuntimeException("LDAP bind failed: $error (errno: $errno)");
        }

        return $conn;
    }

    /**
     * Close LDAP connection safely
     *
     * @param LDAP\Connection|false|null $conn Connection to close
     */
    private function _disconnect($conn): void
    {
        if ($conn !== null && $conn !== false) {
            @ldap_close($conn);
        }
    }

    private function get_user_identifier(): string
    {
        $rcmail = rcmail::get_instance();
	return $rcmail->user->get_username();
    }

    /**
     * Build LDAP filter with proper placeholder substitution
     *
     * @return string LDAP filter with escaped values
     * @throws RuntimeException if filter cannot be built
     */
    private function build_ldap_filter(): string
    {
        $rcmail = rcmail::get_instance();
        $identity = $rcmail->user->get_identity();

        // Get email and login
        $email = $identity['email'] ?? '';
        $login = $this->get_user_identifier();

        // Escape all values for LDAP
        $escaped_email = ldap_escape($email, '', LDAP_ESCAPE_FILTER);
        $escaped_login = ldap_escape($login, '', LDAP_ESCAPE_FILTER);

        // Replace all placeholders
        $filter = $this->filter;
        $filter = str_replace('%email', $escaped_email, $filter);
        $filter = str_replace('%login', $escaped_login, $filter);

        return $filter;
    }

    /**
     * Load vacation data from LDAP
     *
     * @throws RuntimeException on any error
     */
    private function _load(): void
    {
        $user = $this->get_user_identifier();
        $conn = $this->_connect();

        try {
            $ldap_filter = $this->build_ldap_filter();
            $result = @ldap_search($conn, $this->base_dn, $ldap_filter, $this->fields);

            if ($result === false) {
                $error = ldap_error($conn);
                throw new RuntimeException("LDAP search failed: $error");
            }

            $info = ldap_get_entries($conn, $result);

            if ($info === false) {
                throw new RuntimeException("Failed to retrieve LDAP entries");
            }

            // Require exactly one record
            if ($info['count'] === 0) {
                throw new RuntimeException("No LDAP entry found for user: $user (Filter: $ldap_filter)");
            }

            if ($info['count'] > 1) {
                throw new RuntimeException("Multiple LDAP entries found for user: $user (Count: {$info['count']}, Filter: $ldap_filter)");
            }

            // Extract reply text
            if (isset($info[0][$this->attr_mailreplytext][0])) {
                $this->replytext = $info[0][$this->attr_mailreplytext][0];
            }

            // Extract delivery mode
            if (isset($info[0][$this->attr_deliverymode])) {
                $deliverymodes = $info[0][$this->attr_deliverymode];
                unset($deliverymodes['count']); // Remove count element

                foreach ($deliverymodes as $mode) {
                    if ($mode === 'reply') {
                        $this->enable = true;
                        break;
                    }
                }
            }

            rcmail::write_log('qldap_vacation', "Successfully loaded vacation data for: $user");
        } finally {
            $this->_disconnect($conn);
        }
    }

    /**
     * Save vacation data to LDAP
     *
     * @throws RuntimeException on any error
     */
    private function _save(): void
    {
        $user = $this->get_user_identifier();

        // Sanitize and validate input
        $enable = !empty($_POST['vacation_enable']);
        $replytext = $_POST['vacation_body'] ?? '';

        // Trim and validate reply text
        $replytext = trim($replytext);

        // If no reply text, disable vacation
        if (empty($replytext)) {
            $enable = false;
        }

        $conn = $this->_connect();

        try {
            // Find user entry
            $ldap_filter = $this->build_ldap_filter();
            $result = @ldap_search($conn, $this->base_dn, $ldap_filter, $this->fields);

            if ($result === false) {
                $error = ldap_error($conn);
                throw new RuntimeException("LDAP search failed: $error");
            }

            $info = ldap_get_entries($conn, $result);

            if ($info === false) {
                throw new RuntimeException("Failed to retrieve LDAP entries");
            }

            // Require exactly one record
            if ($info['count'] === 0) {
                throw new RuntimeException("No LDAP entry found for user: $user (Filter: $ldap_filter)");
            }

            if ($info['count'] > 1) {
                throw new RuntimeException("Multiple LDAP entries found for user: $user (Count: {$info['count']}, Filter: $ldap_filter)");
            }

            $dn = $info[0]['dn'];

            // Check current delivery mode
            $was_enabled = false;
            if (isset($info[0][$this->attr_deliverymode])) {
                $deliverymodes = $info[0][$this->attr_deliverymode];
                unset($deliverymodes['count']);

                foreach ($deliverymodes as $mode) {
                    if ($mode === 'reply') {
                        $was_enabled = true;
                        break;
                    }
                }
            }

            // Update reply text
            if (empty($replytext)) {
                $succ = @ldap_mod_del($conn, $dn, [$this->attr_mailreplytext => []]);
            } else {
                $succ = @ldap_modify($conn, $dn, [$this->attr_mailreplytext => [$replytext]]);
            }

            if (!$succ) {
                $error = ldap_error($conn);
                throw new RuntimeException("Failed to update $this->attr_mailreplytext: $error");
            }

            // Update delivery mode if changed
            if ($enable !== $was_enabled) {
                $attrs = [$this->attr_deliverymode => ['reply']];

                if ($enable) {
                    $succ = @ldap_mod_add($conn, $dn, $attrs);
                } else {
                    $succ = @ldap_mod_del($conn, $dn, $attrs);
                }

                if (!$succ) {
                    $error = ldap_error($conn);
                    throw new RuntimeException("Failed to update $this->attr_deliverymode: $error");
                }
            }

            rcmail::write_log('qldap_vacation', "Successfully updated vacation settings for DN: $dn");
        } finally {
            $this->_disconnect($conn);
        }
    }
}
