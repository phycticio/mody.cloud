/**
 * The analytics board (tickets/analytics-chat.md): the Visitors section's
 * charts, drawn from the specs and numbers the server sends
 * (Dashboard\Board). Chart.js draws lines, areas, bars and donuts; tiles,
 * lists, tables, journeys and the heatmap are plain HTML. Every chart has a
 * table view. Text goes in with textContent only.
 *
 * Built into app/dist/analytics-board.js; the platform enqueues it on the
 * Visitors section with the board in window.modyAnalyticsBoard.
 */
import {
	ArcElement,
	BarController,
	BarElement,
	CategoryScale,
	Chart,
	DoughnutController,
	Filler,
	Legend,
	LinearScale,
	LineController,
	LineElement,
	PointElement,
	Tooltip,
} from 'chart.js';

import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

import { startChat } from './analytics-chat';

Chart.register(
	ArcElement,
	BarController,
	BarElement,
	CategoryScale,
	DoughnutController,
	Filler,
	Legend,
	LinearScale,
	LineController,
	LineElement,
	PointElement,
	Tooltip
);

const ROUTE = '/mody-platform/v1/analytics/board';
const COUNTS = ['visits', 'views', 'entries', 'exits'];
const GRID = '#f0f0f1';
const INK = '#50575e';
const SURFACE = '#ffffff';
/* The dataviz reference palette's sequential blue, light to dark: the heatmap. */
const RAMP = [
	'#cde2fb',
	'#9ec5f4',
	'#6da7ec',
	'#3987e5',
	'#256abf',
	'#184f95',
	'#0d366b',
];
const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const charts = [];
/* Whether the analyst's bubble is on the page (phase 2): cards get Ask Mody. */
let withAnalyst = false;

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
 * The admin color scheme's color, as hex when the browser gives it so.
 *
 * @return {string} A CSS color.
 */
function adminColor() {
	const value = getComputedStyle(document.body)
		.getPropertyValue('--wp-admin-theme-color')
		.trim();
	return value || '#2271b1';
}

/**
 * A card's color as CSS: `admin` becomes the scheme's color.
 *
 * @param {string} color Hex or 'admin'.
 * @return {string} A CSS color.
 */
function css(color) {
	return color === 'admin' ? adminColor() : color;
}

/**
 * A color at an opacity.
 *
 * @param {string} color A CSS color (hex or rgb).
 * @param {number} alpha 0 to 1.
 * @return {string} rgba().
 */
function fade(color, alpha) {
	const hex = color.replace('#', '');
	let rgb = color.match(/\d+(\.\d+)?/g);
	if (/^[0-9a-f]{6}$/i.test(hex)) {
		rgb = [0, 2, 4].map((at) => parseInt(hex.slice(at, at + 2), 16));
	}
	if (!rgb || rgb.length < 3) {
		return color;
	}
	return `rgba(${rgb[0]}, ${rgb[1]}, ${rgb[2]}, ${alpha})`;
}

/**
 * A number as people read it, by metric.
 *
 * @param {number} value  The value.
 * @param {string} metric The metric.
 * @return {string} The text.
 */
function format(value, metric) {
	const lang = document.documentElement.lang || undefined;
	if (metric === 'pages_per_visit') {
		return Number(value).toLocaleString(lang, {
			minimumFractionDigits: 1,
			maximumFractionDigits: 1,
		});
	}
	if (metric === 'single_page_rate') {
		return (
			Number(value).toLocaleString(lang, { maximumFractionDigits: 0 }) +
			'%'
		);
	}
	return Number(value).toLocaleString(lang);
}

/**
 * The change on the period before, or ''.
 *
 * @param {number} now    This period.
 * @param {number} before The period before.
 * @return {string} "+12% on the period before", or ''.
 */
function change(now, before) {
	if (!before) {
		return '';
	}
	const pct = Math.round((100 * (now - before)) / before);
	return sprintf(
		/* translators: %s: a signed percentage, such as +12. */
		__('%s%% on the period before', 'mody_cloud'),
		(pct > 0 ? '+' : '') + pct.toLocaleString()
	);
}

