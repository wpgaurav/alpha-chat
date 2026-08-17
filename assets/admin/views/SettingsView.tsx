import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	ButtonGroup,
	Card,
	CardBody,
	CardHeader,
	Notice,
	RangeControl,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import {
	adminApi,
	CatalogEmbeddingProvider,
	CatalogProvider,
	ModelCatalog,
	Settings,
} from '../api';

type PresetKey = 'fast' | 'balanced' | 'quality';

const COLOR_FIELDS: { key: string; label: string }[] = [
	{ key: 'accent', label: __( 'Accent', 'alpha-chat' ) },
	{ key: 'background', label: __( 'Panel', 'alpha-chat' ) },
	{ key: 'assistant_bubble', label: __( 'Assistant', 'alpha-chat' ) },
	{ key: 'user_bubble', label: __( 'User', 'alpha-chat' ) },
];

function Section( {
	title,
	description,
	children,
}: {
	title: string;
	description?: string;
	children: React.ReactNode;
} ) {
	return (
		<Card className="alpha-chat-section" size="small">
			<CardHeader className="alpha-chat-section__header">
				<div>
					<h2 className="alpha-chat-section__title">{ title }</h2>
					{ description && (
						<p className="alpha-chat-section__desc">
							{ description }
						</p>
					) }
				</div>
			</CardHeader>
			<CardBody className="alpha-chat-section__body">
				{ children }
			</CardBody>
		</Card>
	);
}

function ColorField( {
	label,
	value,
	onChange,
}: {
	label: string;
	value: string;
	onChange: ( hex: string ) => void;
} ) {
	return (
		<div
			className="alpha-chat-color-field"
			role="group"
			aria-label={ label }
		>
			<span className="alpha-chat-color-field__label">{ label }</span>
			<span className="alpha-chat-color-field__row">
				<input
					type="color"
					value={ value }
					onChange={ ( event ) => onChange( event.target.value ) }
					className="alpha-chat-color-field__swatch"
				/>
				<input
					type="text"
					value={ value }
					onChange={ ( event ) => onChange( event.target.value ) }
					className="alpha-chat-color-field__hex"
					spellCheck={ false }
				/>
			</span>
		</div>
	);
}

function thinkingHelp( providerId: string ): string {
	if ( providerId === 'xai' ) {
		return __(
			'Grok cannot turn thinking fully off. Off is sent as low. xhigh is not used.',
			'alpha-chat'
		);
	}
	if ( providerId === 'deepseek' ) {
		return __(
			'Default is low. Off disables DeepSeek thinking. Medium/high stay below max so the widget does not time out.',
			'alpha-chat'
		);
	}
	if ( providerId === 'anthropic' ) {
		return __(
			'Claude has no effort ladder. This setting is saved but not sent.',
			'alpha-chat'
		);
	}
	return __(
		'Mapped to OpenAI reasoning_effort. Off is none. xhigh and max are not used.',
		'alpha-chat'
	);
}

function secretValue( settings: Settings, key: string ): string {
	const value = ( settings as unknown as Record< string, unknown > )[ key ];
	return typeof value === 'string' ? value : '';
}

