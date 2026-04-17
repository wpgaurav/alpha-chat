import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, Placeholder, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { comment as chatIcon } from '@wordpress/icons';

import metadata from './block.json';

type Attributes = {
	heading: string;
	placeholder: string;
};

registerBlockType< Attributes >( metadata.name, {
	icon: chatIcon,
	edit: ( { attributes, setAttributes } ) => {
		const blockProps = useBlockProps();
		return (
			<div { ...blockProps }>
				<InspectorControls>
					<PanelBody title={ __( 'Chat settings', 'alpha-chat' ) }>
						<TextControl
							label={ __( 'Heading', 'alpha-chat' ) }
							value={ attributes.heading }
							onChange={ ( value: string ) => setAttributes( { heading: value } ) }
						/>
						<TextControl
							label={ __( 'Input placeholder', 'alpha-chat' ) }
							value={ attributes.placeholder }
							onChange={ ( value: string ) => setAttributes( { placeholder: value } ) }
							help={ __(
								'Overrides the default placeholder inside the chat input.',
								'alpha-chat'
							) }
						/>
					</PanelBody>
				</InspectorControls>

				<Placeholder
					icon={ chatIcon }
					label={ attributes.heading || __( 'Alpha Chat', 'alpha-chat' ) }
					instructions={ __(
						'Alpha Chat will render here on the frontend. Open the block sidebar to configure it.',
						'alpha-chat'
					) }
				/>
			</div>
		);
	},
	save: () => null,
} );
