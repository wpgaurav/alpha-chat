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

import { adminApi, Settings } from '../api';

type Provider = 'openai' | 'anthropic';
type PresetKey = 'fast' | 'balanced' | 'quality';

const CHAT_MODELS: Record< Provider, { label: string; value: string }[] > = {
	openai: [
		{ label: 'GPT-5.4 mini · fast, cheap', value: 'gpt-5.4-mini' },
		{ label: 'GPT-5.4 · highest quality', value: 'gpt-5.4' },
		{ label: 'GPT-4.1 · legacy', value: 'gpt-4.1' },
	],
	anthropic: [
		{ label: 'Claude Haiku 4.5 · fast, cheap', value: 'claude-haiku-4-5' },
		{ label: 'Claude Sonnet 4.6 · balanced', value: 'claude-sonnet-4-6' },
		{ label: 'Claude Opus 4.7 · highest quality', value: 'claude-opus-4-7' },
	],
};

const EMBEDDING_MODELS = [
	{ label: 'text-embedding-3-small · fast, cheap', value: 'text-embedding-3-small' },
	{ label: 'text-embedding-3-large · highest quality', value: 'text-embedding-3-large' },
];

const PRESETS: Record< PresetKey, Record< Provider, Partial< Settings > > > = {
	fast: {
		openai: { chat_model: 'gpt-5.4-mini', temperature: 0.3, max_response_tokens: 600 },
		anthropic: { chat_model: 'claude-haiku-4-5', temperature: 0.3, max_response_tokens: 600 },
	},
	balanced: {
		openai: { chat_model: 'gpt-5.4-mini', temperature: 0.7, max_response_tokens: 800 },
		anthropic: { chat_model: 'claude-sonnet-4-6', temperature: 0.7, max_response_tokens: 800 },
	},
	quality: {
		openai: { chat_model: 'gpt-5.4', temperature: 0.7, max_response_tokens: 1500 },
		anthropic: { chat_model: 'claude-opus-4-7', temperature: 0.7, max_response_tokens: 1500 },
	},
};

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
						<p className="alpha-chat-section__desc">{ description }</p>
					) }
				</div>
			</CardHeader>
			<CardBody className="alpha-chat-section__body">{ children }</CardBody>
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
		<label className="alpha-chat-color-field">
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
		</label>
	);
}

