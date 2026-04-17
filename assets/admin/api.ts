declare const alphaChatAdmin: {
	restUrl: string;
	restNonce: string;
	version: string;
};

type ApiInit = Omit< RequestInit, 'body' | 'headers' > & {
	body?: unknown;
	query?: Record< string, string | number | undefined >;
	headers?: Record< string, string >;
};

async function api< T >( path: string, init: ApiInit = {} ): Promise< T > {
	const url = new URL( `${ alphaChatAdmin.restUrl }${ path }`, window.location.origin );
	if ( init.query ) {
		Object.entries( init.query ).forEach( ( [ key, value ] ) => {
			if ( value === undefined ) {
				return;
			}
			url.searchParams.set( key, String( value ) );
		} );
	}

	const response = await fetch( url.toString(), {
		...init,
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': alphaChatAdmin.restNonce,
			...( init.headers ?? {} ),
		},
		body: init.body !== undefined ? JSON.stringify( init.body ) : undefined,
		credentials: 'same-origin',
	} );

	const raw = await response.text();
	const data = raw ? JSON.parse( raw ) : null;

	if ( ! response.ok ) {
		const message = data?.message ?? `Request failed (${ response.status })`;
		throw new Error( message );
	}

	return data as T;
}

export type Settings = {
	llm_provider: 'openai' | 'anthropic';
	chat_enabled: boolean;
	show_launcher: boolean;
	moderation_enabled: boolean;
	openai_api_key: string;
	anthropic_api_key: string;
	chat_model: string;
	embedding_model: string;
	temperature: number;
	top_p: number;
	max_response_tokens: number;
	max_context_chunks: number;
	chunk_size_tokens: number;
	chunk_overlap_tokens: number;
	similarity_score_threshold: number;
	welcome_message: string;
	fallback_message: string;
	system_prompt: string;
	predefined_questions: string[];
	colors: Record< string, string >;
	launcher_nudge: string;
	launcher_position: 'left' | 'center' | 'right';
	brand_name: string;
	contact_form_enabled: boolean;
	contact_cta_label: string;
	contact_success_message: string;
	contact_notify_email: string;
};

export type Faq = {
	id: number;
	question: string;
	answer: string;
	sort_order: number;
	enabled: boolean;
	created_at: string;
	updated_at: string;
};

export type Contact = {
	id: number;
	thread_uuid: string;
	name: string;
	email: string;
	message: string;
	user_id: number | null;
	status: string;
	created_at: string;
};

export type Stats = {
	chunks: number;
	posts: number;
};

export type SettingsResponse = {
	settings: Settings;
	stats: Stats;
};

export type KbItem = {
	id: number;
	title: string;
	type: string;
	status: string;
	url: string;
	modified: string;
	indexed: boolean;
	chunk_count: number;
	last_indexed: number;
	index_error: string;
};

export type KbList = {
	items: KbItem[];
	total: number;
	total_pages: number;
};

export type Thread = {
	id: number;
	uuid: string;
	user_id: number | null;
	title: string;
	message_count: number;
	created_at: string;
	updated_at: string;
};

export type Message = {
	id: number;
	thread_id: number;
	role: string;
	content: string;
	token_count: number;
	metadata: unknown;
	created_at: string;
};

export type ChartData = {
	labels: string[];
	messages: number[];
	sessions: number[];
};

export const adminApi = {
	getSettings: () => api< SettingsResponse >( '/settings' ),
	saveSettings: ( data: Partial< Settings > ) =>
		api< SettingsResponse >( '/settings', { method: 'POST', body: { data } } ),
	listKnowledgeBase: ( params: {
		search?: string;
		post_type?: string;
		indexed?: 'any' | 'yes' | 'no';
		page?: number;
		per_page?: number;
	} ) => api< KbList >( '/knowledge-base', { query: params } ),
	addToKnowledgeBase: ( postId: number ) =>
		api< { queued: boolean; post_id: number } >(
			`/knowledge-base/${ postId }`,
			{ method: 'POST' }
		),
	removeFromKnowledgeBase: ( postId: number ) =>
		api< { removed: boolean; post_id: number } >(
			`/knowledge-base/${ postId }`,
			{ method: 'DELETE' }
		),
	reindexAll: () =>
		api< { queued: number } >( '/knowledge-base/reindex-all', { method: 'POST' } ),
	listPostTypes: () =>
		api< { items: { slug: string; label: string }[] } >(
			'/knowledge-base/post-types'
		),
	bulkKnowledgeBase: ( action: 'add' | 'remove', postIds: number[] ) =>
		api< { queued: number; removed: number } >( '/knowledge-base/bulk', {
			method: 'POST',
			body: { action, post_ids: postIds },
		} ),
	indexRemaining: () =>
		api< { queued: number } >( '/knowledge-base/index-remaining', { method: 'POST' } ),
	getQueueStats: () =>
		api< { pending: number; in_progress: number; complete: number; failed: number } >(
			'/knowledge-base/queue'
		),
	processQueue: () =>
		api< {
			before: { pending: number; in_progress: number; complete: number; failed: number };
			after: { pending: number; in_progress: number; complete: number; failed: number };
			processed: number;
		} >( '/knowledge-base/queue', { method: 'POST' } ),
	listThreads: ( page: number, perPage: number ) =>
		api< { items: Thread[]; total: number } >( '/threads', {
			query: { page, per_page: perPage },
		} ),
	getThread: ( id: number ) =>
		api< { thread: Thread; messages: Message[] } >( `/threads/${ id }` ),
	deleteThread: ( id: number ) =>
		api< { deleted: boolean } >( `/threads/${ id }`, { method: 'DELETE' } ),
	getChart: ( days: number ) => api< ChartData >( '/threads/chart', { query: { days } } ),
	listContacts: ( page: number, perPage: number ) =>
		api< { items: Contact[]; total: number } >( '/contacts', {
			query: { page, per_page: perPage },
		} ),
	deleteContact: ( id: number ) =>
		api< { deleted: boolean } >( `/contacts/${ id }`, { method: 'DELETE' } ),
	listFaqs: () => api< { items: Faq[] } >( '/faqs' ),
	createFaq: ( data: { question: string; answer: string; enabled: boolean; sort_order: number } ) =>
		api< { id: number; item: Faq } >( '/faqs', { method: 'POST', body: data } ),
	updateFaq: ( id: number, data: Partial< Omit< Faq, 'id' | 'created_at' | 'updated_at' > > ) =>
		api< { item: Faq } >( `/faqs/${ id }`, { method: 'PUT', body: data } ),
	deleteFaq: ( id: number ) =>
		api< { deleted: boolean } >( `/faqs/${ id }`, { method: 'DELETE' } ),
};
