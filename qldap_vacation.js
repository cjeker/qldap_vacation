/**
 * qldap_vacation plugin JS code
 */
window.rcmail && rcmail.addEventListener('init', function(evt) {
	rcmail.register_command('plugin.qldap_vacation-save', function() {
		rcmail.gui_objects.vacationform.submit();
	}, true);

	$('input:not(:hidden):first').focus();
});
