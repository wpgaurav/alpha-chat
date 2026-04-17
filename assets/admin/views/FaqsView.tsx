import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Notice,
	Spinner,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { adminApi, Faq } from '../api';

export function FaqsView() {
	const [ items, setItems ] = useState< Faq[] >( [] );
	const [ loading, setLoading ] = useState( true );
	const [ busy, setBusy ] = useState( false );
	const [ editing, setEditing ] = useState< Partial< Faq > | null >( null );
	const [ notice, setNotice ] = useState< { status: 'success' | 'error'; message: string } | null >( null );

	async function load() {
		setLoading( true );
		try {
			const response = await adminApi.listFaqs();
			setItems( response.items );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message: error instanceof Error ? error.message : String( error ),
			} );
		} finally {
			setLoading( false );
		}
	}

	useEffect( () => {
		load();
	}, [] );

	function startNew() {
		setEditing( { question: '', answer: '', enabled: true, sort_order: items.length } );
		setNotice( null );
	}

	async function save() {
		if ( ! editing ) return;
		const question = ( editing.question ?? '' ).trim();
		const answer = ( editing.answer ?? '' ).trim();
		if ( ! question || ! answer ) {
			setNotice( { status: 'error', message: __( 'Question and answer are required.', 'alpha-chat' ) } );
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
			setNotice( { status: 'success', message: __( 'Saved.', 'alpha-chat' ) } );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message: error instanceof Error ? error.message : String( error ),
			} );
		} finally {
			setBusy( false );
		}
	}

	async function remove( id: number ) {
		if ( ! window.confirm( __( 'Delete this Q&A?', 'alpha-chat' ) ) ) return;
		try {
			await adminApi.deleteFaq( id );
			await load();
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message: error instanceof Error ? error.message : String( error ),
			} );
		}
	}

	async function toggleEnabled( faq: Faq ) {
		try {
			await adminApi.updateFaq( faq.id, { enabled: ! faq.enabled } );
			await load();
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message: error instanceof Error ? error.message : String( error ),
			} );
		}
	}

	return (
		<div className="alpha-chat-faqs">
			{ notice && (
				<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
					{ notice.message }
				</Notice>
			) }

			<Card>
				<CardBody>
					<div className="alpha-chat-faqs__toolbar">
						<div>
							<strong>{ __( 'Curated Q&A', 'alpha-chat' ) }</strong>
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

			{ editing && (
				<Card>
					<CardBody>
						<TextControl
							label={ __( 'Question', 'alpha-chat' ) }
							value={ editing.question ?? '' }
							onChange={ ( value ) => setEditing( { ...editing, question: value } ) }
							placeholder={ __( 'e.g. Who are you?', 'alpha-chat' ) }
						/>
						<TextareaControl
							label={ __( 'Answer', 'alpha-chat' ) }
							value={ editing.answer ?? '' }
							onChange={ ( value ) => setEditing( { ...editing, answer: value } ) }
							rows={ 4 }
						/>
						<ToggleControl
							label={ __( 'Enabled', 'alpha-chat' ) }
							checked={ editing.enabled ?? true }
							onChange={ ( value ) => setEditing( { ...editing, enabled: value } ) }
						/>
						<div style={ { display: 'flex', gap: '0.5rem', marginTop: '0.5rem' } }>
							<Button variant="primary" onClick={ save } isBusy={ busy }>
								{ __( 'Save', 'alpha-chat' ) }
							</Button>
							<Button variant="tertiary" onClick={ () => setEditing( null ) }>
								{ __( 'Cancel', 'alpha-chat' ) }
							</Button>
						</div>
					</CardBody>
				</Card>
			) }

			<Card>
				<CardBody>
					{ loading ? (
						<Spinner />
					) : items.length === 0 ? (
						<p>{ __( 'No Q&A yet. Add your first one above.', 'alpha-chat' ) }</p>
					) : (
						<table className="alpha-chat-table">
							<thead>
								<tr>
									<th>{ __( 'Question', 'alpha-chat' ) }</th>
									<th>{ __( 'Answer', 'alpha-chat' ) }</th>
									<th style={ { width: 80 } }>{ __( 'Enabled', 'alpha-chat' ) }</th>
									<th />
								</tr>
							</thead>
							<tbody>
								{ items.map( ( faq ) => (
									<tr key={ faq.id }>
										<td style={ { maxWidth: 260, whiteSpace: 'pre-wrap' } }>
											<strong>{ faq.question }</strong>
										</td>
										<td style={ { maxWidth: 460, whiteSpace: 'pre-wrap' } }>
											{ faq.answer }
										</td>
										<td>
											<ToggleControl
												__nextHasNoMarginBottom
												label=""
												checked={ faq.enabled }
												onChange={ () => toggleEnabled( faq ) }
											/>
										</td>
										<td>
											<Button variant="link" onClick={ () => setEditing( faq ) }>
												{ __( 'Edit', 'alpha-chat' ) }
											</Button>
											<Button
												variant="link"
												isDestructive
												onClick={ () => remove( faq.id ) }
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
