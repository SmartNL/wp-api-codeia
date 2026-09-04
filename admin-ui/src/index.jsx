import { createRoot } from '@wordpress/element';
import App from './App.jsx';

const container = document.getElementById( 'codeia-admin-root' );

if ( container ) {
	createRoot( container ).render( <App page={ container.dataset.page || 'codeia-api' } /> );
}
