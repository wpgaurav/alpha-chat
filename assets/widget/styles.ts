export const widgetStyles = `
:host, .root {
	all: initial;
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
	font-size: 14px;
	line-height: 1.4;
	color: var(--ac-fg);
	--ac-accent: #4f46e5;
	--ac-bg: #ffffff;
	--ac-fg: #1f2937;
	--ac-muted: #6b7280;
	--ac-border: #e5e7eb;
	--ac-user-bubble: var(--ac-accent);
	--ac-assistant-bubble: #f3f4f6;
	--ac-radius: 20px;
}

* {
	box-sizing: border-box;
}

.launcher {
	position: fixed;
	inset-block-end: 1.25rem;
	z-index: 2147483000;
	display: flex;
	align-items: center;
	gap: 0.5rem;
	pointer-events: none;
}

.launcher.pos-right { inset-inline-end: 1.25rem; }
.launcher.pos-left  { inset-inline-start: 1.25rem; flex-direction: row-reverse; }
.launcher.pos-center {
	inset-inline-start: 50%;
	transform: translateX(-50%);
	flex-direction: column;
	align-items: center;
	gap: 0.6rem;
}

.launcher > * { pointer-events: auto; }

.nudge {
	display: flex;
	align-items: center;
	gap: 0.6rem;
	background: #fff;
	color: var(--ac-fg);
	border: 1px solid var(--ac-border);
	border-radius: 999px;
	padding: 0.5rem 0.9rem 0.5rem 0.7rem;
	box-shadow: 0 10px 30px rgba(17, 24, 39, 0.12);
	cursor: pointer;
	font: inherit;
	font-size: 14px;
	max-width: 320px;
	transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.nudge:hover { transform: translateY(-1px); box-shadow: 0 14px 34px rgba(17, 24, 39, 0.16); }
.nudge__icon { display: inline-flex; width: 22px; height: 22px; color: var(--ac-fg); flex-shrink: 0; }
.nudge__icon svg { width: 100%; height: 100%; }
.nudge__text { font-size: 14px; color: var(--ac-muted); }
.nudge__arrow {
	display: inline-flex;
	width: 30px;
	height: 30px;
	align-items: center;
	justify-content: center;
	background: var(--ac-accent);
	color: #fff;
	border-radius: 999px;
	margin-inline-start: 0.25rem;
	flex-shrink: 0;
}
.nudge__arrow svg { width: 16px; height: 16px; }

.toggle {
	width: 52px;
	height: 52px;
	border-radius: 50%;
	background: var(--ac-accent);
	color: #fff;
	border: 0;
	cursor: pointer;
	box-shadow: 0 10px 28px rgba(17, 24, 39, 0.22);
	display: flex;
	align-items: center;
	justify-content: center;
	font: inherit;
	transition: transform 0.15s ease;
}
.toggle:hover { transform: scale(1.05); }
.toggle svg { width: 22px; height: 22px; }

.panel {
	position: fixed;
	inset-block-end: 1.25rem;
	z-index: 2147483000;
	width: min(420px, calc(100vw - 2rem));
	height: min(640px, calc(100vh - 6rem));
	background: var(--ac-bg);
	border-radius: 24px;
	border: 1px solid var(--ac-border);
	box-shadow: 0 30px 60px rgba(17, 24, 39, 0.2);
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.panel.pos-right { inset-inline-end: 1.25rem; }
.panel.pos-left  { inset-inline-start: 1.25rem; }
.panel.pos-center {
	inset-inline-start: 50%;
	transform: translateX(-50%);
}

.panel.is-inline {
	position: relative;
	inset: auto;
	transform: none;
	width: 100%;
	max-width: 640px;
	margin: 0 auto;
	height: min(640px, 80vh);
}

.header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 0.9rem 1.1rem;
	border-block-end: 1px solid var(--ac-border);
	background: #fff;
}
.header__title {
	font-weight: 600;
	font-size: 15px;
	color: var(--ac-fg);
}
.header__actions { display: flex; gap: 0.25rem; }
.header__btn {
	background: transparent;
	border: 0;
	color: var(--ac-muted);
	cursor: pointer;
	padding: 0.35rem 0.6rem;
	border-radius: 8px;
	font: inherit;
	font-size: 13px;
}
.header__btn:hover { background: #f3f4f6; color: var(--ac-fg); }

.log {
	flex: 1;
	overflow-y: auto;
	padding: 1rem;
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	background: #fafafa;
}

.msg { display: flex; flex-direction: column; gap: 0.35rem; max-width: 85%; }
.msg.is-user { align-self: flex-end; align-items: flex-end; }
.msg.is-assistant { align-self: flex-start; align-items: flex-start; }

.bubble {
	padding: 0.65rem 0.9rem;
	border-radius: 16px;
	white-space: pre-wrap;
	word-break: break-word;
	font-size: 14px;
	line-height: 1.5;
}
.msg.is-user .bubble {
	background: var(--ac-user-bubble);
	color: #fff;
	border-bottom-right-radius: 6px;
}
.msg.is-assistant .bubble {
	background: var(--ac-assistant-bubble);
	color: var(--ac-fg);
	border-bottom-left-radius: 6px;
}
.msg.is-typing .bubble { opacity: 0.7; font-style: italic; }

.sources { display: flex; flex-direction: column; gap: 0.35rem; width: 100%; margin-block-start: 0.15rem; }
.source {
	display: flex;
	align-items: center;
	gap: 0.6rem;
	text-decoration: none;
	color: var(--ac-fg);
	background: #fff;
	border: 1px solid var(--ac-border);
	border-radius: 12px;
	padding: 0.45rem 0.6rem;
	font-size: 13px;
	transition: border-color 0.15s ease, transform 0.15s ease;
}
.source:hover { border-color: var(--ac-accent); transform: translateY(-1px); }
.source img {
	width: 40px;
	height: 40px;
	object-fit: cover;
	border-radius: 8px;
	flex-shrink: 0;
}
.source__title {
	font-weight: 500;
	line-height: 1.3;
	overflow: hidden;
	display: -webkit-box;
	-webkit-line-clamp: 2;
	-webkit-box-orient: vertical;
}

.contact-cta {
	align-self: center;
	margin-block-start: 0.25rem;
	background: #fff;
	border: 1px solid var(--ac-border);
	color: var(--ac-accent);
	border-radius: 999px;
	padding: 0.4rem 0.9rem;
	font: inherit;
	font-size: 13px;
	font-weight: 500;
	cursor: pointer;
}
.contact-cta:hover { background: var(--ac-accent); color: #fff; border-color: var(--ac-accent); }

.contact-form {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	background: #fff;
	border: 1px solid var(--ac-border);
	border-radius: 14px;
	padding: 0.8rem;
}
.contact-form__title { font-weight: 600; font-size: 14px; }
.contact-form input,
.contact-form textarea {
	font: inherit;
	font-size: 13px;
	color: var(--ac-fg);
	border: 1px solid var(--ac-border);
	border-radius: 8px;
	padding: 0.55rem 0.7rem;
	background: #fff;
	width: 100%;
	resize: vertical;
}
.contact-form input:focus,
.contact-form textarea:focus { outline: 2px solid var(--ac-accent); outline-offset: -1px; border-color: transparent; }
.contact-form__actions { display: flex; justify-content: flex-end; gap: 0.4rem; }
.contact-form__cancel {
	background: transparent;
	border: 0;
	color: var(--ac-muted);
	cursor: pointer;
	font: inherit;
	font-size: 13px;
	padding: 0.4rem 0.7rem;
}
.contact-form__submit {
	background: var(--ac-accent);
	color: #fff;
	border: 0;
	border-radius: 8px;
	padding: 0.5rem 1rem;
	cursor: pointer;
	font: inherit;
	font-size: 13px;
	font-weight: 600;
}
.contact-form__submit:disabled { opacity: 0.5; cursor: not-allowed; }

.error { color: #b91c1c; font-size: 12px; }

.form {
	display: flex;
	gap: 0.5rem;
	padding: 0.75rem;
	background: #fff;
	border-block-start: 1px solid var(--ac-border);
}
.form__input {
	flex: 1;
	resize: none;
	border: 1px solid var(--ac-border);
	border-radius: 12px;
	padding: 0.6rem 0.75rem;
	font: inherit;
	font-size: 14px;
	color: var(--ac-fg);
	background: #fff;
	min-height: 44px;
	max-height: 120px;
}
.form__input:focus { outline: 2px solid var(--ac-accent); outline-offset: -1px; border-color: transparent; }

.form__send {
	width: 44px;
	height: 44px;
	flex-shrink: 0;
	background: var(--ac-accent);
	color: #fff;
	border: 0;
	border-radius: 12px;
	cursor: pointer;
	display: flex;
	align-items: center;
	justify-content: center;
}
.form__send:disabled { opacity: 0.4; cursor: not-allowed; }
.form__send svg { width: 18px; height: 18px; }

@media (max-width: 520px) {
	.panel {
		width: calc(100vw - 1rem);
		height: calc(100vh - 5rem);
		inset-inline-end: 0.5rem;
		inset-inline-start: 0.5rem;
	}
	.panel.pos-center { transform: none; }
}
`;
