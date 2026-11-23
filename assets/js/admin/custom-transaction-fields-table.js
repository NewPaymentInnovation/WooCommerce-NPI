document.addEventListener( 'DOMContentLoaded', function () {
	var vars = window.localizeCustomTransactionFieldsVars || {};
	var fieldId = vars.fieldId ? String( vars.fieldId ) : '';

	if ( ! fieldId ) {
		return;
	}

	var textarea = document.getElementById( fieldId );
	if ( ! textarea ) {
		return;
	}

	var row = textarea.closest( 'tr' );
	if ( ! row ) {
		return;
	}

	row.classList.add( 'wcpm-custom-transaction-fields-row' );
	textarea.style.display = 'none';

	var wrapper = document.createElement( 'div' );
	wrapper.className = 'wcpm-custom-transaction-fields';

	var table = document.createElement( 'table' );
	table.className = 'widefat striped wcpm-custom-transaction-fields-table';

	var thead = document.createElement( 'thead' );
	thead.innerHTML = '<tr><th>Field Key</th><th>Field Value</th><th class="wcpm-actions-col">Actions</th></tr>';
	table.appendChild( thead );

	var tbody = document.createElement( 'tbody' );
	table.appendChild( tbody );

	var actions = document.createElement( 'div' );
	actions.className = 'wcpm-custom-transaction-fields-actions';

	var addButton = document.createElement( 'button' );
	addButton.type = 'button';
	addButton.className = 'button';
	addButton.textContent = 'Add Field';
	actions.appendChild( addButton );

	wrapper.appendChild( table );
	wrapper.appendChild( actions );
	textarea.parentNode.appendChild( wrapper );

	var parseStoredFields = function () {
		var raw = String( textarea.value || '' ).trim();
		if ( raw === '' ) {
			return [];
		}

		try {
			var parsed = JSON.parse( raw );
			if ( Array.isArray( parsed ) ) {
				return parsed
					.filter( function ( entry ) {
						return entry && typeof entry === 'object';
					} )
					.map( function ( entry ) {
						return {
							key: entry.key ? String( entry.key ) : '',
							value: entry.value !== undefined && entry.value !== null ? String( entry.value ) : ''
						};
					} );
			}

			if ( parsed && typeof parsed === 'object' ) {
				return Object.keys( parsed ).map( function ( key ) {
					return {
						key: String( key ),
						value: parsed[ key ] !== undefined && parsed[ key ] !== null ? String( parsed[ key ] ) : ''
					};
				} );
			}
		} catch ( e ) {
			return [];
		}

		return [];
	};

	var syncTextarea = function () {
		var payload = [];
		var rows = tbody.querySelectorAll( 'tr' );
		rows.forEach( function ( tr ) {
			var keyInput = tr.querySelector( '.wcpm-key' );
			var valueInput = tr.querySelector( '.wcpm-value' );
			var key = keyInput ? String( keyInput.value || '' ).trim() : '';
			if ( key === '' ) {
				return;
			}

			payload.push( {
				key: key,
				value: valueInput ? String( valueInput.value || '' ) : ''
			} );
		} );

		textarea.value = JSON.stringify( payload );
	};

	var createRow = function ( key, value ) {
		var tr = document.createElement( 'tr' );

		var keyTd = document.createElement( 'td' );
		var keyInput = document.createElement( 'input' );
		keyInput.type = 'text';
		keyInput.className = 'regular-text wcpm-key';
		keyInput.value = key || '';
		keyInput.placeholder = 'e.g. customerTag';
		keyInput.addEventListener( 'input', syncTextarea );
		keyTd.appendChild( keyInput );

		var valueTd = document.createElement( 'td' );
		var valueInput = document.createElement( 'input' );
		valueInput.type = 'text';
		valueInput.className = 'regular-text wcpm-value';
		valueInput.value = value || '';
		valueInput.placeholder = 'e.g. wholesale';
		valueInput.addEventListener( 'input', syncTextarea );
		valueTd.appendChild( valueInput );

		var actionTd = document.createElement( 'td' );
		actionTd.className = 'wcpm-actions-col';
		var removeButton = document.createElement( 'button' );
		removeButton.type = 'button';
		removeButton.className = 'button-link-delete';
		removeButton.textContent = 'Remove';
		removeButton.addEventListener( 'click', function () {
			tr.remove();
			syncTextarea();
		} );
		actionTd.appendChild( removeButton );

		tr.appendChild( keyTd );
		tr.appendChild( valueTd );
		tr.appendChild( actionTd );
		tbody.appendChild( tr );
	};

	var initialFields = parseStoredFields();
	if ( initialFields.length > 0 ) {
		initialFields.forEach( function ( field ) {
			createRow( field.key, field.value );
		} );
	} else {
		createRow( '', '' );
	}

	addButton.addEventListener( 'click', function () {
		createRow( '', '' );
		syncTextarea();
	} );

	syncTextarea();
} );