/**
 * A tile: one number and its change.
 *
 * @param {Object} card The card.
 * @return {HTMLElement} The body.
 */
function tile(card) {
	const series = card.data.series[0];
	const box = el('div', 'mody-board__tile');
	box.append(
		el(
			'span',
			'mody-dashboard__tile-value',
			format(series.data[0], series.metric)
		)
	);
	if (series.previous) {
		const text = change(series.data[0], series.previous[0]);
		if (text) {
			box.append(el('span', 'mody-dashboard__tile-detail', text));
		}
	}
	return box;
}

/**
 * A ranked list with share bars.
 *
 * @param {Object} card The card.
 * @return {HTMLElement} The body.
 */
function list(card) {
	const { labels, series } = card.data;
	const box = el('section', 'mody-dashboard__list');
	const head = el('h4', 'mody-board__list-head');
	head.append(el('span', '', ''), el('span', '', series[0].label));
	box.append(head);
	if (!labels.length) {
		box.append(
			el('p', 'mody-brand__empty', __('Nothing yet', 'mody_cloud'))
		);
		return box;
	}
	const max = Math.max(1, ...series[0].data);
	const ol = el('ol');
	labels.forEach((label, i) => {
		const li = el('li');
		li.style.setProperty(
			'--mody-share',
			`${Math.round((1000 * series[0].data[i]) / max) / 10}%`
		);
		li.append(
			el('span', 'mody-dashboard__list-name', label),
			el(
				'span',
				'mody-dashboard__list-count',
				format(series[0].data[i], series[0].metric)
			)
		);
		ol.append(li);
	});
	box.append(ol);
	return box;
}

/**
 * Journeys: each visit pattern's pages in order, with its visits.
 *
 * @param {Object} card The card.
 * @return {HTMLElement} The body.
 */
function journeys(card) {
	const { keys, series, more } = card.data;
	const box = el('section', 'mody-dashboard__list mody-dashboard__journeys');
	if (!keys.length) {
		box.append(
			el(
				'p',
				'mody-brand__empty',
				__(
					'Nothing yet: journeys show when visits see more than one page.',
					'mody_cloud'
				)
			)
		);
		return box;
	}
	const max = Math.max(1, ...series[0].data);
	const ol = el('ol');
	keys.forEach((key, i) => {
		const li = el('li');
		li.style.setProperty(
			'--mody-share',
			`${Math.round((1000 * series[0].data[i]) / max) / 10}%`
		);
		const steps = el(
			'span',
			'mody-dashboard__list-name mody-dashboard__steps'
		);
		const parts = key.split('\n');
		if (more[i]) {
			parts.push(null);
		}
		parts.forEach((step, n) => {
			if (n > 0) {
				const arrow = el('span', 'mody-dashboard__step-arrow', '→');
				arrow.setAttribute('aria-hidden', 'true');
				steps.append(
					arrow,
					el('span', 'screen-reader-text', __('then', 'mody_cloud'))
				);
			}
			if (step === null) {
				steps.append(
					el(
						'span',
						'mody-dashboard__step is-more',
						__('more', 'mody_cloud')
					)
				);
			} else {
				steps.append(el('span', 'mody-dashboard__step', step));
			}
		});
		li.append(
			steps,
			el(
				'span',
				'mody-dashboard__list-count',
				format(series[0].data[i], 'visits')
			)
		);
		ol.append(li);
	});
	box.append(ol);
	return box;
}

/**
 * Weekday names, Monday first, in the page's language.
 *
 * @param {string} width 'long' or 'short'.
 * @return {string[]} Seven names.
 */
function weekdays(width) {
	const lang = document.documentElement.lang || undefined;
	const fmt = new Intl.DateTimeFormat(lang, {
		weekday: width,
		timeZone: 'UTC',
	});
	return [0, 1, 2, 3, 4, 5, 6].map((d) =>
		fmt.format(new Date(Date.UTC(2024, 0, 1 + d)))
	); // 1 January 2024 was a Monday.
}

