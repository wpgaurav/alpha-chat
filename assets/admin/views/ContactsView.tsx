import { useEffect, useState } from '@wordpress/element';
import { Button, Notice, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { adminApi, Contact } from '../api';

export function ContactsView() {
	const [ contacts, setContacts ] = useState< Contact[] >( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ loading, setLoading ] = useState( true );
	const [ page, setPage ] = useState( 1 );
	const [ error, setError ] = useState< string | null >( null );
	const perPage = 20;

	async function load() {
		setLoading( true );
		setError( null );
		try {
			const response = await adminApi.listContacts( page, perPage );
			setContacts( response.items );
			setTotal( response.total );
		} catch ( e ) {
			setError( e instanceof Error ? e.message : String( e ) );
		} finally {
			setLoading( false );
		}
	}

	useEffect( () => {
		load();
	}, [ page ] ); // eslint-disable-line react-hooks/exhaustive-deps

	async function remove( id: number ) {
		if ( ! window.confirm( __( 'Delete this contact?', 'alpha-chat' ) ) ) return;
		try {
			await adminApi.deleteContact( id );
			load();
		} catch ( e ) {
			setError( e instanceof Error ? e.message : String( e ) );
		}
	}

	const totalPages = Math.max( 1, Math.ceil( total / perPage ) );

	return (
		<div className="alpha-chat-contacts">
			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
			{ loading ? (
				<Spinner />
			) : contacts.length === 0 ? (
				<p>{ __( 'No contacts yet.', 'alpha-chat' ) }</p>
			) : (
				<>
					<table className="alpha-chat-table">
						<thead>
							<tr>
								<th>{ __( 'Date', 'alpha-chat' ) }</th>
								<th>{ __( 'Name', 'alpha-chat' ) }</th>
								<th>{ __( 'Email', 'alpha-chat' ) }</th>
								<th>{ __( 'Message', 'alpha-chat' ) }</th>
								<th />
							</tr>
						</thead>
						<tbody>
							{ contacts.map( ( c ) => (
								<tr key={ c.id }>
									<td>{ new Date( c.created_at + 'Z' ).toLocaleString() }</td>
									<td>{ c.name || '—' }</td>
									<td>
										<a href={ `mailto:${ c.email }` }>{ c.email }</a>
									</td>
									<td style={ { maxWidth: 480, whiteSpace: 'pre-wrap' } }>
										{ c.message }
									</td>
									<td>
										<Button
											variant="link"
											isDestructive
											onClick={ () => remove( c.id ) }
										>
											{ __( 'Delete', 'alpha-chat' ) }
										</Button>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
					<div className="alpha-chat-pagination">
						<Button
							variant="secondary"
							disabled={ page <= 1 }
							onClick={ () => setPage( ( p ) => p - 1 ) }
						>
							{ __( 'Previous', 'alpha-chat' ) }
						</Button>
						<span style={ { margin: '0 0.75rem' } }>
							{ sprintf(
								/* translators: 1: current page, 2: total pages */
								__( 'Page %1$d of %2$d', 'alpha-chat' ),
								page,
								totalPages
							) }
						</span>
						<Button
							variant="secondary"
							disabled={ page >= totalPages }
							onClick={ () => setPage( ( p ) => p + 1 ) }
						>
							{ __( 'Next', 'alpha-chat' ) }
						</Button>
					</div>
				</>
			) }
		</div>
	);
}
