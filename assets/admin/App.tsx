import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { TabPanel } from '@wordpress/components';

import { SettingsView } from './views/SettingsView';
import { KnowledgeBaseView } from './views/KnowledgeBaseView';
import { ThreadsView } from './views/ThreadsView';
import { DashboardView } from './views/DashboardView';
import { ContactsView } from './views/ContactsView';
import { FaqsView } from './views/FaqsView';

const tabs = [
	{ name: 'dashboard', title: __( 'Dashboard', 'alpha-chat' ), className: 'alpha-chat-tab' },
	{ name: 'knowledge-base', title: __( 'Knowledge Base', 'alpha-chat' ), className: 'alpha-chat-tab' },
	{ name: 'faqs', title: __( 'Q&A', 'alpha-chat' ), className: 'alpha-chat-tab' },
	{ name: 'threads', title: __( 'Conversations', 'alpha-chat' ), className: 'alpha-chat-tab' },
	{ name: 'contacts', title: __( 'Contacts', 'alpha-chat' ), className: 'alpha-chat-tab' },
	{ name: 'settings', title: __( 'Settings', 'alpha-chat' ), className: 'alpha-chat-tab' },
];

export function App() {
	const [ activeTab, setActiveTab ] = useState( 'dashboard' );

	return (
		<div className="alpha-chat-admin">
			<header className="alpha-chat-admin__header">
				<h1>{ __( 'Alpha Chat', 'alpha-chat' ) }</h1>
			</header>
			<TabPanel
				className="alpha-chat-admin__tabs"
				activeClass="is-active"
				initialTabName={ activeTab }
				onSelect={ setActiveTab }
				tabs={ tabs }
			>
				{ ( tab ) => (
					<section className="alpha-chat-admin__panel">
						{ tab.name === 'dashboard' && <DashboardView /> }
						{ tab.name === 'knowledge-base' && <KnowledgeBaseView /> }
						{ tab.name === 'faqs' && <FaqsView /> }
						{ tab.name === 'threads' && <ThreadsView /> }
						{ tab.name === 'contacts' && <ContactsView /> }
						{ tab.name === 'settings' && <SettingsView /> }
					</section>
				) }
			</TabPanel>
		</div>
	);
}