/**
 * The heatmap: hours across, weekdays down, darker for more.
 *
 * @param {Object} card The card.
 * @return {HTMLElement} The body.
 */
function heatmap(card) {
	const { keys, series } = card.data;
	const values = {};
	keys.forEach((key, i) => {
		values[key] = series[0].data[i];
	});
	const max = Math.max(0, ...series[0].data);
	const short = weekdays('short');
	const long = weekdays('long');
	const grid = el('div', 'mody-board__heatmap');
	grid.setAttribute('aria-hidden', 'true');
	grid.append(el('span', ''));
	for (let h = 0; h < 24; h++) {
		grid.append(
			el(
				'span',
				'mody-board__heat-hour',
				h % 3 === 0 ? String(h).padStart(2, '0') : ''
			)
		);
	}
	for (let w = 0; w < 7; w++) {
		grid.append(el('span', 'mody-board__heat-day', short[w]));
		for (let h = 0; h < 24; h++) {
			const value = values[`${w}:${h}`] || 0;
			const cell = el('span', 'mody-board__heat-cell');
			let step = -1;
			if (value > 0 && max > 0) {
				step = Math.floor(((RAMP.length - 1) * value) / max);
				step = Math.min(RAMP.length - 1, step);
			}
			cell.style.background = step >= 0 ? RAMP[step] : '#f6f7f7';
			cell.dataset.tip = sprintf(
				/* translators: 1: a weekday, 2: an hour such as 14:00, 3: a number, 4: what it counts. */
				__('%1$s %2$s: %3$s %4$s', 'mody_cloud'),
				long[w],
				`${String(h).padStart(2, '0')}:00`,
				format(value, series[0].metric),
				series[0].label.toLowerCase()
			);
			grid.append(cell);
		}
	}
	const box = el('div', 'mody-board__heat');
	box.append(grid);
	const legend = el('p', 'mody-board__heat-legend');
	legend.append(el('span', '', __('Fewer', 'mody_cloud')));
	RAMP.forEach((color) => {
		const swatch = el('span', 'mody-board__heat-swatch');
		swatch.style.background = color;
		legend.append(swatch);
	});
	legend.append(el('span', '', __('More', 'mody_cloud')));
	box.append(legend);
	return box;
}

/**
 * Values at the tips of bars, when the card asks for them.
 */
const valuesPlugin = {
	id: 'modyValues',
	afterDatasetsDraw(chart, args, options) {
		if (!options.show) {
			return;
		}
		const { ctx } = chart;
		ctx.save();
		ctx.fillStyle = INK;
		ctx.font = `12px ${Chart.defaults.font.family}`;
		chart.data.datasets.forEach((dataset, d) => {
			const meta = chart.getDatasetMeta(d);
			if (meta.hidden || dataset.modyPrevious) {
				return;
			}
			meta.data.forEach((element, i) => {
				const text = format(dataset.data[i], dataset.modyMetric);
				const { x, y } = element.tooltipPosition();
				if (chart.options.indexAxis === 'y') {
					ctx.textAlign = 'left';
					ctx.textBaseline = 'middle';
					ctx.fillText(text, x + 6, y);
				} else {
					ctx.textAlign = 'center';
					ctx.textBaseline = 'bottom';
					ctx.fillText(text, x, y - 4);
				}
			});
		});
		ctx.restore();
	},
};

/**
 * A Chart.js chart: line, area, bar, hbar, stacked or donut.
 *
 * @param {Object} card The card.
 * @return {HTMLElement} The body.
 */
