import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Flex,
	FlexItem,
	Notice,
	SelectControl,
	Spinner,
} from '@wordpress/components';

import { adminApi, type LogEntry, type LogList } from '../api';

const PER_PAGE = 25;

function formatContext( context: Record< string, unknown > | null ): string {
	if ( ! context || ! Object.keys( context ).length ) {
		return '';
	}
	try {
		return JSON.stringify( context, null, 2 );
	} catch {
		return '';
	}
}

export function LogsView() {
	const [ data, setData ] = useState< LogList | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ page, setPage ] = useState( 1 );
	const [ level, setLevel ] = useState( '' );
	const [ expanded, setExpanded ] = useState< number | null >( null );
	const [ error, setError ] = useState< string | null >( null );

	async function load() {
		setLoading( true );
		setError( null );
		try {
			setData(
				await adminApi.listLogs( {
					page,
					per_page: PER_PAGE,
					level: level || undefined,
				} )
			);
		} catch ( e ) {
			setError( e instanceof Error ? e.message : String( e ) );
		} finally {
			setLoading( false );
		}
	}

	useEffect( () => {
		load();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ page, level ] );

	async function clearAll() {
		if (
			// eslint-disable-next-line no-alert
			! window.confirm(
				__( 'Delete every recorded log entry?', 'alpha-chat' )
			)
		) {
			return;
		}
		try {
			await adminApi.clearLogs();
			setPage( 1 );
			await load();
		} catch ( e ) {
			setError( e instanceof Error ? e.message : String( e ) );
		}
	}

	const items: LogEntry[] = data?.items ?? [];
	const total = data?.total ?? 0;
	const totalPages = Math.max( 1, Math.ceil( total / PER_PAGE ) );

	return (
		<div className="alpha-chat-logs">
			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			<Flex justify="space-between" align="flex-end">
				<FlexItem>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Level', 'alpha-chat' ) }
						value={ level }
						options={ [
							{ label: __( 'All', 'alpha-chat' ), value: '' },
							{
								label: __( 'Errors', 'alpha-chat' ),
								value: 'error',
							},
							{
								label: __( 'Warnings', 'alpha-chat' ),
								value: 'warning',
							},
						] }
						onChange={ ( v ) => {
							setPage( 1 );
							setLevel( v );
						} }
					/>
				</FlexItem>
				<FlexItem>
					<Flex gap={ 2 }>
						<FlexItem>
							<Button variant="secondary" onClick={ load }>
								{ __( 'Refresh', 'alpha-chat' ) }
							</Button>
						</FlexItem>
						<FlexItem>
							<Button
								isDestructive
								variant="secondary"
								onClick={ clearAll }
								disabled={ ! total }
							>
								{ __( 'Clear log', 'alpha-chat' ) }
							</Button>
						</FlexItem>
					</Flex>
				</FlexItem>
			</Flex>

			{ data && (
				<p className="alpha-chat-logs__summary">
					{ sprintf(
						/* translators: 1: error count, 2: warning count */
						__( '%1$d errors, %2$d warnings', 'alpha-chat' ),
						data.counts.error,
						data.counts.warning
					) }
				</p>
			) }

			{ loading && <Spinner /> }

			{ ! loading && ! items.length && (
				<Notice status="success" isDismissible={ false }>
					{ __(
						'Nothing logged. Errors and warnings from chat, indexing, and provider calls appear here.',
						'alpha-chat'
					) }
				</Notice>
			) }

			{ ! loading && !! items.length && (
				<table className="widefat striped alpha-chat-logs__table">
					<thead>
						<tr>
							<th>{ __( 'When', 'alpha-chat' ) }</th>
							<th>{ __( 'Level', 'alpha-chat' ) }</th>
							<th>{ __( 'Message', 'alpha-chat' ) }</th>
							<th>{ __( 'Source', 'alpha-chat' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ items.map( ( item ) => {
							const context = formatContext( item.context );
							const isOpen = expanded === item.id;
							return (
								<tr key={ item.id }>
									<td>{ item.created_at }</td>
									<td>
										<span
											className={ `alpha-chat-logs__level is-${ item.level }` }
										>
											{ item.level }
										</span>
									</td>
									<td>
										{ item.message }
										{ !! context && (
											<>
												{ ' ' }
												<Button
													variant="link"
													onClick={ () =>
														setExpanded(
															isOpen
																? null
																: item.id
														)
													}
												>
													{ isOpen
														? __(
																'Hide details',
																'alpha-chat'
														  )
														: __(
																'Details',
																'alpha-chat'
														  ) }
												</Button>
												{ isOpen && (
													<pre className="alpha-chat-logs__context">
														{ context }
													</pre>
												) }
											</>
										) }
									</td>
									<td>
										<code>{ item.source || '—' }</code>
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
			) }

			{ totalPages > 1 && (
				<Flex justify="center" gap={ 2 }>
					<FlexItem>
						<Button
							variant="secondary"
							disabled={ page <= 1 }
							onClick={ () => setPage( ( p ) => p - 1 ) }
						>
							{ __( 'Previous', 'alpha-chat' ) }
						</Button>
					</FlexItem>
					<FlexItem>
						{ sprintf(
							/* translators: 1: current page, 2: total pages */
							__( 'Page %1$d of %2$d', 'alpha-chat' ),
							page,
							totalPages
						) }
					</FlexItem>
					<FlexItem>
						<Button
							variant="secondary"
							disabled={ page >= totalPages }
							onClick={ () => setPage( ( p ) => p + 1 ) }
						>
							{ __( 'Next', 'alpha-chat' ) }
						</Button>
					</FlexItem>
				</Flex>
			) }
		</div>
	);
}
