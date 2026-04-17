import { createRoot, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';

import { widgetStyles } from './styles';

type Role = 'user' | 'assistant';

type Source = {
	source_type: string;
	source_id: number;
	score: number;
	title: string;
	url: string;
	image: string;
};

type Message = {
	id: string;
	role: Role;
	content: string;
	sources?: Source[];
};

type ChatResponse = {
	thread_uuid: string;
	reply: string;
	flagged?: boolean;
	sources: Source[];
};

type Position = 'left' | 'center' | 'right';

type ClientData = {
	restUrl: string;
	nonce: string;
	welcomeMessage: string;
	fallbackMessage: string;
	predefinedQuestions: string[];
	colors: Record< string, string >;
	launcherNudge: string;
	launcherPosition: Position;
	brandName: string;
	contactFormEnabled: boolean;
	contactCtaLabel: string;
	contactSuccessMessage: string;
	strings: {
		send: string;
		typing: string;
		reset: string;
		input: string;
		nameLabel: string;
		emailLabel: string;
		messageLabel: string;
		cancel: string;
		submit: string;
	};
};

declare global {
	interface Window {
		alphaChatClient?: ClientData;
	}
}

const THREAD_KEY = 'alpha-chat-thread';

function uid(): string {
	return `${ Date.now() }-${ Math.random().toString( 36 ).slice( 2, 8 ) }`;
}

function ChatIcon() {
	return (
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
			<path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
		</svg>
	);
}

function ArrowUpIcon() {
	return (
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
			<path d="M12 19V5" />
			<path d="M5 12l7-7 7 7" />
		</svg>
	);
}

function CloseIcon() {
	return (
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
			<path d="M18 6L6 18" />
			<path d="M6 6l12 12" />
		</svg>
	);
}

function cssVarStyle( colors: Record< string, string > ): React.CSSProperties {
	const style: Record< string, string > = {};
	if ( colors.accent ) style[ '--ac-accent' ] = colors.accent;
	if ( colors.background ) style[ '--ac-bg' ] = colors.background;
	if ( colors.user_bubble ) style[ '--ac-user-bubble' ] = colors.user_bubble;
	if ( colors.assistant_bubble ) style[ '--ac-assistant-bubble' ] = colors.assistant_bubble;
	return style as React.CSSProperties;
}

function SourceCards( { sources }: { sources: Source[] } ) {
	if ( ! sources.length ) return null;
	return (
		<div className="sources">
			{ sources.map( ( s, i ) =>
				s.url ? (
					<a
						key={ `${ s.source_type }-${ s.source_id }-${ i }` }
						className="source"
						href={ s.url }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ s.image && <img src={ s.image } alt="" loading="lazy" /> }
						<span className="source__title">{ s.title || s.url }</span>
					</a>
				) : null
			) }
		</div>
	);
}

function ContactForm( {
	client,
	threadUuid,
	onDone,
	onCancel,
}: {
	client: ClientData;
	threadUuid: string | null;
	onDone: ( message: string ) => void;
	onCancel: () => void;
} ) {
	const [ name, setName ] = useState( '' );
	const [ email, setEmail ] = useState( '' );
	const [ message, setMessage ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ err, setErr ] = useState< string | null >( null );

	async function submit() {
		setBusy( true );
		setErr( null );
		try {
			const response = await fetch( `${ client.restUrl }/contact`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify( {
					name,
					email,
					message,
					thread: threadUuid,
				} ),
			} );
			const data = await response.json();
			if ( ! response.ok || ! data?.ok ) {
				throw new Error( data?.message || 'Submission failed' );
			}
			onDone( client.contactSuccessMessage );
		} catch ( e ) {
			setErr( e instanceof Error ? e.message : String( e ) );
		} finally {
			setBusy( false );
		}
	}

	return (
		<form
			className="contact-form"
			onSubmit={ ( event ) => {
				event.preventDefault();
				submit();
			} }
		>
			<div className="contact-form__title">{ client.contactCtaLabel }</div>
			<input
				type="text"
				placeholder={ client.strings.nameLabel }
				value={ name }
				onChange={ ( event ) => setName( event.target.value ) }
				autoComplete="name"
				required
			/>
			<input
				type="email"
				placeholder={ client.strings.emailLabel }
				value={ email }
				onChange={ ( event ) => setEmail( event.target.value ) }
				autoComplete="email"
				required
			/>
			<textarea
				placeholder={ client.strings.messageLabel }
				value={ message }
				onChange={ ( event ) => setMessage( event.target.value ) }
				rows={ 3 }
				required
			/>
			{ err && <div className="error">{ err }</div> }
			<div className="contact-form__actions">
				<button
					type="button"
					className="contact-form__cancel"
					onClick={ onCancel }
					disabled={ busy }
				>
					{ client.strings.cancel }
				</button>
				<button
					type="submit"
					className="contact-form__submit"
					disabled={ busy || ! name.trim() || ! email || ! message.trim() }
				>
					{ client.strings.submit }
				</button>
			</div>
		</form>
	);
}

