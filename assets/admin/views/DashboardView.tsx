import { useEffect, useState } from '@wordpress/element';
import { Card, CardBody, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { adminApi, ChartData, Stats } from '../api';

export function DashboardView() {
	const [ stats, setStats ] = useState< Stats | null >( null );
	const [ chart, setChart ] = useState< ChartData | null >( null );
	const [ loading, setLoading ] = useState( true );

	useEffect( () => {
		let cancelled = false;
		Promise.all( [ adminApi.getSettings(), adminApi.getChart( 14 ) ] )
			.then( ( [ settings, chartData ] ) => {
				if ( cancelled ) return;
				setStats( settings.stats );
				setChart( chartData );
			} )
			.finally( () => ! cancelled && setLoading( false ) );
		return () => {
			cancelled = true;
		};
	}, [] );

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
