import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { Button, Notice, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { adminApi, ChartData, QueueStats, Stats } from '../api';
import { useAdminNav } from '../nav';

type RangeDays = 7 | 14 | 30;

const KB_FILTER_KEY = 'alpha-chat-kb-indexed';

function formatCount( value: number ): string {
	return value.toLocaleString();
}

function formatDay( label: string ): string {
	const date = new Date( `${ label }T00:00:00Z` );
	if ( Number.isNaN( date.getTime() ) ) {
		return label;
	}
	return date.toLocaleDateString( undefined, {
		month: 'short',
		day: 'numeric',
	} );
}

export function DashboardView() {
	const { goTo } = useAdminNav();
	const [ days, setDays ] = useState< RangeDays >( 14 );
	const [ stats, setStats ] = useState< Stats | null >( null );
	const [ chart, setChart ] = useState< ChartData | null >( null );
	const [ queue, setQueue ] = useState< QueueStats | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ refreshing, setRefreshing ] = useState( false );
	const [ processing, setProcessing ] = useState( false );
	const [ selectedDay, setSelectedDay ] = useState< number | null >( null );
	const [ notice, setNotice ] = useState< {
		status: 'success' | 'error';
		message: string;
	} | null >( null );

	const load = useCallback( async ( nextDays: RangeDays, quiet = false ) => {
		if ( ! quiet ) {
			setRefreshing( true );
		}
		try {
			const data = await adminApi.getDashboard( nextDays );
			setStats( data.stats );
			setChart( data.chart );
			setQueue( data.queue );
			setNotice( null );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message:
					error instanceof Error ? error.message : String( error ),
			} );
		} finally {
			setLoading( false );
			setRefreshing( false );
		}
	}, [] );

	useEffect( () => {
		load( days );
	}, [ days, load ] );

	useEffect( () => {
		if ( ! queue || queue.pending + queue.in_progress === 0 ) {
			return;
		}
		const tick = () => {
			if ( document.visibilityState === 'hidden' ) {
				return;
			}
			adminApi
				.getQueueStats()
				.then( setQueue )
				.catch( () => undefined );
		};
		const id = window.setInterval( tick, 8000 );
		document.addEventListener( 'visibilitychange', tick );
		return () => {
			window.clearInterval( id );
			document.removeEventListener( 'visibilitychange', tick );
		};
	}, [ queue ] );

	async function processOnce() {
		setProcessing( true );
		try {
			const result = await adminApi.processQueue();
			setQueue( result.after );
			await load( days, true );
			setNotice( {
				status: 'success',
				message: sprintf(
					/* translators: %d is the number of jobs processed */
					__( 'Processed %d queue job(s).', 'alpha-chat' ),
					result.processed
				),
			} );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message:
					error instanceof Error ? error.message : String( error ),
			} );
		} finally {
			setProcessing( false );
		}
	}

	function openKnowledgeBase( indexed: 'any' | 'yes' | 'no' ) {
		try {
			window.sessionStorage.setItem( KB_FILTER_KEY, indexed );
		} catch {
			/* ignore */
		}
		goTo( 'knowledge-base' );
	}

	const messageTotal = useMemo(
		() => chart?.messages.reduce( ( a, b ) => a + b, 0 ) ?? 0,
		[ chart ]
	);
	const sessionTotal = useMemo(
		() => chart?.sessions.reduce( ( a, b ) => a + b, 0 ) ?? 0,
		[ chart ]
	);
	const busyQueue = ( queue?.pending ?? 0 ) + ( queue?.in_progress ?? 0 );

	if ( loading ) {
		return (
			<div className="alpha-chat-settings__loading">
				<Spinner />
			</div>
		);
	}

	return (
		<div className="alpha-chat-dashboard">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			<div className="alpha-chat-dashboard__toolbar">
				<div>
					<h2 className="alpha-chat-dashboard__title">
						{ __( 'Overview', 'alpha-chat' ) }
					</h2>
					<p className="alpha-chat-hint">
						{ __(
							'Click a card to open the matching screen. Change the range to reload activity.',
							'alpha-chat'
						) }
					</p>
				</div>
				<div className="alpha-chat-dashboard__actions">
					<div className="alpha-chat-range" role="group">
						{ ( [ 7, 14, 30 ] as RangeDays[] ).map( ( value ) => (
							<Button
								key={ value }
								size="compact"
								variant={
									days === value ? 'primary' : 'secondary'
								}
								onClick={ () => setDays( value ) }
							>
								{ sprintf(
									/* translators: %d is a number of days */
									__( '%dd', 'alpha-chat' ),
									value
								) }
							</Button>
						) ) }
					</div>
					<Button
						variant="tertiary"
						onClick={ () => load( days ) }
						isBusy={ refreshing }
					>
						{ __( 'Refresh', 'alpha-chat' ) }
					</Button>
				</div>
			</div>

			<div className="alpha-chat-statgrid">
				<button
					type="button"
					className="alpha-chat-stat"
					onClick={ () => openKnowledgeBase( 'yes' ) }
				>
					<span className="alpha-chat-stat__label">
						{ __( 'Indexed chunks', 'alpha-chat' ) }
					</span>
					<span className="alpha-chat-stat__value">
						{ formatCount( stats?.chunks ?? 0 ) }
					</span>
					<span className="alpha-chat-stat__hint">
						{ __( 'Open the knowledge base', 'alpha-chat' ) }
					</span>
				</button>
				<button
					type="button"
					className="alpha-chat-stat"
					onClick={ () => openKnowledgeBase( 'yes' ) }
				>
					<span className="alpha-chat-stat__label">
						{ __( 'Indexed posts', 'alpha-chat' ) }
					</span>
					<span className="alpha-chat-stat__value">
						{ formatCount( stats?.posts ?? 0 ) }
					</span>
					<span className="alpha-chat-stat__hint">
						{ __( 'Browse indexed content', 'alpha-chat' ) }
					</span>
				</button>
				<button
					type="button"
					className="alpha-chat-stat"
					onClick={ () => goTo( 'threads' ) }
				>
					<span className="alpha-chat-stat__label">
						{ sprintf(
							/* translators: %d is a number of days */
							__( 'Messages (%dd)', 'alpha-chat' ),
							days
						) }
					</span>
					<span className="alpha-chat-stat__value">
						{ formatCount( messageTotal ) }
					</span>
					<span className="alpha-chat-stat__hint">
						{ __( 'Open conversations', 'alpha-chat' ) }
					</span>
				</button>
				<button
					type="button"
					className="alpha-chat-stat"
					onClick={ () => goTo( 'threads' ) }
				>
					<span className="alpha-chat-stat__label">
						{ sprintf(
							/* translators: %d is a number of days */
							__( 'Sessions (%dd)', 'alpha-chat' ),
							days
						) }
					</span>
					<span className="alpha-chat-stat__value">
						{ formatCount( sessionTotal ) }
					</span>
					<span className="alpha-chat-stat__hint">
						{ __( 'Review recent chats', 'alpha-chat' ) }
					</span>
				</button>
			</div>

			{ queue && (
				<section className="alpha-chat-panel">
					<div className="alpha-chat-panel__header">
						<div>
							<h2>{ __( 'Indexing queue', 'alpha-chat' ) }</h2>
							<p className="alpha-chat-hint">
								{ __(
									'Pending jobs run in the background. Process now if the queue looks stuck.',
									'alpha-chat'
								) }
							</p>
						</div>
						<div className="alpha-chat-dashboard__actions">
							<Button
								variant="primary"
								onClick={ processOnce }
								isBusy={ processing }
								disabled={ busyQueue === 0 }
							>
								{ __( 'Process now', 'alpha-chat' ) }
							</Button>
							<Button
								variant="secondary"
								onClick={ () => openKnowledgeBase( 'no' ) }
							>
								{ __( 'Index remaining', 'alpha-chat' ) }
							</Button>
						</div>
					</div>
					<div className="alpha-chat-queue__grid">
						<button
							type="button"
							className="alpha-chat-queue__tile"
							onClick={ processOnce }
							disabled={ queue.pending === 0 || processing }
						>
							<span className="alpha-chat-queue__label">
								{ __( 'Pending', 'alpha-chat' ) }
							</span>
							<span className="alpha-chat-queue__value">
								{ formatCount( queue.pending ) }
							</span>
							<span className="alpha-chat-stat__hint">
								{ __( 'Run pending jobs', 'alpha-chat' ) }
							</span>
						</button>
						<div className="alpha-chat-queue__tile is-static">
							<span className="alpha-chat-queue__label">
								{ __( 'In progress', 'alpha-chat' ) }
							</span>
							<span className="alpha-chat-queue__value">
								{ formatCount( queue.in_progress ) }
							</span>
						</div>
						<button
							type="button"
							className="alpha-chat-queue__tile"
							onClick={ () => openKnowledgeBase( 'yes' ) }
						>
							<span className="alpha-chat-queue__label">
								{ __( 'Complete', 'alpha-chat' ) }
							</span>
							<span className="alpha-chat-queue__value">
								{ formatCount( queue.complete ) }
							</span>
							<span className="alpha-chat-stat__hint">
								{ __( 'View indexed posts', 'alpha-chat' ) }
							</span>
						</button>
						<button
							type="button"
							className={ `alpha-chat-queue__tile${
								queue.failed > 0 ? ' is-error' : ''
							}` }
							onClick={ () => openKnowledgeBase( 'yes' ) }
						>
							<span className="alpha-chat-queue__label">
								{ __( 'Failed', 'alpha-chat' ) }
							</span>
							<span className="alpha-chat-queue__value is-error">
								{ formatCount( queue.failed ) }
							</span>
							<span className="alpha-chat-stat__hint">
								{ __( 'Inspect index errors', 'alpha-chat' ) }
							</span>
						</button>
					</div>
				</section>
			) }

			{ chart && (
				<ActivityChart
					chart={ chart }
					selectedDay={ selectedDay }
					onSelect={ setSelectedDay }
					onOpenThreads={ () => goTo( 'threads' ) }
				/>
			) }
		</div>
	);
}