function ChatPanel( {
	client,
	onClose,
	inline,
}: {
	client: ClientData;
	onClose?: () => void;
	inline?: boolean;
} ) {
	const [ messages, setMessages ] = useState< Message[] >( () => [
		{ id: uid(), role: 'assistant', content: client.welcomeMessage },
	] );
	const [ input, setInput ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ showContact, setShowContact ] = useState( false );
	const [ threadUuid, setThreadUuid ] = useState< string | null >( () => {
		try {
			return window.localStorage.getItem( THREAD_KEY );
		} catch {
			return null;
		}
	} );
	const scrollRef = useRef< HTMLDivElement | null >( null );

	useEffect( () => {
		if ( scrollRef.current ) {
			scrollRef.current.scrollTop = scrollRef.current.scrollHeight;
		}
	}, [ messages, busy, showContact ] );

	async function send( content: string ) {
		const trimmed = content.trim();
		if ( ! trimmed || busy ) return;
		setError( null );
		setBusy( true );
		setMessages( ( c ) => [ ...c, { id: uid(), role: 'user', content: trimmed } ] );
		setInput( '' );

		try {
			const response = await fetch( `${ client.restUrl }/chat`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify( {
					message: trimmed,
					thread: threadUuid,
				} ),
			} );
			const raw = await response.text();
			const data = raw ? ( JSON.parse( raw ) as ChatResponse | { message?: string } ) : null;
			if ( ! response.ok || ! data || ! ( 'reply' in data ) ) {
				throw new Error(
					( data && 'message' in data && data.message ) ||
						`Request failed (${ response.status })`
				);
			}
			if ( data.thread_uuid ) {
				setThreadUuid( data.thread_uuid );
				try {
					window.localStorage.setItem( THREAD_KEY, data.thread_uuid );
				} catch {
					/* ignore */
				}
			}
			setMessages( ( c ) => [
				...c,
				{
					id: uid(),
					role: 'assistant',
					content: data.reply || client.fallbackMessage,
					sources: data.sources ?? [],
				},
			] );
		} catch ( e ) {
			setError( e instanceof Error ? e.message : String( e ) );
			setMessages( ( c ) => [
				...c,
				{ id: uid(), role: 'assistant', content: client.fallbackMessage },
			] );
		} finally {
			setBusy( false );
		}
	}

	function reset() {
		setThreadUuid( null );
		try {
			window.localStorage.removeItem( THREAD_KEY );
		} catch {
			/* ignore */
		}
		setMessages( [ { id: uid(), role: 'assistant', content: client.welcomeMessage } ] );
		setError( null );
		setShowContact( false );
	}

	const hasExchange = messages.length > 1;
	const showContactCta = client.contactFormEnabled && hasExchange && ! showContact;

	return (
		<>
			<div className="header">
				<span className="header__title">{ client.brandName || 'Alpha Chat' }</span>
				<div className="header__actions">
					<button type="button" className="header__btn" onClick={ reset } disabled={ busy }>
						{ client.strings.reset }
					</button>
					{ onClose && (
						<button
							type="button"
							className="header__btn header__close"
							onClick={ onClose }
							aria-label="Close"
							title="Close"
						>
							<CloseIcon />
						</button>
					) }
				</div>
			</div>

			<div className="log" ref={ scrollRef }>
				{ messages.map( ( m ) => (
					<div key={ m.id } className={ `msg is-${ m.role }` }>
						<div className="bubble">{ m.content }</div>
						{ m.role === 'assistant' && m.sources && <SourceCards sources={ m.sources } /> }
					</div>
				) ) }
				{ busy && (
					<div className="msg is-assistant is-typing">
						<div className="bubble">{ client.strings.typing }</div>
					</div>
				) }
				{ error && <div className="error">{ error }</div> }

				{ showContactCta && (
					<button
						type="button"
						className="contact-cta"
						onClick={ () => setShowContact( true ) }
					>
						{ client.contactCtaLabel }
					</button>
				) }

				{ showContact && (
					<ContactForm
						client={ client }
						threadUuid={ threadUuid }
						onDone={ ( msg ) => {
							setShowContact( false );
							setMessages( ( c ) => [
								...c,
								{ id: uid(), role: 'assistant', content: msg },
							] );
						} }
						onCancel={ () => setShowContact( false ) }
					/>
				) }
			</div>

			<form
				className="form"
				onSubmit={ ( event ) => {
					event.preventDefault();
					send( input );
				} }
			>
				<textarea
					className="form__input"
					value={ input }
					placeholder={ client.strings.input }
					rows={ 1 }
					onChange={ ( event ) => setInput( event.target.value ) }
					onKeyDown={ ( event ) => {
						if ( event.key === 'Enter' && ! event.shiftKey ) {
							event.preventDefault();
							send( input );
						}
					} }
					disabled={ busy }
				/>
				<button
					type="submit"
					className="form__send"
					disabled={ busy || ! input.trim() }
					aria-label={ client.strings.send }
				>
					<ArrowUpIcon />
				</button>
			</form>
		</>
	);
}

