import { createRoot } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';
import { App } from './App';

import './style.scss';

domReady( () => {
	const container = document.getElementById( 'alpha-chat-admin-root' );
	if ( ! container ) {
		return;
	}
	createRoot( container ).render( <App /> );
} );