function ActivityChart( {
	chart,
	selectedDay,
	onSelect,
	onOpenThreads,
}: {
	chart: ChartData;
	selectedDay: number | null;
	onSelect: ( index: number | null ) => void;
	onOpenThreads: () => void;
} ) {
	const max = Math.max( 1, ...chart.messages, ...chart.sessions );
	const active = selectedDay ?? chart.labels.length - 1;
	const activeLabel = chart.labels[ active ] ?? '';
	const activeMessages = chart.messages[ active ] ?? 0;
	const activeSessions = chart.sessions[ active ] ?? 0;

	return (
		<section className="alpha-chat-panel">
			<div className="alpha-chat-panel__header">
				<div>
					<h2>{ __( 'Daily activity', 'alpha-chat' ) }</h2>
					<p className="alpha-chat-hint">
						{ sprintf(
							/* translators: 1: date, 2: messages, 3: sessions */
							__(
								'%1$s — %2$d messages, %3$d sessions. Click a bar to inspect that day.',
								'alpha-chat'
							),
							formatDay( activeLabel ),
							activeMessages,
							activeSessions
						) }
					</p>
				</div>
				<Button variant="secondary" onClick={ onOpenThreads }>
					{ __( 'Open conversations', 'alpha-chat' ) }
				</Button>
			</div>
			<div className="alpha-chat-chart">
				{ chart.labels.map( ( label, index ) => {
					const messages = chart.messages[ index ] ?? 0;
					const sessions = chart.sessions[ index ] ?? 0;
					const height = Math.max(
						6,
						Math.round( ( messages / max ) * 100 )
					);
					const sessionHeight = Math.max(
						4,
						Math.round( ( sessions / max ) * 100 )
					);
					return (
						<button
							key={ label }
							type="button"
							className={ `alpha-chat-chart__col${
								index === active ? ' is-active' : ''
							}` }
							onClick={ () =>
								onSelect( index === selectedDay ? null : index )
							}
							aria-pressed={ index === active }
							aria-label={ sprintf(
								/* translators: 1: date, 2: messages, 3: sessions */
								__(
									'%1$s, %2$d messages, %3$d sessions',
									'alpha-chat'
								),
								formatDay( label ),
								messages,
								sessions
							) }
						>
							<span className="alpha-chat-chart__bars">
								<span
									className="alpha-chat-chart__bar is-messages"
									style={ { height: `${ height }%` } }
								/>
								<span
									className="alpha-chat-chart__bar is-sessions"
									style={ { height: `${ sessionHeight }%` } }
								/>
							</span>
							<span className="alpha-chat-chart__label">
								{ formatDay( label ) }
							</span>
						</button>
					);
				} ) }
			</div>
		</section>
	);
}
