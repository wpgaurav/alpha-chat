import * as React from 'react';

/**
 * A small Markdown renderer for assistant replies.
 *
 * Models answer in Markdown, so the widget used to show literal `**bold**` and
 * `-` bullets. This renders the subset they actually emit.
 *
 * It deliberately builds React elements rather than an HTML string: reply text is
 * shaped by retrieved site content and curated Q&A, so it is not fully trusted.
 * Everything that is not an explicitly constructed element stays a text node,
 * which React escapes. There is no dangerouslySetInnerHTML anywhere in the widget
 * and this file must not introduce one.
 */

type Inline = React.ReactNode;

/** Schemes allowed to become a clickable link. */
const SAFE_SCHEMES = [ 'http:', 'https:', 'mailto:' ];

function safeHref( raw: string ): string | null {
	const url = raw.trim();
	if ( ! url ) {
		return null;
	}
	// Protocol-relative and root-relative links are fine and have no scheme.
	if ( url.startsWith( '/' ) ) {
		return url;
	}
	try {
		const parsed = new URL( url, window.location.origin );
		return SAFE_SCHEMES.includes( parsed.protocol ) ? parsed.href : null;
	} catch {
		return null;
	}
}

/**
 * Parse inline markup. Code spans are consumed first so their contents are never
 * re-scanned for emphasis.
 * @param text
 * @param keyPrefix
 */