function drawn(card) {
	const { spec, data, colors } = card;
	const type = spec.type;
	const palette = colors.series.map(css);
	const metric = data.series[0].metric;
	const wrap = el('div', 'mody-board__canvas is-' + type);
	const canvas = el('canvas');
	canvas.setAttribute('role', 'img');
	canvas.setAttribute(
		'aria-label',
		sprintf(
			/* translators: %s: the chart's title. */
			__('%s. The numbers are in the table below.', 'mody_cloud'),
			spec.title
		)
	);
	wrap.append(canvas);
	if (type === 'hbar') {
		wrap.style.height = `${Math.max(160, data.labels.length * 30 + 40)}px`;
	}

	let datasets;
	let labels = data.labels;
	if (type === 'donut') {
		let values = data.series[0].data.slice();
		if (labels.length > 8) {
			const rest = values.slice(7).reduce((a, b) => a + b, 0);
			labels = [...labels.slice(0, 7), __('Other', 'mody_cloud')];
			values = [...values.slice(0, 7), rest];
		}
		datasets = [
			{
				data: values,
				backgroundColor: labels.map((label, i) =>
					label === __('Other', 'mody_cloud') && i === 7
						? colors.muted
						: palette[i % palette.length]
				),
				borderColor: SURFACE,
				borderWidth: 2,
				modyMetric: metric,
			},
		];
	} else {
		datasets = [];
		data.series.forEach((series, i) => {
			const color =
				series.key === '__other'
					? colors.muted
					: palette[i % palette.length];
			datasets.push(
				makeDataset(
					type,
					series.label,
					series.data,
					color,
					series.metric,
					false
				)
			);
			if (series.previous) {
				datasets.push(
					makeDataset(
						type,
						sprintf(
							/* translators: %s: what is counted, such as Visits. */
							__('%s, the period before', 'mody_cloud'),
							series.label
						),
						series.previous,
						colors.muted,
						series.metric,
						true
					)
				);
			}
		});
	}

	const many = datasets.length > 1 || type === 'donut';
	const axis = {
		grid: { color: GRID },
		border: { display: false },
		ticks: { color: INK },
	};
	const value = {
		...axis,
		beginAtZero: true,
		ticks: {
			color: INK,
			precision: COUNTS.includes(metric) ? 0 : undefined,
			callback: (v) => format(v, metric),
		},
	};
	const category = {
		...axis,
		grid: { display: false },
		ticks: { color: INK, autoSkip: true, maxRotation: 0 },
	};
	const options = {
		responsive: true,
		maintainAspectRatio: false,
		animation: reduced ? false : { duration: 300 },
		interaction: {
			mode: type === 'donut' ? 'nearest' : 'index',
			intersect: false,
		},
		plugins: {
			legend: {
				display: many && spec.style.legend,
				position: 'bottom',
				labels: {
					color: INK,
					boxWidth: 12,
					boxHeight: 12,
				},
			},
			tooltip: {
				callbacks: {
					label: (item) =>
						`${item.dataset.label || item.label}: ${format(item.raw, item.dataset.modyMetric)}`,
				},
			},
			modyValues: { show: spec.style.values && type !== 'donut' },
		},
	};
	if (type === 'area' || type === 'line') {
		// Areas fill at 10%: the swatch wears the line's color instead.
		options.plugins.legend.labels.generateLabels = (chart) =>
			Chart.defaults.plugins.legend.labels
				.generateLabels(chart)
				.map((item) => ({
					...item,
					fillStyle: item.strokeStyle,
					lineWidth: 0,
				}));
	}
	if (type === 'donut') {
		options.cutout = '60%';
		options.plugins.tooltip.callbacks.label = (item) =>
			`${item.label}: ${format(item.raw, metric)}`;
	} else if (type === 'hbar') {
		options.indexAxis = 'y';
		// Long paths keep their end: the table and the tooltip have them whole.
		category.ticks.callback = function (v) {
			const label = String(this.getLabelForValue(v));
			return label.length > 28 ? '…' + label.slice(-27) : label;
		};
		options.scales = { x: value, y: category };
	} else {
		options.scales = { x: category, y: value };
		if (type === 'stacked') {
			options.scales.x.stacked = true;
			options.scales.y.stacked = true;
		}
	}

	charts.push(
		new Chart(canvas, {
			type:
				{
					donut: 'doughnut',
					hbar: 'bar',
					stacked: 'bar',
					area: 'line',
				}[type] || type,
			data: { labels, datasets },
			options,
			plugins: [valuesPlugin],
		})
	);
	return wrap;
}

