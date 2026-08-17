import { useEffect, useMemo, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CheckboxControl,
	Notice,
	Spinner,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { adminApi, Faq, FaqImportPage } from '../api';

export function FaqsView() {
	const [ items, setItems ] = useState< Faq[] >( [] );
	const [ loading, setLoading ] = useState( true );
	const [ busy, setBusy ] = useState( false );
	const [ editing, setEditing ] = useState< Partial< Faq > | null >( null );
	const [ importUrls, setImportUrls ] = useState( '' );
	const [ importPages, setImportPages ] = useState< FaqImportPage[] >( [] );
	const [ selectedKeys, setSelectedKeys ] = useState<
		Record< string, boolean >
	>( {} );
	const [ fetching, setFetching ] = useState( false );
	const [ notice, setNotice ] = useState< {
		status: 'success' | 'error';
		message: string;
	} | null >( null );

	async function load() {
		setLoading( true );
		try {
			const response = await adminApi.listFaqs();
			setItems( response.items );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message:
					error instanceof Error ? error.message : String( error ),
			} );
		} finally {
			setLoading( false );
		}
	}

	useEffect( () => {
		load();
	}, [] );

	const selectedItems = useMemo( () => {
		const selected: { question: string; answer: string }[] = [];
		importPages.forEach( ( page ) => {
			page.pairs.forEach( ( pair ) => {
				if ( selectedKeys[ `${ page.url }::${ pair.question }` ] ) {
					selected.push( {
						question: pair.question,
						answer: pair.answer,
					} );
				}
			} );
		} );
		return selected;
	}, [ importPages, selectedKeys ] );

	function startNew() {
		setEditing( {
			question: '',
			answer: '',
			enabled: true,
			sort_order: items.length,
		} );
		setNotice( null );
	}

	async function save() {
		if ( ! editing ) {
			return;
		}
		const question = ( editing.question ?? '' ).trim();
		const answer = ( editing.answer ?? '' ).trim();
		if ( ! question || ! answer ) {
			setNotice( {
				status: 'error',
				message: __(
					'Question and answer are required.',
					'alpha-chat'
				),
			} );
			return;
		}

		setBusy( true );
		setNotice( null );
		try {
			if ( editing.id ) {
				await adminApi.updateFaq( editing.id, {
					question,
					answer,
					enabled: editing.enabled ?? true,
					sort_order: editing.sort_order ?? 0,
				} );
			} else {
				await adminApi.createFaq( {
					question,
					answer,
					enabled: editing.enabled ?? true,
					sort_order: editing.sort_order ?? 0,
				} );
			}
			setEditing( null );
			await load();
			setNotice( {
				status: 'success',
				message: __( 'Saved.', 'alpha-chat' ),
			} );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message:
					error instanceof Error ? error.message : String( error ),
			} );
		} finally {
			setBusy( false );
		}
	}

	async function remove( id: number ) {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( __( 'Delete this Q&A?', 'alpha-chat' ) ) ) {
			return;
		}
		try {
			await adminApi.deleteFaq( id );
			await load();
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message:
					error instanceof Error ? error.message : String( error ),
			} );
		}
	}

	function pairKey( url: string, question: string ): string {
		return `${ url }::${ question }`;
	}

	async function fetchImport() {
		const urls = importUrls
			.split( /\r?\n/ )
			.map( ( line ) => line.trim() )
			.filter( Boolean );
		if ( ! urls.length ) {
			setNotice( {
				status: 'error',
				message: __( 'Paste at least one page URL.', 'alpha-chat' ),
			} );
			return;
		}

		setFetching( true );
		setNotice( null );
		try {
			const response = await adminApi.previewFaqImport( urls );
			setImportPages( response.pages );
			const next: Record< string, boolean > = {};
			response.pages.forEach( ( page ) => {
				page.pairs.forEach( ( pair ) => {
					next[ pairKey( page.url, pair.question ) ] =
						! pair.duplicate;
				} );
			} );
			setSelectedKeys( next );
			const found = response.pages.reduce(
				( sum, page ) => sum + page.pairs.length,
				0
			);
			setNotice( {
				status: found ? 'success' : 'error',
				message: found
					? __(
							'Fetched Q&A from the page APIs. Review and import the pairs you want.',
							'alpha-chat'
					  )
					: __(
							'No Q&A pairs were found on those pages.',
							'alpha-chat'
					  ),
			} );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message:
					error instanceof Error ? error.message : String( error ),
			} );
		} finally {
			setFetching( false );
		}
	}

	async function importSelected() {
		if ( ! selectedItems.length ) {
			setNotice( {
				status: 'error',
				message: __(
					'Select at least one Q&A to import.',
					'alpha-chat'
				),
			} );
			return;
		}
		setBusy( true );
		setNotice( null );
		try {
			const result = await adminApi.importFaqs( selectedItems );
			setImportPages( [] );
			setSelectedKeys( {} );
			await load();
			setNotice( {
				status: 'success',
				message: `${ result.created } ${ __(
					'imported.',
					'alpha-chat'
				) }${
					result.skipped
						? ` ${ result.skipped } ${ __(
								'skipped as duplicates.',
								'alpha-chat'
						  ) }`
						: ''
				}`,
			} );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message:
					error instanceof Error ? error.message : String( error ),
			} );
		} finally {
			setBusy( false );
		}
	}

	async function toggleEnabled( faq: Faq ) {
		try {
			await adminApi.updateFaq( faq.id, { enabled: ! faq.enabled } );
			await load();
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message:
					error instanceof Error ? error.message : String( error ),
			} );
		}
	}

	return (
		<div className="alpha-chat-faqs">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			<Card>
				<CardBody>
					<div className="alpha-chat-faqs__toolbar">
						<div>
							<strong>
								{ __( 'Curated Q&A', 'alpha-chat' ) }
							</strong>
							<p className="alpha-chat-hint">
								{ __(
									'Always included in the assistant\'s context. Perfect for identity ("Who are you?"), pricing, contact info, policies — anything you want the bot to answer reliably.',
									'alpha-chat'
								) }
							</p>
						</div>
						<Button variant="primary" onClick={ startNew }>
							{ __( 'Add Q&A', 'alpha-chat' ) }
						</Button>
					</div>
				</CardBody>
			</Card>

			<Card className="alpha-chat-section" size="small">
				<CardBody className="alpha-chat-section__body">
					<div>
						<h2 className="alpha-chat-section__title">
							{ __( 'Import from pages', 'alpha-chat' ) }
						</h2>
						<p className="alpha-chat-section__desc">
							{ __(
								'Paste page URLs (one per line). Alpha Chat fetches them through the WordPress REST API when available, then reads FAQ schema, accordion markup, and question headings.',
								'alpha-chat'
							) }
						</p>
					</div>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Page URLs', 'alpha-chat' ) }
						value={ importUrls }
						onChange={ setImportUrls }
						rows={ 4 }
						placeholder="https://example.com/faq"
					/>
					<div className="alpha-chat-faqs__import-actions">
						<Button
							variant="secondary"
							onClick={ fetchImport }
							isBusy={ fetching }
						>
							{ __( 'Fetch Q&A', 'alpha-chat' ) }
						</Button>
						{ selectedItems.length > 0 && (
							<Button
								variant="primary"
								onClick={ importSelected }
								isBusy={ busy }
							>
								{ __( 'Import selected', 'alpha-chat' ) } (
								{ selectedItems.length })
							</Button>
						) }
					</div>
					{ importPages.map( ( page ) => (
						<div
							key={ page.url }
							className="alpha-chat-faqs__import-page"
						>
							<strong>{ page.title || page.url }</strong>
							<p className="alpha-chat-hint">{ page.url }</p>
							{ page.error && (
								<p className="alpha-chat-row-error">
									{ page.error }
								</p>
							) }
							{ ! page.error && page.pairs.length === 0 && (
								<p className="alpha-chat-hint">
									{ __(
										'No question-and-answer pairs found on this page.',
										'alpha-chat'
									) }
								</p>
							) }
							{ page.pairs.map( ( pair ) => {
								const key = pairKey( page.url, pair.question );
								return (
									<CheckboxControl
										key={ key }
										__nextHasNoMarginBottom
										className="alpha-chat-faqs__import-pair"
										checked={ !! selectedKeys[ key ] }
										disabled={ pair.duplicate }
										onChange={ ( checked ) =>
											setSelectedKeys( ( current ) => ( {
												...current,
												[ key ]: checked,
											} ) )
										}
										label={ pair.question }
										help={
											pair.duplicate
												? __(
														'Already in curated Q&A.',
														'alpha-chat'
												  )
												: pair.answer
										}
									/>
								);
							} ) }
						</div>
					) ) }
				</CardBody>
			</Card>

			{ editing && (
				<Card>
					<CardBody>
						<TextControl
							label={ __( 'Question', 'alpha-chat' ) }
							value={ editing.question ?? '' }
							onChange={ ( value ) =>
								setEditing( { ...editing, question: value } )
							}
							placeholder={ __(
								'e.g. Who are you?',
								'alpha-chat'
							) }
						/>
						<TextareaControl
							label={ __( 'Answer', 'alpha-chat' ) }
							value={ editing.answer ?? '' }
							onChange={ ( value ) =>
								setEditing( { ...editing, answer: value } )
							}
							rows={ 4 }
						/>
						<ToggleControl
							label={ __( 'Enabled', 'alpha-chat' ) }
							checked={ editing.enabled ?? true }
							onChange={ ( value ) =>
								setEditing( { ...editing, enabled: value } )
							}
						/>
						<div
							style={ {
								display: 'flex',
								gap: '0.5rem',
								marginTop: '0.5rem',
							} }
						>
							<Button
								variant="primary"
								onClick={ save }
								isBusy={ busy }
							>
								{ __( 'Save', 'alpha-chat' ) }
							</Button>
							<Button
								variant="tertiary"
								onClick={ () => setEditing( null ) }
							>
								{ __( 'Cancel', 'alpha-chat' ) }
							</Button>
						</div>
					</CardBody>
				</Card>
			) }

			<Card>
				<CardBody>
					{ loading && <Spinner /> }
					{ ! loading && items.length === 0 && (
						<p>
							{ __(
								'No Q&A yet. Add your first one above.',
								'alpha-chat'
							) }
						</p>
					) }
					{ ! loading && items.length > 0 && (
						<table className="alpha-chat-table">
							<thead>
								<tr>
									<th>{ __( 'Question', 'alpha-chat' ) }</th>
									<th>{ __( 'Answer', 'alpha-chat' ) }</th>
									<th style={ { width: 80 } }>
										{ __( 'Enabled', 'alpha-chat' ) }
									</th>
									<th />
								</tr>
							</thead>
							<tbody>
								{ items.map( ( faq ) => (
									<tr key={ faq.id }>
										<td
											style={ {
												maxWidth: 260,
												whiteSpace: 'pre-wrap',
											} }
										>
											<strong>{ faq.question }</strong>
										</td>
										<td
											style={ {
												maxWidth: 460,
												whiteSpace: 'pre-wrap',
											} }
										>
											{ faq.answer }
										</td>
										<td>
											<ToggleControl
												__nextHasNoMarginBottom
												label=""
												checked={ faq.enabled }
												onChange={ () =>
													toggleEnabled( faq )
												}
											/>
										</td>
										<td>
											<Button
												variant="link"
												onClick={ () =>
													setEditing( faq )
												}
											>
												{ __( 'Edit', 'alpha-chat' ) }
											</Button>
											<Button
												variant="link"
												isDestructive
												onClick={ () =>
													remove( faq.id )
												}
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
		</div>
	);
}
