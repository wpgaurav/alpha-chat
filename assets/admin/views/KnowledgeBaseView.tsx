import { useCallback, useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Flex,
	FlexItem,
	Notice,
	SearchControl,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { adminApi, KbItem } from '../api';

const PER_PAGE = 20;

export function KnowledgeBaseView() {
	const [ items, setItems ] = useState< KbItem[] >( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ totalPages, setTotalPages ] = useState( 1 );
	const [ page, setPage ] = useState( 1 );
	const [ search, setSearch ] = useState( '' );
	const [ postType, setPostType ] = useState( 'any' );
	const [ postTypes, setPostTypes ] = useState< { label: string; value: string }[] >( [
		{ label: __( 'Any', 'alpha-chat' ), value: 'any' },
	] );
	const [ indexedFilter, setIndexedFilter ] = useState< 'any' | 'yes' | 'no' >( 'any' );
	const [ loading, setLoading ] = useState( true );
	const [ working, setWorking ] = useState< Record< number, boolean > >( {} );
	const [ selected, setSelected ] = useState< Set< number > >( new Set() );
	const [ bulkBusy, setBulkBusy ] = useState( false );
	const [ notice, setNotice ] = useState< { status: 'success' | 'error'; message: string } | null >( null );

	const load = useCallback( async () => {
		setLoading( true );
		try {
			const result = await adminApi.listKnowledgeBase( {
				search: search || undefined,
				post_type: postType === 'any' ? undefined : postType,
				indexed: indexedFilter,
				page,
				per_page: PER_PAGE,
			} );
			setItems( result.items );
			setTotal( result.total );
			setTotalPages( Math.max( 1, result.total_pages ) );
			setSelected( new Set() );
		} catch ( error ) {
			const message = error instanceof Error ? error.message : String( error );
			setNotice( { status: 'error', message } );
		} finally {
			setLoading( false );
		}
	}, [ search, postType, indexedFilter, page ] );

	useEffect( () => {
		load();
	}, [ load ] );

	useEffect( () => {
		adminApi
			.listPostTypes()
			.then( ( response ) => {
				setPostTypes( [
					{ label: __( 'Any', 'alpha-chat' ), value: 'any' },
					...response.items.map( ( item ) => ( {
						label: item.label,
						value: item.slug,
					} ) ),
				] );
			} )
			.catch( () => {
				/* leave default */
			} );
	}, [] );

	function setBusy( postId: number, state: boolean ) {
		setWorking( ( previous ) => ( { ...previous, [ postId ]: state } ) );
	}

	async function addItem( postId: number ) {
		setBusy( postId, true );
		setNotice( null );
		try {
			await adminApi.addToKnowledgeBase( postId );
			setNotice( {
				status: 'success',
				message: __( 'Indexing queued. It will finish in the background.', 'alpha-chat' ),
			} );
			await load();
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message: error instanceof Error ? error.message : String( error ),
			} );
		} finally {
			setBusy( postId, false );
		}
	}

	async function removeItem( postId: number ) {
		setBusy( postId, true );
		setNotice( null );
		try {
			await adminApi.removeFromKnowledgeBase( postId );
			setNotice( { status: 'success', message: __( 'Removed from knowledge base.', 'alpha-chat' ) } );
			await load();
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message: error instanceof Error ? error.message : String( error ),
			} );
		} finally {
			setBusy( postId, false );
		}
	}

	async function reindexAll() {
		setNotice( null );
		try {
			const result = await adminApi.reindexAll();
			setNotice( {
				status: 'success',
				message: sprintf(
					/* translators: %d is the number of items queued */
					__( 'Queued %d item(s) for reindexing.', 'alpha-chat' ),
					result.queued
				),
			} );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message: error instanceof Error ? error.message : String( error ),
			} );
		}
	}

	async function indexRemaining() {
		setBulkBusy( true );
		setNotice( null );
		try {
			const result = await adminApi.indexRemaining();
			setNotice( {
				status: 'success',
				message: sprintf(
					/* translators: %d is the number of items queued */
					__( 'Queued %d remaining item(s) for indexing.', 'alpha-chat' ),
					result.queued
				),
			} );
			await load();
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message: error instanceof Error ? error.message : String( error ),
			} );
		} finally {
			setBulkBusy( false );
		}
	}

	async function runBulk( action: 'add' | 'remove' ) {
		const ids = Array.from( selected );
		if ( ids.length === 0 ) return;
		setBulkBusy( true );
		setNotice( null );
		try {
			const result = await adminApi.bulkKnowledgeBase( action, ids );
			setNotice( {
				status: 'success',
				message:
					action === 'add'
						? sprintf(
							/* translators: %d: number queued */
							__( 'Queued %d item(s) for indexing.', 'alpha-chat' ),
							result.queued
						)
						: sprintf(
							/* translators: %d: number removed */
							__( 'Removed %d item(s).', 'alpha-chat' ),
							result.removed
						),
			} );
			setSelected( new Set() );
			await load();
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message: error instanceof Error ? error.message : String( error ),
			} );
		} finally {
			setBulkBusy( false );
		}
	}

	function toggleOne( id: number ) {
		setSelected( ( prev ) => {
			const next = new Set( prev );
			if ( next.has( id ) ) {
				next.delete( id );
			} else {
				next.add( id );
			}
			return next;
		} );
	}

	function toggleAll() {
		setSelected( ( prev ) => {
			if ( prev.size === items.length ) return new Set();
			return new Set( items.map( ( item ) => item.id ) );
		} );
	}

	return (
		<div className="alpha-chat-kb">
			{ notice && (
				<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
					{ notice.message }
				</Notice>
			) }

			<Card>
				<CardBody>
					<Flex align="center" gap={ 4 } wrap>
						<FlexItem isBlock>
							<SearchControl
								__nextHasNoMarginBottom
								label={ __( 'Search', 'alpha-chat' ) }
								value={ search }
								onChange={ ( value ) => {
									setPage( 1 );
									setSearch( value );
								} }
							/>
						</FlexItem>
						<FlexItem>
							<SelectControl
								__nextHasNoMarginBottom
								label={ __( 'Type', 'alpha-chat' ) }
								value={ postType }
								options={ postTypes }
								onChange={ ( value ) => {
									setPage( 1 );
									setPostType( value );
								} }
							/>
						</FlexItem>
						<FlexItem>
							<SelectControl
								__nextHasNoMarginBottom
								label={ __( 'Indexed', 'alpha-chat' ) }
								value={ indexedFilter }
								options={ [
									{ label: __( 'All', 'alpha-chat' ), value: 'any' },
									{ label: __( 'Not indexed', 'alpha-chat' ), value: 'no' },
									{ label: __( 'Indexed', 'alpha-chat' ), value: 'yes' },
								] }
								onChange={ ( value ) => {
									setPage( 1 );
									setIndexedFilter( value as 'any' | 'yes' | 'no' );
								} }
							/>
						</FlexItem>
						<FlexItem>
							<Button
								variant="primary"
								onClick={ indexRemaining }
								isBusy={ bulkBusy }
							>
								{ __( 'Index remaining', 'alpha-chat' ) }
							</Button>
						</FlexItem>
						<FlexItem>
							<Button variant="secondary" onClick={ reindexAll }>
								{ __( 'Reindex all', 'alpha-chat' ) }
							</Button>
						</FlexItem>
					</Flex>
				</CardBody>
			</Card>

			{ selected.size > 0 && (
				<Card>
					<CardBody>
						<Flex align="center" gap={ 3 }>
							<FlexItem>
								<strong>
									{ sprintf(
										/* translators: %d: number selected */
										__( '%d selected', 'alpha-chat' ),
										selected.size
									) }
								</strong>
							</FlexItem>
							<FlexItem>
								<Button
									variant="primary"
									onClick={ () => runBulk( 'add' ) }
									isBusy={ bulkBusy }
								>
									{ __( 'Index selected', 'alpha-chat' ) }
								</Button>
							</FlexItem>
							<FlexItem>
								<Button
									variant="secondary"
									isDestructive
									onClick={ () => runBulk( 'remove' ) }
									isBusy={ bulkBusy }
								>
									{ __( 'Remove selected', 'alpha-chat' ) }
								</Button>
							</FlexItem>
							<FlexItem>
								<Button variant="tertiary" onClick={ () => setSelected( new Set() ) }>
									{ __( 'Clear', 'alpha-chat' ) }
								</Button>
							</FlexItem>
						</Flex>
					</CardBody>
				</Card>
			) }

			<Card>
				<CardBody>
					{ loading ? (
						<Spinner />
					) : (
						<table className="widefat striped alpha-chat-table">
							<thead>
								<tr>
									<th style={ { width: 32 } }>
										<input
											type="checkbox"
											checked={ items.length > 0 && selected.size === items.length }
											onChange={ toggleAll }
											aria-label={ __( 'Select all on page', 'alpha-chat' ) }
										/>
									</th>
									<th>{ __( 'Title', 'alpha-chat' ) }</th>
									<th>{ __( 'Type', 'alpha-chat' ) }</th>
									<th>{ __( 'Status', 'alpha-chat' ) }</th>
									<th>{ __( 'Chunks', 'alpha-chat' ) }</th>
									<th>{ __( 'Indexed', 'alpha-chat' ) }</th>
									<th aria-label={ __( 'Actions', 'alpha-chat' ) } />
								</tr>
							</thead>
							<tbody>
								{ items.length === 0 && (
									<tr>
										<td colSpan={ 7 }>
											{ __( 'No posts match.', 'alpha-chat' ) }
										</td>
									</tr>
								) }
								{ items.map( ( item ) => (
									<tr key={ item.id } className={ selected.has( item.id ) ? 'is-selected' : '' }>
										<td>
											<input
												type="checkbox"
												checked={ selected.has( item.id ) }
												onChange={ () => toggleOne( item.id ) }
												aria-label={ item.title }
											/>
										</td>
										<td>
											<a href={ item.url } target="_blank" rel="noreferrer">
												{ item.title || __( '(no title)', 'alpha-chat' ) }
											</a>
											{ item.index_error && (
												<div className="alpha-chat-row-error">
													{ item.index_error }
												</div>
											) }
										</td>
										<td>{ item.type }</td>
										<td>{ item.status }</td>
										<td>{ item.chunk_count }</td>
										<td>
											{ item.indexed
												? new Date( item.last_indexed * 1000 ).toLocaleString()
												: '—' }
										</td>
										<td>
											{ item.indexed ? (
												<Button
													variant="tertiary"
													isDestructive
													isBusy={ !! working[ item.id ] }
													onClick={ () => removeItem( item.id ) }
												>
													{ __( 'Remove', 'alpha-chat' ) }
												</Button>
											) : (
												<Button
													variant="secondary"
													isBusy={ !! working[ item.id ] }
													onClick={ () => addItem( item.id ) }
												>
													{ __( 'Add', 'alpha-chat' ) }
												</Button>
											) }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) }
				</CardBody>
			</Card>

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
