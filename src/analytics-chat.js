/**
 * The analyst's chat bubble on the Visitors section (tickets/analytics-chat.md,
 * phase 2). It looks like the editors' bubble (agent-chat.css and
 * agent-bubble.css, enqueued with it) and talks to
 * mody-platform/v1/analytics/analyst, which answers when the turn ends.
 * When Mody changed the board, the board redraws and the reply offers Undo.
 * Text goes in with textContent only.
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const ROUTE = '/mody-platform/v1/analytics/analyst';
const BOARD = '/mody-platform/v1/analytics/board';

/**
 * Creates an element with a class and text.
 *
 * @param {string} tag       The tag.
 * @param {string} className The class, or ''.
 * @param {string} text      The text, or ''.
 * @return {HTMLElement} The element.
 */
function el(tag, className = '', text = '') {
	const node = document.createElement(tag);
	if (className) {
		node.className = className;
	}
	if (text !== '') {
		node.textContent = text;
	}
	return node;
}

/**
 * A chart-and-bubble icon, drawn with DOM calls.
 *
 * @return {SVGElement} The icon.
 */
function icon() {
	const ns = 'http://www.w3.org/2000/svg';
	const svg = document.createElementNS(ns, 'svg');
	svg.setAttribute('viewBox', '0 0 24 24');
	svg.setAttribute('width', '26');
	svg.setAttribute('height', '26');
	svg.setAttribute('aria-hidden', 'true');
	const path = document.createElementNS(ns, 'path');
	path.setAttribute(
		'd',
		'M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-5 4v-4H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zm3 10h2V9H7zm4 0h2V7h-2zm4 0h2v-3h-2z'
	);
	path.setAttribute('fill', 'currentColor');
	svg.append(path);
	return svg;
}

/**
 * Starts the bubble.
 *
 * @param {Object}   options         The board's hooks.
 * @param {Function} options.days    Returns the board's period.
 * @param {Function} options.onBoard Called with a new board and the ids that changed.
 * @return {{ask: Function}} ask(text): opens the bubble with text to finish.
 */