/**
 * One Chart.js dataset, with the mark specs: bars at most 24px with a 4px
 * rounded end, 2px lines, areas at 10%.
 *
 * @param {string}   type     The chart type.
 * @param {string}   label    The series' name.
 * @param {number[]} values   The numbers.
 * @param {string}   color    A CSS color.
 * @param {string}   metric   The metric.
 * @param {boolean}  previous Whether it is the period before.
 * @return {Object} The dataset.
 */
function makeDataset(type, label, values, color, metric, previous) {
	const base = {
		label,
		data: values,
		modyMetric: metric,
		modyPrevious: previous,
	};
	if (type === 'line' || type === 'area') {
		return {
			...base,
			borderColor: color,
			backgroundColor: type === 'area' ? fade(color, 0.1) : color,
			fill: type === 'area' && !previous ? 'origin' : false,
			borderWidth: 2,
			pointRadius: 0,
			pointHoverRadius: 4,
			pointHoverBorderColor: SURFACE,
			pointHoverBorderWidth: 2,
			tension: 0,
		};
	}
	if (type === 'stacked') {
		return {
			...base,
			backgroundColor: color,
			borderColor: SURFACE,
			borderWidth: { top: 2, right: 0, bottom: 0, left: 0 },
			maxBarThickness: 24,
		};
	}
	return {
		...base,
		backgroundColor: color,
		maxBarThickness: 24,
		borderRadius:
			type === 'hbar'
				? { topRight: 4, bottomRight: 4 }
				: { topLeft: 4, topRight: 4 },
		borderSkipped: 'start',
	};
}

/**
 * The numbers as a table.
 *
 * @param {Object}  card The card.
 * @param {boolean} open Whether the table is the chart itself.
 * @return {HTMLElement} The table, or its details.
 */
function table(card, open) {
	const { labels, series, keys } = card.data;
	const t = el('table', 'mody-brand__rows mody-board__table');
	const head = el('tr');
	head.append(el('th', '', ''));
	series.forEach((s) => {
		head.append(el('th', '', s.label));
		if (s.previous) {
			head.append(el('th', '', __('The period before', 'mody_cloud')));
		}
	});
	head.querySelectorAll('th').forEach((th) =>
		th.setAttribute('scope', 'col')
	);
	const thead = el('thead');
	thead.append(head);
	const tbody = el('tbody');
	const names =
		card.spec.query.dimension === 'hour_weekday' ? weekdays('long') : null;
	labels.forEach((label, i) => {
		const row = el('tr');
		let name = label;
		if (names) {
			const [w, h] = keys[i].split(':');
			name = `${names[Number(w)]} ${String(h).padStart(2, '0')}:00`;
		}
		const th = el(
			'th',
			'',
			card.spec.query.dimension === 'none' ? card.spec.title : name
		);
		th.setAttribute('scope', 'row');
		row.append(th);
		series.forEach((s) => {
			row.append(el('td', '', format(s.data[i], s.metric)));
			if (s.previous) {
				row.append(el('td', '', format(s.previous[i], s.metric)));
			}
		});
		tbody.append(row);
	});
	t.append(thead, tbody);
	if (open) {
		const scroll = el('div', 'mody-board__table-wrap');
		scroll.append(t);
		return scroll;
	}
	const details = el('details', 'mody-brand__prompt mody-board__details');
	details.append(el('summary', '', __('Show as a table', 'mody_cloud')));
	const scroll = el('div', 'mody-board__table-wrap');
	scroll.append(t);
	details.append(scroll);
	return details;
}

