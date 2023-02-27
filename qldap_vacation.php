<?php

class qldap_vacation extends rcube_plugin
{
  public $task    = 'settings';
  public $noajax  = true;
  public $noframe = true;

  // LDAP config parameters
  private $config;
  private $ldap;
  private $server;
  private $bind_dn;
  private $bind_pw;
  private $base_dn;
  private $filter;
  private $fields;
  private $attr_mailreplytext;
  private $attr_deliverymode;

  private $replytext;
  private $enable;

  function init()
  {
    $this->load_config();
    $this->config = rcmail::get_instance()->config->get('qldap_vacation');

    // Load LDAP config
    $this->ldap      = $this->config['ldap'];
    $this->server    = $this->ldap['server'];
    $this->bind_dn   = $this->ldap['bind_dn'];
    $this->bind_pw   = $this->ldap['bind_pw'];
    $this->base_dn   = $this->ldap['base_dn'];
    $this->filter    = $this->ldap['filter'];

    // attribute names need to be lowercase for the LDAP api
    $this->attr_mailreplytext = strtolower($this->ldap['mailreplytext']);
    $this->attr_deliverymode  = strtolower($this->ldap['deliverymode']);
    $this->fields = array($this->attr_mailreplytext, $this->attr_deliverymode);

    $this->replytext = '';
    $this->enable = false;

    $this->add_texts('localization/', true);

    $this->add_hook('settings_actions', array($this, 'settings_actions'));

    $this->register_action('plugin.qldap_vacation', array($this, 'vacation_init'));
    $this->register_action('plugin.qldap_vacation-save', array($this, 'vacation_save'));
    $this->include_script('qldap_vacation.js');
  }

  function settings_actions($args)
  {
    // register as settings action
    $args['actions'][] = array(
      'action' => 'plugin.qldap_vacation',
      'class'  => 'qldap_vacation',
      'label'  => 'qldap_vacation.qldapvacation',
      'title'  => 'qldap_vacation.vacation',
      'domain' => 'vacation',
    );

    return $args;
  }

  function vacation_init()
  {
    $this->register_handler('plugin.body', array($this, 'vacation_form'));
 
    $rcmail = rcmail::get_instance();
    $rcmail->output->set_pagetitle($this->gettext('changevacation'));
    $rcmail->output->send('plugin');
  }
  
  function vacation_save()
  {
     $this->register_handler('plugin.body', array($this, 'vacation_form'));

     $rcmail = rcmail::get_instance();
     $rcmail->output->set_pagetitle($this->gettext('changevacation'));

     $this->_save();

     $rcmail->overwrite_action('plugin.qldap_vacation');
     $rcmail->output->send('plugin');
  }

  function vacation_form()
  {
    $rcmail = rcmail::get_instance();
    // load the actuall data
    $this->_load();

    $table = new html_table(array('cols' => 2));

    $input_replytext = new html_textarea(array('name' => 'vacation_body', 'id' => 'vacation_body', 'cols' => 80, 'rows' => 16));
    $input_checkbox = new html_checkbox(array('name' => 'vacation_enable', 'id' => 'vaction_enable', 'value' => 1))
;

    $table->add('title', html::label('vacation_body', rcube::Q($this->gettext('vacation_replytext'))));
    $table->add('', $input_replytext->show($this->replytext));

    $table->add('title', html::label('vaction_enable', rcube::Q($this->gettext('vacation_enable'))));
    $table->add('', $input_checkbox->show($this->enable ? 1 : 0));
    
    $out = html::div(array('class' => 'box'),
      html::div(array('id' => 'prefs-title', 'class' => 'boxtitle'), $this->gettext('changevacation')) .
      html::div(array('class' => 'boxcontent'), $table->show() .
      html::p(null,
        $rcmail->output->button(array(
          'command' => 'plugin.qldap_vacation-save',
          'type'    => 'input',
          'class'   => 'button mainaction',
          'label'   => 'save'
      )))));

    $rcmail->output->add_gui_object('vacationform', 'vacation-form');
    
    return $rcmail->output->form_tag(array(
      'id'     => 'vacation-form',
      'name'   => 'vacation-form',
      'method' => 'post',
      'action' => './?_task=settings&_action=plugin.qldap_vacation-save',
    ), $out);
  }