export function SettingsView() {
	const [ settings, setSettings ] = useState< Settings | null >( null );
	const [ catalog, setCatalog ] = useState< ModelCatalog | null >( null );
	const [ saving, setSaving ] = useState( false );
	const [ showAdvanced, setShowAdvanced ] = useState( false );
	const [ notice, setNotice ] = useState< {
		status: 'success' | 'error';
		message: string;
	} | null >( null );

	useEffect( () => {
		adminApi
			.getSettings()
			.then( ( response ) => {
				setSettings( response.settings );
				setCatalog( response.catalog );
			} )
			.catch( ( error: Error ) =>
				setNotice( { status: 'error', message: error.message } )
			);
	}, [] );

	function update< K extends keyof Settings >(
		key: K,
		value: Settings[ K ]
	) {
		setSettings( ( previous ) =>
			previous ? { ...previous, [ key ]: value } : previous
		);
	}

	function updateMany( patch: Partial< Settings > ) {
		setSettings( ( previous ) =>
			previous ? { ...previous, ...patch } : previous
		);
	}

	function changeEmbeddingProvider( provider: CatalogEmbeddingProvider ) {
		const available = provider.models.map( ( model ) => model.id );
		const nextModel =
			settings && available.includes( settings.embedding_model )
				? settings.embedding_model
				: available[ 0 ];
		updateMany( {
			embedding_provider: provider.id,
			embedding_model: nextModel,
		} );
	}

	function changeProvider( provider: CatalogProvider ) {
		const available = provider.models.map( ( model ) => model.id );
		const nextModel =
			settings && available.includes( settings.chat_model )
				? settings.chat_model
				: available[ 0 ];
		updateMany( {
			llm_provider: provider.id,
			chat_model: nextModel,
		} );
	}

	function applyPreset( key: PresetKey, provider: CatalogProvider ) {
		const preset = provider.presets[ key ];
		if ( ! preset ) {
			return;
		}
		updateMany( preset );
	}

	function updateSecret( key: string, value: string ) {
		setSettings( ( previous ) =>
			previous ? { ...previous, [ key ]: value } : previous
		);
	}

	async function save() {
		if ( ! settings ) {
			return;
		}
		setSaving( true );
		setNotice( null );
		try {
			const response = await adminApi.saveSettings( settings );
			setSettings( response.settings );
			setNotice( {
				status: 'success',
				message: __( 'Settings saved.', 'alpha-chat' ),
			} );
		} catch ( error ) {
			const message =
				error instanceof Error ? error.message : String( error );
			setNotice( { status: 'error', message } );
		} finally {
			setSaving( false );
		}
	}

	if ( ! settings || ! catalog ) {
		return (
			<div className="alpha-chat-settings__loading">
				<Spinner />
			</div>
		);
	}

	const selectedProvider =
		catalog.providers.find(
			( provider ) => provider.id === settings.llm_provider
		) ?? catalog.providers[ 0 ];
	const selectedEmbedding =
		( catalog.embeddings ?? [] ).find(
			( provider ) => provider.id === settings.embedding_provider
		) ?? ( catalog.embeddings ?? [] )[ 0 ];
	const modelOptions = ( selectedProvider?.models ?? [] ).map(
		( model ) => ( {
			label: model.label,
			value: model.id,
		} )
	);
	const embeddingOptions = ( selectedEmbedding?.models ?? [] ).map(
		( model ) => ( {
			label: model.label,
			value: model.id,
		} )
	);
	const needsOpenAiKey =
		settings.llm_provider === 'openai' ||
		( selectedEmbedding?.id ?? settings.embedding_provider ) === 'openai' ||
		settings.moderation_enabled;

	return (
		<div className="alpha-chat-settings">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			<Section
				title={ __( 'Quick preset', 'alpha-chat' ) }
				description={ __(
					'Pick a profile. Sets model, temperature, and response length.',
					'alpha-chat'
				) }
			>
				<ButtonGroup className="alpha-chat-presets">
					<Button
						variant="secondary"
						onClick={ () =>
							selectedProvider &&
							applyPreset( 'fast', selectedProvider )
						}
					>
						⚡ { __( 'Fast', 'alpha-chat' ) }
					</Button>
					<Button
						variant="secondary"
						onClick={ () =>
							selectedProvider &&
							applyPreset( 'balanced', selectedProvider )
						}
					>
						⚖️ { __( 'Balanced', 'alpha-chat' ) }
					</Button>
					<Button
						variant="secondary"
						onClick={ () =>
							selectedProvider &&
							applyPreset( 'quality', selectedProvider )
						}
					>
						💎 { __( 'Quality', 'alpha-chat' ) }
					</Button>
				</ButtonGroup>
			</Section>

			<Section
				title={ __( 'Provider & model', 'alpha-chat' ) }
				description={ __(
					'Vector store: site database, built-in.',
					'alpha-chat'
				) }
			>
				<div className="alpha-chat-grid-2">
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Provider', 'alpha-chat' ) }
						value={ selectedProvider?.id ?? settings.llm_provider }
						options={ catalog.providers.map( ( provider ) => ( {
							label: provider.label,
							value: provider.id,
						} ) ) }
						onChange={ ( value ) => {
							const next = catalog.providers.find(
								( provider ) => provider.id === value
							);
							if ( next ) {
								changeProvider( next );
							}
						} }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Chat model', 'alpha-chat' ) }
						value={ settings.chat_model }
						options={ modelOptions }
						onChange={ ( value ) => update( 'chat_model', value ) }
					/>
				</div>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Thinking', 'alpha-chat' ) }
					value={ settings.reasoning_effort || 'low' }
					options={ ( catalog.reasoning ?? [] ).map( ( level ) => ( {
						label: level.label,
						value: level.id,
					} ) ) }
					onChange={ ( value ) =>
						update( 'reasoning_effort', value )
					}
					help={ thinkingHelp( selectedProvider?.id ?? 'openai' ) }
				/>
				<div className="alpha-chat-grid-2">
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Embedding provider', 'alpha-chat' ) }
						value={
							selectedEmbedding?.id ?? settings.embedding_provider
						}
						options={ ( catalog.embeddings ?? [] ).map(
							( provider ) => ( {
								label: provider.label,
								value: provider.id,
							} )
						) }
						onChange={ ( value ) => {
							const next = ( catalog.embeddings ?? [] ).find(
								( provider ) => provider.id === value
							);
							if ( next ) {
								changeEmbeddingProvider( next );
							}
						} }
						help={ __(
							'Independent of chat. Reindex the knowledge base after changing provider or model.',
							'alpha-chat'
						) }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Embedding model', 'alpha-chat' ) }
						value={ settings.embedding_model }
						options={ embeddingOptions }
						onChange={ ( value ) =>
							update( 'embedding_model', value )
						}
					/>
				</div>
				{ needsOpenAiKey && (
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'OpenAI API key', 'alpha-chat' ) }
						type="password"
						value={ settings.openai_api_key }
						onChange={ ( value ) =>
							update( 'openai_api_key', value )
						}
						help={ __(
							'Needed only for OpenAI chat, OpenAI embeddings, or moderation.',
							'alpha-chat'
						) }
					/>
				) }
				{ selectedProvider &&
					selectedProvider.id !== 'openai' &&
					selectedProvider.secret_key && (
						<TextControl
							label={
								selectedProvider.label +
								' ' +
								__( 'API key', 'alpha-chat' )
							}
							type="password"
							value={ secretValue(
								settings,
								selectedProvider.secret_key
							) }
							onChange={ ( value ) =>
								updateSecret(
									selectedProvider.secret_key,
									value
								)
							}
						/>
					) }
				{ selectedEmbedding &&
					selectedEmbedding.id !== 'openai' &&
					selectedEmbedding.secret_key &&
					selectedEmbedding.secret_key !==
						selectedProvider?.secret_key && (
						<TextControl
							label={
								selectedEmbedding.label +
								' ' +
								__( 'API key', 'alpha-chat' )
							}
							type="password"
							value={ secretValue(
								settings,
								selectedEmbedding.secret_key
							) }
							onChange={ ( value ) =>
								updateSecret(
									selectedEmbedding.secret_key,
									value
								)
							}
						/>
					) }
			</Section>

			<Section title={ __( 'Behavior', 'alpha-chat' ) }>
				<div className="alpha-chat-toggles">
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Enable chat', 'alpha-chat' ) }
						checked={ settings.chat_enabled }
						onChange={ ( value ) =>
							update( 'chat_enabled', value )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Show floating launcher site-wide',
							'alpha-chat'
						) }
						checked={ settings.show_launcher }
						onChange={ ( value ) =>
							update( 'show_launcher', value )
						}
						help={ __(
							'Off = fastest site. Chat loads only on pages with the block or [alpha_chat].',
							'alpha-chat'
						) }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Name conversations with AI',
							'alpha-chat'
						) }
						checked={ settings.ai_thread_titles ?? true }
						onChange={ ( value ) =>
							update( 'ai_thread_titles', value )
						}
						help={ __(
							'Titles each conversation from its first exchange using your chat provider. Runs in the background, so it never slows a reply.',
							'alpha-chat'
						) }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Enable moderation', 'alpha-chat' ) }
						checked={ settings.moderation_enabled }
						onChange={ ( value ) =>
							update( 'moderation_enabled', value )
						}
						help={ __(
							'Uses OpenAI moderation. Turn off if you are not using an OpenAI key.',
							'alpha-chat'
						) }
					/>
				</div>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'System prompt', 'alpha-chat' ) }
					value={ settings.system_prompt }
					onChange={ ( value ) => update( 'system_prompt', value ) }
					rows={ 3 }
				/>
				<div className="alpha-chat-grid-2">
					<TextControl
						label={ __( 'Welcome message', 'alpha-chat' ) }
						value={ settings.welcome_message }
						onChange={ ( value ) =>
							update( 'welcome_message', value )
						}
					/>
					<TextControl
						label={ __( 'Fallback message', 'alpha-chat' ) }
						value={ settings.fallback_message }
						onChange={ ( value ) =>
							update( 'fallback_message', value )
						}
					/>
				</div>
			</Section>

			<Section
				title={ __( 'Launcher', 'alpha-chat' ) }
				description={ __(
					'Shown beside the chat icon when the widget is closed.',
					'alpha-chat'
				) }
			>
				<div className="alpha-chat-grid-2">
					<TextControl
						label={ __( 'Brand name', 'alpha-chat' ) }
						value={ settings.brand_name }
						onChange={ ( value ) => update( 'brand_name', value ) }
						help={ __( 'Shown in the chat header.', 'alpha-chat' ) }
					/>
					<SelectControl
						label={ __( 'Position', 'alpha-chat' ) }
						value={ settings.launcher_position }
						options={ [
							{
								label: __( 'Right', 'alpha-chat' ),
								value: 'right',
							},
							{
								label: __( 'Center', 'alpha-chat' ),
								value: 'center',
							},
							{
								label: __( 'Left', 'alpha-chat' ),
								value: 'left',
							},
						] }
						onChange={ ( value ) =>
							update(
								'launcher_position',
								value as Settings[ 'launcher_position' ]
							)
						}
					/>
				</div>
				<TextControl
					label={ __( 'Nudge text', 'alpha-chat' ) }
					value={ settings.launcher_nudge }
					onChange={ ( value ) => update( 'launcher_nudge', value ) }
					help={ __(
						'Short prompt shown beside the chat button. Leave empty to hide.',
						'alpha-chat'
					) }
				/>
				<div className="alpha-chat-devices">
					<p className="alpha-chat-devices__label">
						{ __( 'Show the button on', 'alpha-chat' ) }
					</p>
					{ (
						[
							[
								'desktop',
								__( 'Desktop', 'alpha-chat' ),
								__( '1024px and wider', 'alpha-chat' ),
							],
							[
								'tablet',
								__( 'Tablet', 'alpha-chat' ),
								__( '768px to 1023px', 'alpha-chat' ),
							],
							[
								'mobile',
								__( 'Mobile', 'alpha-chat' ),
								__( 'Under 768px', 'alpha-chat' ),
							],
						] as const
					 ).map( ( [ key, label, hint ] ) => (
						<ToggleControl
							key={ key }
							__nextHasNoMarginBottom
							label={ label }
							help={ hint }
							checked={
								settings.launcher_devices?.[ key ] ?? true
							}
							onChange={ ( value ) =>
								update( 'launcher_devices', {
									desktop:
										settings.launcher_devices?.desktop ??
										true,
									tablet:
										settings.launcher_devices?.tablet ??
										true,
									mobile:
										settings.launcher_devices?.mobile ??
										true,
									[ key ]: value,
								} )
							}
						/>
					) ) }
					<p className="alpha-chat-devices__note">
						{ __(
							'Applied in the browser by screen width, so it stays correct behind a page cache. Blocks and shortcodes are unaffected.',
							'alpha-chat'
						) }
					</p>
				</div>
			</Section>

			<Section
				title={ __( 'Contact form', 'alpha-chat' ) }
				description={ __(
					'Shows a "still need help?" button after the first exchange, collecting email + message.',
					'alpha-chat'
				) }
			>
				<ToggleControl
					label={ __( 'Enable contact form', 'alpha-chat' ) }
					checked={ settings.contact_form_enabled }
					onChange={ ( value ) =>
						update( 'contact_form_enabled', value )
					}
				/>
				<TextControl
					label={ __( 'Notify email', 'alpha-chat' ) }
					type="email"
					value={ settings.contact_notify_email }
					onChange={ ( value ) =>
						update( 'contact_notify_email', value )
					}
					help={ __(
						'Where to send contact-form submissions. Falls back to site admin email.',
						'alpha-chat'
					) }
				/>
				<div className="alpha-chat-grid-2">
					<TextControl
						label={ __( 'CTA label', 'alpha-chat' ) }
						value={ settings.contact_cta_label }
						onChange={ ( value ) =>
							update( 'contact_cta_label', value )
						}
					/>
					<TextControl
						label={ __( 'Success message', 'alpha-chat' ) }
						value={ settings.contact_success_message }
						onChange={ ( value ) =>
							update( 'contact_success_message', value )
						}
					/>
				</div>
			</Section>

			<Section
				title={ __( 'Widget design', 'alpha-chat' ) }
				description={
					__( 'Embed anywhere with', 'alpha-chat' ) + ' [alpha_chat]'
				}
			>
				<div className="alpha-chat-colors">
					{ COLOR_FIELDS.map( ( field ) => (
						<ColorField
							key={ field.key }
							label={ field.label }
							value={
								settings.colors?.[ field.key ] ?? '#000000'
							}
							onChange={ ( hex ) =>
								update( 'colors', {
									...( settings.colors ?? {} ),
									[ field.key ]: hex,
								} )
							}
						/>
					) ) }
				</div>
			</Section>

			<Section title={ __( 'Advanced', 'alpha-chat' ) }>
				<Button
					variant="tertiary"
					onClick={ () => setShowAdvanced( ( v ) => ! v ) }
				>
					{ showAdvanced
						? __( 'Hide advanced controls', 'alpha-chat' )
						: __( 'Show advanced controls', 'alpha-chat' ) }
				</Button>
				{ showAdvanced && (
					<div className="alpha-chat-advanced">
						<div className="alpha-chat-grid-2">
							<RangeControl
								label={ __( 'Temperature', 'alpha-chat' ) }
								value={ settings.temperature }
								min={ 0 }
								max={ 2 }
								step={ 0.1 }
								onChange={ ( value ) =>
									update( 'temperature', value ?? 0.7 )
								}
							/>
							<RangeControl
								label={ __( 'Top P', 'alpha-chat' ) }
								value={ settings.top_p }
								min={ 0 }
								max={ 1 }
								step={ 0.05 }
								onChange={ ( value ) =>
									update( 'top_p', value ?? 1 )
								}
							/>
							<RangeControl
								label={ __(
									'Max response tokens',
									'alpha-chat'
								) }
								value={ settings.max_response_tokens }
								min={ 64 }
								max={ 4096 }
								step={ 64 }
								onChange={ ( value ) =>
									update(
										'max_response_tokens',
										value ?? 800
									)
								}
							/>
							<RangeControl
								label={ __(
									'Similarity threshold',
									'alpha-chat'
								) }
								value={ settings.similarity_score_threshold }
								min={ 0 }
								max={ 1 }
								step={ 0.05 }
								onChange={ ( value ) =>
									update(
										'similarity_score_threshold',
										value ?? 0.4
									)
								}
							/>
							<RangeControl
								label={ __(
									'Max context chunks',
									'alpha-chat'
								) }
								value={ settings.max_context_chunks }
								min={ 1 }
								max={ 20 }
								step={ 1 }
								onChange={ ( value ) =>
									update( 'max_context_chunks', value ?? 5 )
								}
							/>
						</div>
					</div>
				) }
			</Section>

			<div className="alpha-chat-actionbar">
				<Button variant="primary" onClick={ save } isBusy={ saving }>
					{ __( 'Save settings', 'alpha-chat' ) }
				</Button>
			</div>
		</div>
	);
}