function parseInline( text: string, keyPrefix: string ): Inline[] {
	const out: Inline[] = [];
	let buffer = '';
	let key = 0;

	const flush = () => {
		if ( buffer ) {
			out.push( buffer );
			buffer = '';
		}
	};

	for ( let i = 0; i < text.length;  ) {
		const rest = text.slice( i );

		// `code`
		const code = /^`([^`]+)`/.exec( rest );
		if ( code ) {
			flush();
			out.push(
				<code key={ `${ keyPrefix }-c${ key++ }` }>{ code[ 1 ] }</code>
			);
			i += code[ 0 ].length;
			continue;
		}

		// [label](url)
		const link = /^\[([^\]]+)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/.exec( rest );
		if ( link ) {
			const href = safeHref( link[ 2 ] );
			flush();
			if ( href ) {
				out.push(
					<a
						key={ `${ keyPrefix }-l${ key++ }` }
						href={ href }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ parseInline( link[ 1 ], `${ keyPrefix }-l${ key }` ) }
					</a>
				);
			} else {
				// Unsafe scheme: keep the label, drop the link.
				out.push( link[ 1 ] );
			}
			i += link[ 0 ].length;
			continue;
		}

		// **bold** / __bold__
		const bold = /^(\*\*|__)(?=\S)([\s\S]*?\S)\1/.exec( rest );
		if ( bold ) {
			flush();
			out.push(
				<strong key={ `${ keyPrefix }-b${ key++ }` }>
					{ parseInline( bold[ 2 ], `${ keyPrefix }-b${ key }` ) }
				</strong>
			);
			i += bold[ 0 ].length;
			continue;
		}

		// *italic* / _italic_
		const italic = /^(\*|_)(?=\S)([\s\S]*?\S)\1/.exec( rest );
		if ( italic ) {
			flush();
			out.push(
				<em key={ `${ keyPrefix }-i${ key++ }` }>
					{ parseInline( italic[ 2 ], `${ keyPrefix }-i${ key }` ) }
				</em>
			);
			i += italic[ 0 ].length;
			continue;
		}

		// Bare URL
		const auto = /^(https?:\/\/[^\s<>()]+)/.exec( rest );
		if ( auto ) {
			const href = safeHref( auto[ 1 ] );
			flush();
			out.push(
				href ? (
					<a
						key={ `${ keyPrefix }-u${ key++ }` }
						href={ href }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ auto[ 1 ] }
					</a>
				) : (
					auto[ 1 ]
				)
			);
			i += auto[ 0 ].length;
			continue;
		}

		buffer += text[ i ];
		i += 1;
	}

	flush();
	return out;
}

/**
 * Render text with single newlines preserved as line breaks.
 * @param text
 * @param keyPrefix
 */
function withBreaks( text: string, keyPrefix: string ): Inline[] {
	const lines = text.split( '\n' );
	const out: Inline[] = [];

	lines.forEach( ( line, index ) => {
		if ( index > 0 ) {
			out.push( <br key={ `${ keyPrefix }-br${ index }` } /> );
		}
		out.push( ...parseInline( line, `${ keyPrefix }-${ index }` ) );
	} );

	return out;
}

const BULLET = /^\s{0,3}[-*+]\s+(.*)$/;
const ORDERED = /^\s{0,3}\d+[.)]\s+(.*)$/;
const HEADING = /^\s{0,3}(#{1,6})\s+(.*)$/;

export function Markdown( { text }: { text: string } ): React.ReactElement {
	const source = text.replace( /\r\n?/g, '\n' );
	const lines = source.split( '\n' );
	const blocks: React.ReactNode[] = [];

	let i = 0;
	let key = 0;

	while ( i < lines.length ) {
		const line = lines[ i ];

		// Skip blank lines between blocks.
		if ( ! line.trim() ) {
			i += 1;
			continue;
		}

		// Fenced code block
		const fence = /^\s{0,3}```(.*)$/.exec( line );
		if ( fence ) {
			const body: string[] = [];
			i += 1;
			while ( i < lines.length && ! /^\s{0,3}```/.test( lines[ i ] ) ) {
				body.push( lines[ i ] );
				i += 1;
			}
			i += 1; // closing fence
			blocks.push(
				<pre key={ `p${ key++ }` }>
					<code>{ body.join( '\n' ) }</code>
				</pre>
			);
			continue;
		}

		// Heading
		const heading = HEADING.exec( line );
		if ( heading ) {
			const level = Math.min( 6, heading[ 1 ].length );
			const Tag = `h${ level }` as keyof JSX.IntrinsicElements;
			blocks.push(
				<Tag key={ `p${ key++ }` }>
					{ parseInline( heading[ 2 ], `h${ key }` ) }
				</Tag>
			);
			i += 1;
			continue;
		}

		// Lists. A list runs until a blank line or a non-list line.
		const isBullet = BULLET.test( line );
		const isOrdered = ! isBullet && ORDERED.test( line );
		if ( isBullet || isOrdered ) {
			const pattern = isBullet ? BULLET : ORDERED;
			const items: string[] = [];

			while ( i < lines.length ) {
				const match = pattern.exec( lines[ i ] );
				if ( match ) {
					items.push( match[ 1 ] );
					i += 1;
					continue;
				}
				// A wrapped continuation line belongs to the previous item.
				if (
					lines[ i ].trim() &&
					! /^\s{0,3}([-*+]|\d+[.)]|#{1,6})\s/.test( lines[ i ] ) &&
					items.length
				) {
					items[ items.length - 1 ] += ' ' + lines[ i ].trim();
					i += 1;
					continue;
				}
				break;
			}

			const rendered = items.map( ( item, index ) => (
				<li key={ `li${ index }` }>
					{ parseInline( item, `l${ key }-${ index }` ) }
				</li>
			) );

			blocks.push(
				isBullet ? (
					<ul key={ `p${ key++ }` }>{ rendered }</ul>
				) : (
					<ol key={ `p${ key++ }` }>{ rendered }</ol>
				)
			);
			continue;
		}

		// Paragraph: consume until a blank line or the start of another block.
		const paragraph: string[] = [];
		while ( i < lines.length && lines[ i ].trim() ) {
			if (
				BULLET.test( lines[ i ] ) ||
				ORDERED.test( lines[ i ] ) ||
				HEADING.test( lines[ i ] ) ||
				/^\s{0,3}```/.test( lines[ i ] )
			) {
				break;
			}
			paragraph.push( lines[ i ] );
			i += 1;
		}

		if ( paragraph.length ) {
			blocks.push(
				<p key={ `p${ key++ }` }>
					{ withBreaks( paragraph.join( '\n' ), `p${ key }` ) }
				</p>
			);
		}
	}

	return <div className="md">{ blocks }</div>;
}
