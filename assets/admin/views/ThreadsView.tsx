import { useCallback, useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Flex,
	FlexItem,
	Notice,
	Spinner,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { adminApi, Message, Thread } from '../api';

const PER_PAGE = 20;

export function ThreadsView() {
	const [ threads, setThreads ] = useState< Thread[] >( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ page, setPage ] = useState( 1 );
	const [ loading, setLoading ] = useState( true );
	const [ selected, setSelected ] = useState< Thread | null >( null );
	const [ messages, setMessages ] = useState< Message[] >( [] );
	const [ messagesLoading, setMessagesLoading ] = useState( false );
	const [ notice, setNotice ] = useState< { status: 'success' | 'error'; message: string } | null >( null );

	const load = useCallback( async () => {
		setLoading( true );
		try {
			const result = await adminApi.listThreads( page, PER_PAGE );
			setThreads( result.items );
			setTotal( result.total );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message: error instanceof Error ? error.message : String( error ),
			} );
		} finally {
			setLoading( false );
		}
	}, [ page ] );

	useEffect( () => {
		load();
	}, [ load ] );

	async function openThread( thread: Thread ) {
		setSelected( thread );
		setMessages( [] );
		setMessagesLoading( true );
		try {
			const result = await adminApi.getThread( thread.id );
			setMessages( result.messages );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message: error instanceof Error ? error.message : String( error ),
			} );
		} finally {
			setMessagesLoading( false );
		}
	}

	async function deleteThread( thread: Thread ) {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( __( 'Delete this conversation permanently?', 'alpha-chat' ) ) ) {
			return;
		}
		try {
			await adminApi.deleteThread( thread.id );
			setNotice( { status: 'success', message: __( 'Conversation deleted.', 'alpha-chat' ) } );
			if ( selected?.id === thread.id ) {
				setSelected( null );
				setMessages( [] );
			}
			await load();
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message: error instanceof Error ? error.message : String( error ),
			} );
		}
	}

	const totalPages = Math.max( 1, Math.ceil( total / PER_PAGE ) );
	const exportUrl = `${ window.alphaChatAdmin?.restUrl ?? '' }/threads/export?_wpnonce=${ encodeURIComponent(
		window.alphaChatAdmin?.restNonce ?? ''
	) }`;

	return (
		<div className="alpha-chat-threads">
			{ notice && (
				<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
					{ notice.message }
				</Notice>
			) }

			<Flex justify="flex-end" className="alpha-chat-threads__toolbar">
				<FlexItem>
					<Button variant="secondary" href={ exportUrl }>
						{ __( 'Export CSV', 'alpha-chat' ) }
					</Button>
				</FlexItem>
			</Flex>

			<div className="alpha-chat-threads__layout">
				<Card className="alpha-chat-threads__list">
					<CardBody>
						{ loading ? (
							<Spinner />
						) : (
							<table className="widefat striped alpha-chat-table">
								<thead>
									<tr>
										<th>{ __( 'Title', 'alpha-chat' ) }</th>
										<th>{ __( 'Messages', 'alpha-chat' ) }</th>
										<th>{ __( 'Updated', 'alpha-chat' ) }</th>
										<th aria-label={ __( 'Actions', 'alpha-chat' ) } />
									</tr>
								</thead>
								<tbody>
									{ threads.length === 0 && (
										<tr>
											<td colSpan={ 4 }>
												{ __( 'No conversations yet.', 'alpha-chat' ) }
											</td>
										</tr>
									) }
									{ threads.map( ( thread ) => (
										<tr
											key={ thread.id }
											className={
												selected?.id === thread.id
													? 'is-selected'
													: undefined
											}
										>
											<td>
												<Button
													variant="link"
													onClick={ () => openThread( thread ) }
												>
													{ thread.title || thread.uuid }
												</Button>
											</td>
											<td>{ thread.message_count }</td>
											<td>{ thread.updated_at }</td>
											<td>
												<Button
													variant="tertiary"
													isDestructive
													onClick={ () => deleteThread( thread ) }
												>
													{ __( 'Delete', 'alpha-chat' ) }
												</Button>
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						) }
					</CardBody>
				</Card>

				<Card className="alpha-chat-threads__detail">
					<CardBody>
						{ ! selected && (
							<p>{ __( 'Select a conversation to view its messages.', 'alpha-chat' ) }</p>
						) }
						{ selected && (
							<>
								<h2>{ selected.title || selected.uuid }</h2>
								<p className="alpha-chat-threads__meta">
									{ sprintf(
										/* translators: 1: created date, 2: uuid */
										__( 'Started %1$s · %2$s', 'alpha-chat' ),
										selected.created_at,
										selected.uuid
									) }
								</p>
								{ messagesLoading ? (
									<Spinner />
								) : (
									<ul className="alpha-chat-messages">
										{ messages.map( ( message ) => (
											<li
												key={ message.id }
												className={ `alpha-chat-messages__item is-${ message.role }` }
											>
												<div className="alpha-chat-messages__role">
													{ message.role }
												</div>
												<div className="alpha-chat-messages__content">
													{ message.content }
												</div>
												<div className="alpha-chat-messages__meta">
													{ message.created_at }
												</div>
											</li>
										) ) }
									</ul>
								) }
							</>
						) }
					</CardBody>
				</Card>
			</div>

			<Flex justify="space-between" align="center" className="alpha-chat-pagination">
				<FlexItem>
					{ sprintf(
						/* translators: 1: current page, 2: total pages, 3: total items */
						__( 'Page %1$d of %2$d (%3$d items)', 'alpha-chat' ),
						page,
						totalPages,
						total
					) }
				</FlexItem>
				<FlexItem>
					<Button
						variant="secondary"
						disabled={ page <= 1 }
						onClick={ () => setPage( ( current ) => Math.max( 1, current - 1 ) ) }
					>
						{ __( 'Previous', 'alpha-chat' ) }
					</Button>{ ' ' }
					<Button
						variant="secondary"
						disabled={ page >= totalPages }
						onClick={ () => setPage( ( current ) => Math.min( totalPages, current + 1 ) ) }
					>
						{ __( 'Next', 'alpha-chat' ) }
					</Button>
				</FlexItem>
			</Flex>
		</div>
	);
}

declare global {
	interface Window {
		alphaChatAdmin?: {
			restUrl: string;
			restNonce: string;
			version: string;
		};
	}
}
