import { useCallback, useEffect, useState } from '@wordpress/element';
import { Button, Card, CardBody, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { adminApi, ChartData, Stats } from '../api';

type QueueStats = { pending: number; in_progress: number; complete: number; failed: number };

export function DashboardView() {
	const [ stats, setStats ] = useState< Stats | null >( null );
	const [ chart, setChart ] = useState< ChartData | null >( null );
	const [ queue, setQueue ] = useState< QueueStats | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ processing, setProcessing ] = useState( false );

	const refreshQueue = useCallback( async () => {
		try {
			const q = await adminApi.getQueueStats();
			setQueue( q );
		} catch {
			/* ignore */
		}
	}, [] );

	useEffect( () => {
		let cancelled = false;
		Promise.all( [ adminApi.getSettings(), adminApi.getChart( 14 ), adminApi.getQueueStats() ] )
			.then( ( [ settings, chartData, queueStats ] ) => {
				if ( cancelled ) return;
				setStats( settings.stats );
				setChart( chartData );
				setQueue( queueStats );
			} )
			.finally( () => ! cancelled && setLoading( false ) );
		return () => {
			cancelled = true;
		};
	}, [] );

	useEffect( () => {
		if ( ! queue || queue.pending + queue.in_progress === 0 ) return;
		const id = window.setInterval( refreshQueue, 5000 );
		return () => window.clearInterval( id );
	}, [ queue, refreshQueue ] );

	async function processOnce() {
		setProcessing( true );
		try {
			await adminApi.processQueue();
			await Promise.all( [ refreshQueue(), adminApi.getSettings().then( ( s ) => setStats( s.stats ) ) ] );
		} catch {
			/* ignore */
		} finally {
			setProcessing( false );
		}
	}

	if ( loading ) {
		return <Spinner />;
	}

	const messageTotal = chart?.messages.reduce( ( a, b ) => a + b, 0 ) ?? 0;
	const sessionTotal = chart?.sessions.reduce( ( a, b ) => a + b, 0 ) ?? 0;

	return (
		<div className="alpha-chat-dashboard">
			<div className="alpha-chat-grid">
				<Card>
					<CardBody>
						<h2>{ __( 'Indexed chunks', 'alpha-chat' ) }</h2>
						<p className="alpha-chat-metric">{ stats?.chunks ?? 0 }</p>
					</CardBody>
				</Card>
				<Card>
					<CardBody>
						<h2>{ __( 'Indexed posts', 'alpha-chat' ) }</h2>
						<p className="alpha-chat-metric">{ stats?.posts ?? 0 }</p>
					</CardBody>
				</Card>
				<Card>
					<CardBody>
						<h2>{ __( 'Messages (14d)', 'alpha-chat' ) }</h2>
						<p className="alpha-chat-metric">{ messageTotal }</p>
					</CardBody>
				</Card>
				<Card>
					<CardBody>
						<h2>{ __( 'Sessions (14d)', 'alpha-chat' ) }</h2>
						<p className="alpha-chat-metric">{ sessionTotal }</p>
					</CardBody>
				</Card>
			</div>
			{ queue && (
				<Card>
					<CardBody>
						<div className="alpha-chat-queue">
							<div className="alpha-chat-queue__header">
								<h2>{ __( 'Indexing queue', 'alpha-chat' ) }</h2>
								<Button
									variant="secondary"
									onClick={ processOnce }
									isBusy={ processing }
									disabled={ queue.pending + queue.in_progress === 0 }
								>
									{ __( 'Process now', 'alpha-chat' ) }
								</Button>
							</div>
							<div className="alpha-chat-queue__grid">
								<div>
									<div className="alpha-chat-queue__label">{ __( 'Pending', 'alpha-chat' ) }</div>
									<div className="alpha-chat-queue__value">{ queue.pending }</div>
								</div>
								<div>
									<div className="alpha-chat-queue__label">{ __( 'In progress', 'alpha-chat' ) }</div>
									<div className="alpha-chat-queue__value">{ queue.in_progress }</div>
								</div>
								<div>
									<div className="alpha-chat-queue__label">{ __( 'Complete', 'alpha-chat' ) }</div>
									<div className="alpha-chat-queue__value">{ queue.complete }</div>
								</div>
								<div>
									<div className="alpha-chat-queue__label">{ __( 'Failed', 'alpha-chat' ) }</div>
									<div className="alpha-chat-queue__value is-error">{ queue.failed }</div>
								</div>
							</div>
						</div>
					</CardBody>
				</Card>
			) }
			{ chart && <SparklineTable chart={ chart } /> }
		</div>
	);
}

function SparklineTable( { chart }: { chart: ChartData } ) {
	const max = Math.max( 1, ...chart.messages );
	return (
		<Card className="alpha-chat-sparkline">
			<CardBody>
				<h2>{ __( 'Daily activity', 'alpha-chat' ) }</h2>
				<table>
					<thead>
						<tr>
							<th>{ __( 'Date', 'alpha-chat' ) }</th>
							<th>{ __( 'Messages', 'alpha-chat' ) }</th>
							<th>{ __( 'Sessions', 'alpha-chat' ) }</th>
							<th>{ __( 'Trend', 'alpha-chat' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ chart.labels.map( ( label, index ) => (
							<tr key={ label }>
								<td>{ label }</td>
								<td>{ chart.messages[ index ] ?? 0 }</td>
								<td>{ chart.sessions[ index ] ?? 0 }</td>
								<td>
									<span
										className="alpha-chat-sparkline__bar"
										style={ { width: `${ ( ( chart.messages[ index ] ?? 0 ) / max ) * 100 }%` } }
										title={ sprintf(
											/* translators: %s is message count */
											__( '%s messages', 'alpha-chat' ),
											chart.messages[ index ]?.toString() ?? '0'
										) }
									/>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</CardBody>
		</Card>
	);
}