/**
 * A card: its title, its buttons, its chart, its table.
 *
 * @param {Object} card  The card.
 * @param {number} index Its place.
 * @param {number} total How many cards there are.
 * @param {Object} board The board.
 * @return {HTMLElement} The card.
 */
function cardElement(card, index, total, board) {
	const { spec } = card;
	const node = el(
		'section',
		`mody-board__card is-${spec.size} is-type-${spec.type}`
	);
	node.dataset.id = spec.id;
	const head = el('header', 'mody-board__card-head');
	const title = el('h3', 'mody-board__card-title', spec.title);
	head.append(title);
	if (card.days !== board.days) {
		head.append(
			el(
				'span',
				'mody-board__card-period',
				sprintf(
					/* translators: %d: a number of days. */
					__('Last %d days', 'mody_cloud'),
					card.days
				)
			)
		);
	}
	const tools = el('div', 'mody-board__tools');
	const button = (label, icon, action, extra, disabled) => {
		const b = el('button', 'mody-board__tool');
		b.type = 'button';
		b.setAttribute('aria-label', sprintf(label, spec.title));
		b.title = sprintf(label, spec.title);
		b.dataset.action = action;
		b.dataset.id = spec.id;
		if (extra) {
			b.dataset.to = extra;
		}
		b.disabled = disabled;
		const glyph = el('span', `dashicons dashicons-${icon}`);
		glyph.setAttribute('aria-hidden', 'true');
		b.append(glyph);
		return b;
	};
	/* translators: %s: a chart's title. */
	const earlier = __('Move “%s” earlier', 'mody_cloud');
	/* translators: %s: a chart's title. */
	const later = __('Move “%s” later', 'mody_cloud');
	/* translators: %s: a chart's title. */
	const remove = __('Remove “%s”', 'mody_cloud');
	if (withAnalyst) {
		/* translators: %s: a chart's title. */
		const ask = __('Ask Mody about “%s”', 'mody_cloud');
		tools.append(button(ask, 'format-chat', 'ask', '', false));
	}
	tools.append(
		button(earlier, 'arrow-up-alt2', 'move', 'up', index === 0),
		button(later, 'arrow-down-alt2', 'move', 'down', index === total - 1),
		button(remove, 'no-alt', 'remove', '', false)
	);
	head.append(tools);
	node.append(head);

	if (!card.data) {
		node.append(
			el(
				'p',
				'mody-brand__empty',
				card.error ||
					__('This chart could not be counted.', 'mody_cloud')
			)
		);
		return node;
	}
	const bodies = {
		tile: () => [tile(card)],
		list: () => [list(card), table(card, false)],
		journeys: () => [journeys(card)],
		table: () => [table(card, true)],
		heatmap: () => [heatmap(card), table(card, false)],
	};
	const body = bodies[spec.type] || (() => [drawn(card), table(card, false)]);
	node.append(...body());
	return node;
}

/**
 * Draws the board.
 *
 * @param {HTMLElement} root  The board's element.
 * @param {Object}      board The board.
 * @param {string}      focus The id and action of the button to focus, or ''.
 */