export function startChat({ days, onBoard }) {
	const root = el('div', 'mody-bubble mody-analyst');
	root.id = 'mody-analyst-bubble';
	const panel = el('section', 'mody-bubble__panel mody-agent');
	panel.id = 'mody-analyst-panel';
	panel.hidden = true;
	panel.setAttribute('role', 'dialog');
	panel.setAttribute(
		'aria-label',
		__('Ask Mody about your visitors', 'mody_cloud')
	);

	const header = el('header', 'mody-bubble__header');
	const title = el(
		'h2',
		'mody-bubble__title',
		__('Ask Mody about your visitors', 'mody_cloud')
	);
	const reset = el(
		'button',
		'button-link mody-bubble__reset',
		__('New conversation', 'mody_cloud')
	);
	reset.type = 'button';
	const close = el('button', 'mody-bubble__close', '×');
	close.type = 'button';
	close.setAttribute('aria-label', __('Close', 'mody_cloud'));
	header.append(title, reset, close);

	const log = el('div', 'mody-agent__log');
	log.setAttribute('aria-live', 'polite');
	const chips = el('div', 'mody-agent__chips');
	[
		__('Make visits per day a line chart', 'mody_cloud'),
		__('Add a donut of devices for the last 7 days', 'mody_cloud'),
		__(
			'Which pages do visits from search engines open first?',
			'mody_cloud'
		),
		__('Show the busiest hours', 'mody_cloud'),
	].forEach((text) => {
		const chip = el('button', 'button mody-agent__chip', text);
		chip.type = 'button';
		chip.addEventListener('click', () => send(text));
		chips.append(chip);
	});

	const form = el('form', 'mody-agent__form');
	const label = el(
		'label',
		'screen-reader-text',
		__('Your message', 'mody_cloud')
	);
	label.htmlFor = 'mody-analyst-input';
	const input = el('textarea');
	input.id = 'mody-analyst-input';
	input.rows = 2;
	input.maxLength = 1000;
	input.placeholder = __(
		'Ask about your visitors, or describe a chart…',
		'mody_cloud'
	);
	const buttons = el('div', 'mody-agent__buttons');
	const submit = el(
		'button',
		'button button-primary',
		__('Send', 'mody_cloud')
	);
	submit.type = 'submit';
	buttons.append(submit);
	form.append(label, input, buttons);
	panel.append(header, log, chips, form);

	const toggle = el('button', 'mody-bubble__toggle');
	toggle.type = 'button';
	toggle.setAttribute(
		'aria-label',
		__('Ask Mody about your visitors', 'mody_cloud')
	);
	toggle.setAttribute('aria-expanded', 'false');
	toggle.setAttribute('aria-controls', panel.id);
	toggle.append(icon());
	root.append(panel, toggle);
	document.body.append(root);

	/* The earlier conversation, loaded once: a message waits for it, so it never lands above it. */
	let loading = null;
	let busy = false;

	const message = (role, text, extra = {}) => {
		const node = el(
			'div',
			'mody-agent__message' +
				(role === 'user' ? ' mody-agent__message--user' : '')
		);
		node.append(
			el(
				'span',
				'mody-agent__who',
				role === 'user' ? __('You', 'mody_cloud') : 'Mody'
			),
			el('p', 'mody-agent__text', text)
		);
		if (extra.error) {
			node.classList.add('is-error');
		}
		if (extra.refused) {
			node.classList.add('is-refused');
		}
		if (extra.choices && extra.choices.length) {
			const choices = el('div', 'mody-agent__choices');
			extra.choices.forEach((choice) => {
				const b = el('button', 'button', choice);
				b.type = 'button';
				b.addEventListener('click', () => send(choice));
				choices.append(b);
			});
			node.append(choices);
		}
		if (extra.undo) {
			const actions = el('div', 'mody-agent__actions');
			const undo = el(
				'button',
				'button mody-bubble__undo',
				__('Undo', 'mody_cloud')
			);
			undo.type = 'button';
			undo.addEventListener('click', () => {
				undo.disabled = true;
				apiFetch({
					path: BOARD,
					method: 'POST',
					data: { action: 'undo', days: days() },
				})
					.then((board) => {
						onBoard(board, []);
						undo.textContent = __('Undone', 'mody_cloud');
					})
					.catch((error) => {
						undo.disabled = false;
						message(
							'assistant',
							error?.message ||
								__(
									'That did not work. Try again.',
									'mody_cloud'
								),
							{ error: true }
						);
					});
			});
			actions.append(undo);
			node.append(actions);
		}
		log.append(node);
		log.scrollTop = log.scrollHeight;
		return node;
	};

	const load = () => {
		if (!loading) {
			loading = apiFetch({ path: ROUTE })
				.then((data) => {
					const earlier = data.messages || [];
					earlier.forEach((m) =>
						message(
							m.role === 'user' ? 'user' : 'assistant',
							m.text,
							{
								refused: m.refused,
							}
						)
					);
					chips.hidden = chips.hidden || earlier.length > 0;
				})
				.catch(() => {});
		}
		return loading;
	};

	function send(text) {
		const value = String(text || '').trim();
		if (!value || busy) {
			return;
		}
		busy = true;
		submit.disabled = true;
		chips.hidden = true;
		input.value = '';
		load().then(() => post(value));
	}

	/**
	 * Sends a message, once the earlier conversation is on screen.
	 *
	 * @param {string} value The message.
	 */
	function post(value) {
		message('user', value);
		const pending = message('assistant', __('Counting…', 'mody_cloud'));
		pending.classList.add('is-pending');
		pending
			.querySelector('.mody-agent__text')
			.before(el('span', 'mody-agent__spinner'));
		apiFetch({
			path: ROUTE,
			method: 'POST',
			data: { message: value, days: days() },
		})
			.then((data) => {
				pending.remove();
				const turn = data.turn || {};
				message('assistant', turn.reply || '', {
					error: turn.error,
					refused: turn.refused,
					choices: turn.choices,
					undo: !!data.board && !!turn.undo,
				});
				if (data.board) {
					onBoard(data.board, turn.changed || []);
				}
			})
			.catch((error) => {
				pending.remove();
				message(
					'assistant',
					error?.message ||
						__(
							'Mody is not available right now. Please try again in a moment.',
							'mody_cloud'
						),
					{ error: true }
				);
			})
			.finally(() => {
				busy = false;
				submit.disabled = false;
				input.focus();
			});
	}

	const open = () => {
		panel.hidden = false;
		root.classList.add('is-open');
		toggle.setAttribute('aria-expanded', 'true');
		load();
		input.focus();
	};
	const shut = () => {
		panel.hidden = true;
		root.classList.remove('is-open');
		toggle.setAttribute('aria-expanded', 'false');
		toggle.focus();
	};

	toggle.addEventListener('click', () => (panel.hidden ? open() : shut()));
	close.addEventListener('click', shut);
	panel.addEventListener('keydown', (event) => {
		if (event.key === 'Escape') {
			shut();
		}
	});
	reset.addEventListener('click', () => {
		apiFetch({ path: ROUTE, method: 'DELETE' }).then(() => {
			log.replaceChildren();
			chips.hidden = false;
			input.focus();
		});
	});
	form.addEventListener('submit', (event) => {
		event.preventDefault();
		send(input.value);
	});
	input.addEventListener('keydown', (event) => {
		if (event.key === 'Enter' && !event.shiftKey) {
			event.preventDefault();
			send(input.value);
		}
	});

	return {
		ask(text) {
			open();
			input.value = text;
			input.setSelectionRange(text.length, text.length);
		},
	};
}
