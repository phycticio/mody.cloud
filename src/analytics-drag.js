/**
 * Dragging the analytics board's cards into a new order (2026-11-13).
 *
 * Pointer events, so it works with a mouse, a finger or a pen; the
 * browser's own drag and drop does not work on phones. With a mouse a card
 * is dragged by its header; with touch or a pen by its grip, which keeps
 * the rest of the card free for scrolling. A drag starts after a few
 * pixels, so clicks stay clicks. While dragging, a placeholder shows where
 * the card will land; Escape puts it back. The page scrolls near the top
 * and bottom edges.
 *
 * The card's up and down buttons stay: they are the way to move a card
 * from the keyboard (WCAG 2.5.7).
 */

/** Pixels the pointer moves before a press becomes a drag. */
const THRESHOLD = 5;

/** Pixels from the window's top or bottom where the page scrolls. */
const EDGE = 64;

/**
 * Lets the cards in a board be dragged.
 *
 * @param {HTMLElement} root            The board's element (its grid is redrawn; events are delegated).
 * @param {Object}      options         Hooks.
 * @param {Function}    options.canDrag Returns whether a drag may start now.
 * @param {Function}    options.onDrop  Called with the card's id and its new position (from 0).
 * @return {{cancel: Function}} cancel(): ends a drag without moving the card.
 */
export function enableDrag(root, { canDrag, onDrop }) {
	let state = null;
	let frame = 0;

	const cardsIn = (grid, without) =>
		Array.from(grid.children).filter(
			(node) =>
				node !== without &&
				(node.classList.contains('mody-board__card') ||
					node.classList.contains('mody-board__placeholder'))
		);

	const begin = () => {
		const { card } = state;
		const rect = card.getBoundingClientRect();
		const placeholder = document.createElement('div');
		placeholder.className = card.className
			.split(' ')
			.filter((name) => name !== 'is-changed')
			.concat('mody-board__placeholder')
			.join(' ');
		placeholder.style.height = `${rect.height}px`;
		placeholder.setAttribute('aria-hidden', 'true');
		card.before(placeholder);
		Object.assign(card.style, {
			position: 'fixed',
			left: `${rect.left}px`,
			top: `${rect.top}px`,
			width: `${rect.width}px`,
			height: `${rect.height}px`,
			zIndex: '100000',
			pointerEvents: 'none',
		});
		card.classList.add('is-dragging');
		document.body.classList.add('mody-board-dragging');
		card.ownerDocument.defaultView.getSelection()?.removeAllRanges();
		state.placeholder = placeholder;
		state.dragging = true;
		frame = window.requestAnimationFrame(scroll);
	};

	/* Where the placeholder goes: before or after the card under the pointer. */
	const place = () => {
		const { card, grid, placeholder, x, y } = state;
		const under = document.elementFromPoint(x, y);
		if (!under || placeholder.contains(under)) {
			return;
		}
		const target = under.closest('.mody-board__card');
		if (target && target !== card && grid.contains(target)) {
			const box = target.getBoundingClientRect();
			const wide = box.width > grid.clientWidth * 0.6;
			const before = wide
				? y < box.top + box.height / 2
				: x < box.left + box.width / 2;
			if (before && target.previousElementSibling !== placeholder) {
				target.before(placeholder);
			} else if (!before && target.nextElementSibling !== placeholder) {
				target.after(placeholder);
			}
			return;
		}
		// Past the last card: the end.
		const others = cardsIn(grid, card).filter(
			(node) => node !== placeholder
		);
		const last = others[others.length - 1];
		if (
			last &&
			grid.contains(under) &&
			y > last.getBoundingClientRect().bottom
		) {
			grid.append(placeholder);
		}
	};

	/* Near the window's edges, the page scrolls, and the placeholder follows. */
	const scroll = () => {
		if (!state || !state.dragging) {
			return;
		}
		const { y } = state;
		let by = 0;
		if (y < EDGE) {
			by = -Math.ceil((EDGE - y) / 4);
		} else if (y > window.innerHeight - EDGE) {
			by = Math.ceil((y - (window.innerHeight - EDGE)) / 4);
		}
		if (by) {
			window.scrollBy(0, by);
			place();
		}
		frame = window.requestAnimationFrame(scroll);
	};

	const finish = (drop) => {
		if (!state) {
			return;
		}
		const { card, grid, placeholder, dragging, from } = state;
		window.cancelAnimationFrame(frame);
		window.removeEventListener('pointermove', move);
		window.removeEventListener('pointerup', up);
		window.removeEventListener('pointercancel', cancel);
		window.removeEventListener('keydown', key, true);
		state = null;
		if (!dragging) {
			return;
		}
		const to = cardsIn(grid, card).indexOf(placeholder);
		card.removeAttribute('style');
		card.classList.remove('is-dragging');
		document.body.classList.remove('mody-board-dragging');
		if (drop && to >= 0) {
			placeholder.replaceWith(card);
		} else {
			placeholder.remove();
		}
		if (drop && to >= 0 && to !== from) {
			onDrop(card.dataset.id, to);
		}
	};

	function move(event) {
		if (!state || event.pointerId !== state.pointer) {
			return;
		}
		state.x = event.clientX;
		state.y = event.clientY;
		if (!state.dragging) {
			if (
				Math.hypot(
					event.clientX - state.startX,
					event.clientY - state.startY
				) < THRESHOLD
			) {
				return;
			}
			begin();
		}
		event.preventDefault();
		state.card.style.transform = `translate(${
			event.clientX - state.startX
		}px, ${event.clientY - state.startY}px)`;
		place();
	}

	function up(event) {
		if (state && event.pointerId === state.pointer) {
			finish(true);
		}
	}

	function cancel() {
		finish(false);
	}

	function key(event) {
		if (event.key === 'Escape' && state && state.dragging) {
			event.preventDefault();
			event.stopPropagation();
			finish(false);
		}
	}

	root.addEventListener('pointerdown', (event) => {
		if (state || !event.isPrimary || event.button !== 0 || !canDrag()) {
			return;
		}
		const head = event.target.closest('.mody-board__card-head');
		if (
			!head ||
			event.target.closest('button, a, input, select, textarea')
		) {
			return;
		}
		// A finger or pen drags by the grip only, so the card still scrolls the page.
		if (
			event.pointerType !== 'mouse' &&
			!event.target.closest('.mody-board__grip')
		) {
			return;
		}
		const card = head.closest('.mody-board__card');
		const grid = card && card.parentElement;
		if (!card || !grid) {
			return;
		}
		if (event.pointerType !== 'mouse') {
			event.preventDefault();
		}
		state = {
			card,
			grid,
			pointer: event.pointerId,
			startX: event.clientX,
			startY: event.clientY,
			x: event.clientX,
			y: event.clientY,
			from: cardsIn(grid, null).indexOf(card),
			dragging: false,
			placeholder: null,
		};
		window.addEventListener('pointermove', move, { passive: false });
		window.addEventListener('pointerup', up);
		window.addEventListener('pointercancel', cancel);
		window.addEventListener('keydown', key, true);
	});

	return {
		/** Ends a drag without moving the card (the board is about to redraw). */
		cancel() {
			finish(false);
		},
	};
}
