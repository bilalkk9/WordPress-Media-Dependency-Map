/* Media Dependency Map — Bilal. */
( function () {
	'use strict';
	const progress = document.getElementById( 'mdm-progress' );
	if ( ! progress || progress.dataset.active !== '1' ) {
		return;
	}
	async function batch() {
		try {
			const body = new URLSearchParams( { action: 'mdm_batch', nonce: mdmAdmin.nonce } );
			const response = await fetch( mdmAdmin.url, { method: 'POST', credentials: 'same-origin', body } );
			const result = await response.json();
			if ( ! response.ok || ! result.success || result.data.worker_error ) {
				throw new Error( 'Batch failed' );
			}
			if ( ! result.data.active_run ) {
				window.location.reload();
				return;
			}
			progress.textContent = mdmAdmin.progress;
			window.setTimeout( batch, 1200 );
		} catch ( error ) {
			progress.textContent = mdmAdmin.error;
		}
	}
	batch();
}() );