export function SettingsView() {
	const [ settings, setSettings ] = useState< Settings | null >( null );
	const [ saving, setSaving ] = useState( false );
	const [ showAdvanced, setShowAdvanced ] = useState( false );
	const [ notice, setNotice ] = useState< { status: 'success' | 'error'; message: string } | null >( null );

	useEffect( () => {
		adminApi
			.getSettings()
			.then( ( response ) => setSettings( response.settings ) )
			.catch( ( error: Error ) =>
				setNotice( { status: 'error', message: error.message } )
			);
	}, [] );

	function update< K extends keyof Settings >( key: K, value: Settings[ K ] ) {
		setSettings( ( previous ) =>
			previous ? { ...previous, [ key ]: value } : previous
		);
	}

	function updateMany( patch: Partial< Settings > ) {
		setSettings( ( previous ) => ( previous ? { ...previous, ...patch } : previous ) );
	}

	function changeProvider( provider: Provider ) {
		const available = CHAT_MODELS[ provider ].map( ( m ) => m.value );
		const nextModel =
			settings && available.includes( settings.chat_model )
				? settings.chat_model
				: available[ 0 ];
		updateMany( { llm_provider: provider, chat_model: nextModel } );
	}

	function applyPreset( key: PresetKey ) {
		if ( ! settings ) return;
		updateMany( PRESETS[ key ][ settings.llm_provider ] );
	}

	async function save() {
		if ( ! settings ) return;
		setSaving( true );
		setNotice( null );
		try {
			const response = await adminApi.saveSettings( settings );
			setSettings( response.settings );
			setNotice( { status: 'success', message: __( 'Settings saved.', 'alpha-chat' ) } );
		} catch ( error ) {
			const message = error instanceof Error ? error.message : String( error );
			setNotice( { status: 'error', message } );
		} finally {
			setSaving( false );
		}
	}

	if ( ! settings ) {
		return (
			<div className="alpha-chat-settings__loading">
				<Spinner />
			</div>
		);
	}

	const provider = settings.llm_provider;
	const modelOptions = CHAT_MODELS[ provider ];

	return (
		<div className="alpha-chat-settings">
			{ notice && (
				<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
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
					<Button variant="secondary" onClick={ () => applyPreset( 'fast' ) }>
						⚡ { __( 'Fast', 'alpha-chat' ) }
					</Button>
					<Button variant="secondary" onClick={ () => applyPreset( 'balanced' ) }>
						⚖️ { __( 'Balanced', 'alpha-chat' ) }
					</Button>
					<Button variant="secondary" onClick={ () => applyPreset( 'quality' ) }>
						💎 { __( 'Quality', 'alpha-chat' ) }
					</Button>
				</ButtonGroup>
			</Section>

			<Section
				title={ __( 'Provider & model', 'alpha-chat' ) }
				description={ __( 'Vector store: site database, built-in.', 'alpha-chat' ) }
			>
				<div className="alpha-chat-grid-2">
					<SelectControl
						label={ __( 'Provider', 'alpha-chat' ) }
						value={ provider }
						options={ [
							{ label: 'OpenAI', value: 'openai' },
							{ label: 'Anthropic', value: 'anthropic' },
						] }
						onChange={ ( value ) => changeProvider( value as Provider ) }
					/>
					<SelectControl
						label={ __( 'Chat model', 'alpha-chat' ) }
						value={ settings.chat_model }
						options={ modelOptions }
						onChange={ ( value ) => update( 'chat_model', value ) }
					/>
				</div>
				<SelectControl
					label={ __( 'Embedding model (OpenAI)', 'alpha-chat' ) }
					value={ settings.embedding_model }
					options={ EMBEDDING_MODELS }
					onChange={ ( value ) => update( 'embedding_model', value ) }
				/>
				<TextControl
					label={ __( 'OpenAI API key', 'alpha-chat' ) }
					type="password"
					value={ settings.openai_api_key }
					onChange={ ( value ) => update( 'openai_api_key', value ) }
					help={ __(
						'Used for embeddings + moderation, plus OpenAI chat models.',
						'alpha-chat'
					) }
				/>
				{ provider === 'anthropic' && (
					<TextControl
						label={ __( 'Anthropic API key', 'alpha-chat' ) }
						type="password"
						value={ settings.anthropic_api_key }
						onChange={ ( value ) => update( 'anthropic_api_key', value ) }
					/>
				) }
			</Section>

			<Section title={ __( 'Behavior', 'alpha-chat' ) }>
				<div className="alpha-chat-toggles">
					<ToggleControl
						label={ __( 'Enable chat', 'alpha-chat' ) }
						checked={ settings.chat_enabled }
						onChange={ ( value ) => update( 'chat_enabled', value ) }
					/>
					<ToggleControl
						label={ __( 'Show floating launcher site-wide', 'alpha-chat' ) }
						checked={ settings.show_launcher }
						onChange={ ( value ) => update( 'show_launcher', value ) }
						help={ __(
							'Off = fastest site. Chat loads only on pages with the block or [alpha_chat].',
							'alpha-chat'
						) }
					/>
					<ToggleControl
						label={ __( 'Enable moderation', 'alpha-chat' ) }
						checked={ settings.moderation_enabled }
						onChange={ ( value ) => update( 'moderation_enabled', value ) }
					/>
				</div>
				<TextareaControl
					label={ __( 'System prompt', 'alpha-chat' ) }
					value={ settings.system_prompt }
					onChange={ ( value ) => update( 'system_prompt', value ) }
					rows={ 3 }
				/>
				<div className="alpha-chat-grid-2">
					<TextControl
						label={ __( 'Welcome message', 'alpha-chat' ) }
						value={ settings.welcome_message }
						onChange={ ( value ) => update( 'welcome_message', value ) }
					/>
					<TextControl
						label={ __( 'Fallback message', 'alpha-chat' ) }
						value={ settings.fallback_message }
						onChange={ ( value ) => update( 'fallback_message', value ) }
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
							{ label: __( 'Right', 'alpha-chat' ), value: 'right' },
							{ label: __( 'Center', 'alpha-chat' ), value: 'center' },
							{ label: __( 'Left', 'alpha-chat' ), value: 'left' },
						] }
						onChange={ ( value ) =>
							update( 'launcher_position', value as Settings[ 'launcher_position' ] )
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
					onChange={ ( value ) => update( 'contact_form_enabled', value ) }
				/>
				<TextControl
					label={ __( 'Notify email', 'alpha-chat' ) }
					type="email"
					value={ settings.contact_notify_email }
					onChange={ ( value ) => update( 'contact_notify_email', value ) }
					help={ __(
						'Where to send contact-form submissions. Falls back to site admin email.',
						'alpha-chat'
					) }
				/>
				<div className="alpha-chat-grid-2">
					<TextControl
						label={ __( 'CTA label', 'alpha-chat' ) }
						value={ settings.contact_cta_label }
						onChange={ ( value ) => update( 'contact_cta_label', value ) }
					/>
					<TextControl
						label={ __( 'Success message', 'alpha-chat' ) }
						value={ settings.contact_success_message }
						onChange={ ( value ) => update( 'contact_success_message', value ) }
					/>
				</div>
			</Section>

			<Section
				title={ __( 'Widget design', 'alpha-chat' ) }
				description={ __( 'Embed anywhere with', 'alpha-chat' ) + ' [alpha_chat]' }
			>
				<div className="alpha-chat-colors">
					{ COLOR_FIELDS.map( ( field ) => (
						<ColorField
							key={ field.key }
							label={ field.label }
							value={ settings.colors?.[ field.key ] ?? '#000000' }
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
								onChange={ ( value ) => update( 'temperature', value ?? 0.7 ) }
							/>
							<RangeControl
								label={ __( 'Top P', 'alpha-chat' ) }
								value={ settings.top_p }
								min={ 0 }
								max={ 1 }
								step={ 0.05 }
								onChange={ ( value ) => update( 'top_p', value ?? 1 ) }
							/>
							<RangeControl
								label={ __( 'Max response tokens', 'alpha-chat' ) }
								value={ settings.max_response_tokens }
								min={ 64 }
								max={ 4096 }
								step={ 64 }
								onChange={ ( value ) =>
									update( 'max_response_tokens', value ?? 800 )
								}
							/>
							<RangeControl
								label={ __( 'Similarity threshold', 'alpha-chat' ) }
								value={ settings.similarity_score_threshold }
								min={ 0 }
								max={ 1 }
								step={ 0.05 }
								onChange={ ( value ) =>
									update( 'similarity_score_threshold', value ?? 0.4 )
								}
							/>
							<RangeControl
								label={ __( 'Max context chunks', 'alpha-chat' ) }
								value={ settings.max_context_chunks }
								min={ 1 }
								max={ 20 }
								step={ 1 }
								onChange={ ( value ) => update( 'max_context_chunks', value ?? 5 ) }
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