function FloatingWidget( { client }: { client: ClientData } ) {
	const [ open, setOpen ] = useState( false );
	const position = client.launcherPosition || 'right';

	return (
		<div className="root" style={ cssVarStyle( client.colors ?? {} ) }>
			{ open && (
				<div className={ `panel pos-${ position }` }>
					<ChatPanel client={ client } onClose={ () => setOpen( false ) } />
				</div>
			) }
			{ ! open && (
				<div className={ `launcher pos-${ position }` }>
					{ client.launcherNudge && (
						<button
							type="button"
							className="nudge"
							onClick={ () => setOpen( true ) }
							aria-label={ client.launcherNudge }
						>
							<span className="nudge__icon">
								<ChatIcon />
							</span>
							<span className="nudge__text">{ client.launcherNudge }</span>
							<span className="nudge__arrow">
								<ArrowUpIcon />
							</span>
						</button>
					) }
					<button
						type="button"
						className="toggle"
						onClick={ () => setOpen( true ) }
						aria-label="Alpha Chat"
					>
						<ChatIcon />
					</button>
				</div>
			) }
		</div>
	);
}

function InlineWidget( { client }: { client: ClientData } ) {
	return (
		<div className="root" style={ cssVarStyle( client.colors ?? {} ) }>
			<div className="panel is-inline">
				<ChatPanel client={ client } inline />
			</div>
		</div>
	);
}

function mountShadow( host: HTMLElement, component: React.ReactElement ) {
	if ( host.dataset.alphaChatMounted === '1' ) return;
	host.dataset.alphaChatMounted = '1';

	const shadow = host.attachShadow ? host.attachShadow( { mode: 'open' } ) : null;
	if ( ! shadow ) {
		createRoot( host ).render( component );
		return;
	}

	const style = document.createElement( 'style' );
	style.textContent = widgetStyles;
	shadow.appendChild( style );

	const container = document.createElement( 'div' );
	shadow.appendChild( container );
	createRoot( container ).render( component );
}

domReady( () => {
	const client = window.alphaChatClient;
	if ( ! client ) return;

	const inlineHosts = Array.from(
		document.querySelectorAll< HTMLElement >( '[data-alpha-chat-embed]' )
	);

	inlineHosts.forEach( ( host ) => mountShadow( host, <InlineWidget client={ client } /> ) );

	if ( inlineHosts.length > 0 ) return;

	let host = document.getElementById( 'alpha-chat-widget-root' );
	if ( ! host ) {
		host = document.createElement( 'div' );
		host.id = 'alpha-chat-widget-root';
		document.body.appendChild( host );
	}
	mountShadow( host, <FloatingWidget client={ client } /> );
} );
