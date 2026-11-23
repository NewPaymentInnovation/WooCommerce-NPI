document.addEventListener( 'DOMContentLoaded', function () {
	var vars = window.localizeHostedSettingsVars || {};
	var typeFieldId = vars.typeFieldId ? String( vars.typeFieldId ) : '';
	var chfUrlFieldId = vars.chfUrlFieldId ? String( vars.chfUrlFieldId ) : '';

	if ( ! typeFieldId || ! chfUrlFieldId ) {
		return;
	}

	var typeField = document.getElementById( typeFieldId );
	var chfUrlField = document.getElementById( chfUrlFieldId );
	if ( ! typeField || ! chfUrlField ) {
		return;
	}

	var chfUrlRow = chfUrlField.closest( 'tr' );
	if ( ! chfUrlRow ) {
		return;
	}

	var toggleFields = function () {
		chfUrlRow.style.display = String( typeField.value ) === 'chf' ? 'table-row' : 'none';
	};

	toggleFields();
	typeField.addEventListener( 'change', toggleFields );
} );