function render(root, board, focus = '') {
	charts.splice(0).forEach((chart) => chart.destroy());
	const bar = el('div', 'mody-board__bar');
	bar.append(
		el(
			'p',
			'mody-board__state',
			board.isDefault
				? __('The default board.', 'mody_cloud')
				: __('This site’s board.', 'mody_cloud')
		)
	);
	const actions = el('p', 'mody-board__actions');
	if (board.canUndo) {
		const undo = el(
			'button',
			'button',
			__('Undo the last change', 'mody_cloud')
		);
		undo.type = 'button';
		undo.dataset.action = 'undo';
		actions.append(undo);
	}
	if (!board.isDefault) {
		const reset = el(
			'button',
			'button',
			__('Back to the default board', 'mody_cloud')
		);
		reset.type = 'button';
		reset.dataset.action = 'reset';
		actions.append(reset);
	}
	bar.append(actions);
	const live = el('p', 'screen-reader-text');
	live.setAttribute('aria-live', 'polite');
	live.className = 'screen-reader-text mody-board__live';
	const grid = el('div', 'mody-board__grid');
	board.cards.forEach((card, i) =>
		grid.append(cardElement(card, i, board.cards.length, board))
	);
	if (!board.cards.length) {
		grid.append(
			el(
				'p',
				'mody-brand__empty',
				__(
					'No charts. Go back to the default board to start again.',
					'mody_cloud'
				)
			)
		);
	}
	root.replaceChildren(bar, live, grid);
	root.classList.add('is-ready');
	if (focus === 'undo' || focus === 'state') {
		// After remove and reset, Undo; after undo, the board's state line.
		const target =
			root.querySelector('[data-action="undo"]') ||
			root.querySelector('.mody-board__state');
		target.setAttribute('tabindex', '-1');
		target.focus();
	} else if (focus) {
		const [id, action, to] = focus.split('|');
		const target =
			root.querySelector(
				`button[data-id="${CSS.escape(id)}"][data-action="${action}"][data-to="${to}"]:not(:disabled)`
			) ||
			root.querySelector(
				`.mody-board__card[data-id="${CSS.escape(id)}"] .mody-board__tool:not(:disabled)`
			) ||
			root.querySelector('.mody-board__tool');
		if (target) {
			target.focus();
		}
	}
}

/**
 * Starts the board.
 */
function start() {
	const root = document.getElementById('mody-analytics-board');
	const config = window.modyAnalyticsBoard;
	if (!root || !config) {
		return;
	}
	Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
	Chart.defaults.font.size = 12;
	Chart.defaults.color = INK;
	let board = config.board;
	let busy = false;
	withAnalyst = !!config.analyst;
	render(root, board);

	// The analyst's bubble: a new board from it redraws here, its charts briefly marked.
	let chat = null;
	if (withAnalyst) {
		chat = startChat({
			days: () => board.days,
			onBoard: (next, changed) => {
				board = next;
				render(root, board);
				changed.forEach((id) => {
					const card = root.querySelector(
						`.mody-board__card[data-id="${CSS.escape(id)}"]`
					);
					if (card) {
						card.classList.add('is-changed');
						card.scrollIntoView({
							block: 'nearest',
							behavior: reduced ? 'auto' : 'smooth',
						});
					}
				});
			},
		});
	}

	root.addEventListener('click', (event) => {
		const target = event.target.closest('button[data-action]');
		if (!target || busy) {
			return;
		}
		const { action, id = '', to = '' } = target.dataset;
		if (action === 'ask') {
			const card = board.cards.find((c) => c.spec.id === id);
			chat.ask(
				sprintf(
					/* translators: %s: a chart's title. */
					__('About “%s”:', 'mody_cloud'),
					card ? card.spec.title : id
				)
			);
			return;
		}
		busy = true;
		root.setAttribute('aria-busy', 'true');
		apiFetch({
			path: ROUTE,
			method: 'POST',
			data: { action, id, to, days: board.days },
		})
			.then((next) => {
				board = next;
				render(
					root,
					board,
					{
						move: `${id}|move|${to}`,
						remove: 'undo',
						reset: 'undo',
						undo: 'state',
					}[action]
				);
				const live = root.querySelector('.mody-board__live');
				if (live) {
					live.textContent = {
						move: __('Chart moved.', 'mody_cloud'),
						remove: __('Chart removed.', 'mody_cloud'),
						reset: __('The default board is back.', 'mody_cloud'),
						undo: __('The last change was undone.', 'mody_cloud'),
					}[action];
				}
			})
			.catch((error) => {
				const live = root.querySelector('.mody-board__live');
				if (live) {
					live.textContent =
						error?.message ||
						__('That did not work. Try again.', 'mody_cloud');
				}
			})
			.finally(() => {
				busy = false;
				root.removeAttribute('aria-busy');
			});
	});
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', start);
} else {
	start();
}