  function _connect()
  {
    // LDAP Connection
    $conn = ldap_connect($this->server);

    if ( is_resource($conn) ) {
      ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
      // anonymous bind will probably not work to modify entries but who knows...
      if ( $this->bind_dn ){
        $bound = ldap_bind($conn, $this->bind_dn, $this->bind_pw);
      } else {
        $bound = ldap_bind($conn);
      }
      if (! $bound ) {
        $log = sprintf("Bind to server '%s' failed. Con: (%s), Error: (%s)",
          $this->server, $this->conn, ldap_error($conn));
        rcmail::write_log('qldap_vacation', $log);
        ldap_close($conn);
        return false;
      }
    } else {
      $log = sprintf("Connection to the server failed: (Error=%s)", ldap_error($conn));
      rcmail::write_log('qldap_vacation', $log);
      ldap_close($conn);
      return false;
    }
    return $conn;
  }

  function _load()
  {
    $rcmail = rcmail::get_instance();
    $email = $rcmail->user->get_identity()['email'];
    $conn = $this->_connect();

    $ldap_filter = str_replace('%email', $email, $this->filter);
    $result = ldap_search($conn, $this->base_dn, $ldap_filter, $this->fields);

    if ( $result ) {
      $info = ldap_get_entries($conn, $result);

      if ( $info['count'] >= 1 ) {

        $this->replytext = $info["0"][$this->attr_mailreplytext][0];
        $deliverymodes = $info["0"][$this->attr_deliverymode];
        if (is_array($deliverymodes)) {
          foreach ($deliverymodes as $mode) {
            if ($mode == "reply") {
              $this->enable = true;
            }
          }
        }
        $log = sprintf("Found the user '%s' in the database", $email);
        rcmail::write_log('qldap_vacation', $log);
        ldap_close($conn);
        return;
      }
    }

    $log = sprintf("Unique entry '%s' not found. Filter: %s Count: %s", $email, $ldap_filter, $info['count'] );
    rcmail::write_log('qldap_vacation', $log);
    ldap_close($conn);
  }

  function _save()
  {
    $rcmail = rcmail::get_instance();
    $email = $rcmail->user->get_identity()['email'];
    $conn = $this->_connect();

    $replytext = $_POST['vacation_body'];
    $enable = $_POST['vacation_enable'];
    if (! $replytext)
      $enable = false;

    $ldap_filter = str_replace('%email', $email, $this->filter);
    $result = ldap_search($conn, $this->base_dn, $ldap_filter, $this->fields);
    if ( $result )
      $info = ldap_get_entries($conn, $result);

    if (!$result || $info['count'] < 1) {
      $log = sprintf("Write: entry '%s' not found. Filter: %s Count: %s", $email, $ldap_filter, $info['count'] );
      rcmail::write_log('qldap_vacation', $log);
      ldap_close($conn);
      return false;
    }

    $dn = $info["0"]["dn"];
    $was_enabled = false;
    $deliverymodes = $info["0"][$this->attr_deliverymode];
    if (is_array($deliverymodes)) {
      foreach ($deliverymodes as $mode) {
        if ($mode == "reply") {
          $was_enabled = true;
        }
      }
    }

    if (! $replytext)
      $succ = ldap_mod_del($conn, $dn, [ $this->attr_mailreplytext => array() ]);
    else
      $succ = ldap_modify($conn, $dn, [ $this->attr_mailreplytext => [ $replytext ] ]);
    if (! $succ ) {
      $log = sprintf("Failed to update dn %s attr %s to %s: %s", $dn, $this->attr_mailreplytext, $replytext, ldap_error($conn));
      rcmail::write_log('qldap_vacation', $log);
      ldap_close($conn);
      return false;
    }

    if ($enable != $was_enabled) {
      $attrs = array( $this->attr_deliverymode => [ 'reply' ]);
      if ( $enable ) {
        $succ = ldap_mod_add($conn, $dn, $attrs);
      } else {
        $succ = ldap_mod_del($conn, $dn, $attrs);
      }
      if (! $succ ) {
        $log = sprintf("Failed to update %s: %s", $this->attr_deliverymode, ldap_error($conn));
        rcmail::write_log('qldap_vacation', $log);
        ldap_close($conn);
        return false;
      }
    }
    $log = "Succeeded to update LDAP";
    rcmail::write_log('qldap_vacation', $log);
    ldap_close($conn);
    return true;
  }
}
?>
