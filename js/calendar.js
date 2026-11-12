/* Webmail Exchange – calendar module (vanilla JS, no build step) */
(function () {
	'use strict'

	const DAY_NAMES = ['lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.']
	const REMINDERS = [[-1, 'Aucun rappel'], [0, 'Au début'], [5, '5 minutes avant'], [15, '15 minutes avant'], [30, '30 minutes avant'],
		[60, '1 heure avant'], [120, '2 heures avant'], [1440, '1 jour avant'], [10080, '1 semaine avant']]
	const FREEBUSY = [['Busy', 'Occupé'], ['Tentative', 'Provisoire'], ['Free', 'Disponible'], ['OOF', 'Absent du bureau'], ['WorkingElsewhere', 'Travail à distance']]
	const RESPONSES = { Accept: 'Accepté', Tentative: 'Provisoire', Decline: 'Refusé', NoResponseReceived: 'Pas de réponse', Unknown: 'Pas de réponse', Organizer: 'Organisateur' }

	const HOUR_PX = 48 // height of one hour in the week view

	const cal = {
		view: null, // month | week | list
		cursor: startOfDay(new Date()), // reference day: its month, its week, or the first day of the list
		weekScroll: null,
		miniMonth: null, // month shown by the small calendar when browsed with its arrows
		search: null, // { q, loading, events }: search results shown instead of the calendar // remembered vertical scroll of the week grid
		calendars: [], // own and shared calendars of the current account
		calendarsFor: null, // account id the list belongs to
		hidden: new Set(), // keys of calendars the user unticked
		events: [],
		loading: false,
		seq: 0,
	}
	let core = null

	/**
	 * Events already loaded, per period and visible calendars: a period seen
	 * before shows at once (then refreshed), the next and previous ones are
	 * loaded in advance. Any change made from here empties it.
	 */
	const eventCache = new Map()
	const EVENT_CACHE_MAX = 24

	function api(path, opts) {
		if (opts && opts.method && opts.method !== 'GET') eventCache.clear()
		return core.api(path, opts)
	}

	// ------------------------------------------------------------------
	// date helpers (local time)
	// ------------------------------------------------------------------

	function startOfMonth(d) { return new Date(d.getFullYear(), d.getMonth(), 1) }
	function startOfDay(d) { return new Date(d.getFullYear(), d.getMonth(), d.getDate()) }
	function addDays(d, n) { const r = new Date(d); r.setDate(r.getDate() + n); return r }
	function sameDay(a, b) { return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate() }
	function pad(n) { return String(n).padStart(2, '0') }
	function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) }
	function hm(d) { return pad(d.getHours()) + ':' + pad(d.getMinutes()) }
	function ucfirst(str) { return str.charAt(0).toUpperCase() + str.slice(1) }
	function timeLabel(d) { return d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) }

	/** Monday of the week containing d */
	function startOfWeek(d) {
		const day = (d.getDay() + 6) % 7
		return startOfDay(addDays(d, -day))
	}

	function visibleRange(cursor = cal.cursor) {
		if (cal.view === 'list') {
			const from = startOfDay(cursor)
			return [from, addDays(from, 31)]
		}
		if (cal.view === 'day') {
			const day = startOfDay(cursor)
			return [day, addDays(day, 1)]
		}
		if (cal.view === 'week') {
			const first = startOfWeek(cursor)
			return [first, addDays(first, 7)]
		}
		const first = startOfWeek(startOfMonth(cursor))
		const lastOfMonth = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0)
		const end = addDays(startOfWeek(lastOfMonth), 7)
		return [first, end]
	}

	/** Event occupies [start, end[ in local time; all-day ends at next midnight */
	function eventDays(ev) {
		const s = new Date(ev.start)
		const e = new Date(ev.end)
		const days = []
		let d = startOfDay(s)
		// an event ending exactly at midnight does not occupy that day
		const last = new Date(e.getTime() - 1)
		while (d <= last || days.length === 0) {
			days.push(d)
			d = addDays(d, 1)
			if (days.length > 400) break
		}
		return days
	}

	function eventsOn(day) {
		return cal.events
			.filter((ev) => eventDays(ev).some((d) => sameDay(d, day)))
			.sort((a, b) => (b.allDay - a.allDay) || (new Date(a.start) - new Date(b.start)))
	}

	// ------------------------------------------------------------------
	// data
	// ------------------------------------------------------------------

	const PALETTE = ['#c0392b', '#27ae60', '#8e44ad', '#d35400', '#16a085', '#2c3e50', '#c71585', '#7f8c00', '#1f6fb2', '#a0522d']

	function hiddenKey() { return 'webmail.calhidden.' + core.accountId }

	async function loadCalendars(force) {
		if (!force && cal.calendarsFor === core.accountId && cal.calendars.length) return
		const accountId = core.accountId
		let list = [{ key: 'calendar', name: 'Calendrier', kind: 'own', canEdit: true, mode: 'full' }]
		try {
			list = (await api('/accounts/' + accountId + '/calendar/calendars')).calendars
		} catch (e) {
			core.toast(e.message, true)
		}
		if (accountId !== core.accountId) return
		// the default calendar keeps the theme colour, the others get a palette colour
		let i = 0
		list.forEach((c) => { c.color = c.key === 'calendar' ? null : PALETTE[i++ % PALETTE.length] })
		cal.calendars = list
		cal.calendarsFor = accountId
		try {
			cal.hidden = new Set(JSON.parse(localStorage.getItem(hiddenKey()) || '[]'))
		} catch (e) { cal.hidden = new Set() }
		core.renderNav()
	}

	function calendarOf(ev) {
		return cal.calendars.find((c) => c.key === ev.calendar) || cal.calendars[0]
	}

	function rangeKey(start, end) {
		const visible = cal.calendars.filter((c) => !cal.hidden.has(c.key)).map((c) => c.key).join(',')
		return [core.accountId, cal.view, start.toISOString(), end.toISOString(), visible].join('|')
	}

	/** Events of all visible calendars between start and end; errors per calendar */
	async function fetchRange(start, end) {
		const visible = cal.calendars.filter((c) => !cal.hidden.has(c.key))
		const results = await Promise.all(visible.map((c) => api('/accounts/' + core.accountId + '/calendar/events', {
			query: { start: start.toISOString(), end: end.toISOString(), tz: core.TZ, calendar: c.key },
		}).then((data) => ({ c, events: data.events }), (e) => ({ c, error: e }))))
		const events = []
		const errors = []
		results.forEach(({ c, events: evs, error }) => {
			if (error) {
				errors.push(c.name + ' : ' + error.message)
				return
			}
			evs.forEach((ev) => {
				ev.calendar = c.key
				ev.color = c.color
				ev.readOnly = !c.canEdit
				ev.shared = c.kind === 'shared'
				events.push(ev)
			})
		})
		return { events, errors }
	}

	function remember(key, events) {
		eventCache.delete(key)
		eventCache.set(key, events)
		while (eventCache.size > EVENT_CACHE_MAX) eventCache.delete(eventCache.keys().next().value)
	}

	/** Load the previous and next periods in the background. */
	function prefetchAround() {
		if (cal.view === 'list') return
		const cursors = cal.view === 'day'
			? [addDays(cal.cursor, 1), addDays(cal.cursor, -1)]
			: cal.view === 'week'
			? [addDays(cal.cursor, 7), addDays(cal.cursor, -7)]
			: [new Date(cal.cursor.getFullYear(), cal.cursor.getMonth() + 1, 1), new Date(cal.cursor.getFullYear(), cal.cursor.getMonth() - 1, 1)]
		const seq = cal.seq
		cursors.reduce((p, cursor) => p.then(async () => {
			if (seq !== cal.seq) return
			const [start, end] = visibleRange(cursor)
			const key = rangeKey(start, end)
			if (eventCache.has(key)) return
			const { events, errors } = await fetchRange(start, end)
			if (!errors.length) remember(key, events)
		}).catch(() => null), Promise.resolve())
	}

	async function load() {
		const [start, end] = visibleRange()
		const mine = ++cal.seq
		cal.loading = true
		render()
		try {
			await loadCalendars(false)
			if (mine !== cal.seq) return
			const key = rangeKey(start, end)
			if (eventCache.has(key)) {
				// seen before: show it now, refresh below
				cal.events = eventCache.get(key)
				render()
			}
			const { events, errors } = await fetchRange(start, end)
			if (mine !== cal.seq) return
			errors.forEach((msg) => core.toast(msg, true))
			cal.events = events
			if (!errors.length) remember(key, events)
		} catch (e) {
			core.toast(e.message, true)
			cal.events = []
		} finally {
			if (mine === cal.seq) {
				cal.loading = false
				render()
				// the small calendar marks the days with events
				if (core.state.module === 'calendar') core.renderNav()
				prefetchAround()
			}
		}
	}

	// ------------------------------------------------------------------
	// rendering
	// ------------------------------------------------------------------

	function renderNav(navEl) {
		const h = core.h
		navEl.appendChild(h('button', { class: 'wm-compose-btn primary', onclick: () => openForm(null, new Date()) }, '＋  Nouvel événement'))
		const sel = core.accountSelector()
		if (sel) navEl.appendChild(sel)
		navEl.appendChild(miniMonth())
		navEl.appendChild(calendarList())
		navEl.appendChild(h('div', { style: 'flex:1' }))
		navEl.appendChild(core.navFooter())
	}

	const MODE_LABEL = { freebusy: 'disponibilités', read: 'lecture', edit: 'modification' }

	function calendarList() {
		const h = core.h
		const row = (c) => {
			const box = h('input', {
				type: 'checkbox',
				checked: !cal.hidden.has(c.key),
				'aria-label': 'Afficher ' + c.name,
				onchange: () => {
					box.checked ? cal.hidden.delete(c.key) : cal.hidden.add(c.key)
					try { localStorage.setItem(hiddenKey(), JSON.stringify([...cal.hidden])) } catch (e) { /* ignore */ }
					load()
				},
			})
			const mode = c.kind !== 'shared' ? null : (c.mode === 'freebusy' ? 'freebusy' : (c.canEdit ? 'edit' : 'read'))
			return h('li', { class: 'wm-cal-row', dataset: { key: c.key } },
				h('label', { class: 'wm-cal-label', title: c.email || c.name },
					box,
					h('span', { class: 'wm-cal-dot', style: c.color ? 'background:' + c.color : '' }),
					h('span', { class: 'wm-cal-name' }, c.name)),
				mode ? h('span', { class: 'wm-cal-mode wm-cal-mode-' + mode }, MODE_LABEL[mode]) : null,
				c.kind !== 'shared' ? h('button', {
					type: 'button',
					class: 'wm-cal-share',
					title: 'Partager cet agenda',
					'aria-label': 'Partager ' + c.name,
					onclick: () => openShare(c),
				}, '🔗') : null,
				c.kind === 'shared' ? h('button', {
					type: 'button',
					class: 'wm-cal-remove',
					title: 'Retirer cet agenda',
					'aria-label': 'Retirer l\'agenda de ' + c.name,
					onclick: () => removeShared(c),
				}, '✕') : null)
		}
		const own = cal.calendars.filter((c) => c.kind !== 'shared')
		const shared = cal.calendars.filter((c) => c.kind === 'shared')
		return h('div', { class: 'wm-cal-list' },
			h('div', { class: 'wm-cal-section' }, 'Mes agendas'),
			h('ul', {}, own.map(row)),
			h('div', { class: 'wm-cal-section' }, 'Agendas partagés'),
			shared.length ? h('ul', {}, shared.map(row)) : h('p', { class: 'wm-muted wm-cal-hint' }, 'Ajoutez l\'agenda d\'un collègue qui vous l\'a partagé dans Outlook.'),
			h('button', { type: 'button', class: 'wm-cal-add', onclick: openAddShared }, '＋ Ajouter un agenda partagé'))
	}

	/**
	 * Share one of my calendars without delegation (Outlook: right-click on
	 * the calendar > Permissions).
	 */
	function openShare(c) {
		const base = '/accounts/' + core.accountId + '/calendar/permissions'
		window.WebmailSharing.open(core, {
			title: 'Partager « ' + c.name + ' »',
			intro: 'Choisissez ce que chacun peut voir ou faire dans cet agenda, sans en faire un délégué. '
				+ 'C\'est le réglage « Autorisations » de l\'agenda dans Outlook. Vos collègues l\'ajoutent ensuite avec « Ajouter un agenda partagé ».',
			levels: [
				['None', 'Aucun accès'],
				['FreeBusyTimeOnly', 'Disponibilités seulement'],
				['FreeBusyTimeAndSubjectAndLocation', 'Disponibilités, objet et lieu'],
				['Reviewer', 'Lecture (tous les détails)'],
				['Author', 'Lecture et ajout d\'événements'],
				['Editor', 'Modification'],
			],
			newLevel: 'Reviewer',
			load: async () => (await api(base, { query: { calendar: c.key } })).permissions,
			save: (permissions) => api(base, { method: 'POST', body: { calendar: c.key, permissions } }),
			savedMessage: 'Partage de « ' + c.name + ' » enregistré',
		})
	}

	function openAddShared() {
		const h = core.h
		const email = h('input', { type: 'text', name: 'sharedEmail', required: true, placeholder: 'prenom.nom@exemple.fr' })
		const err = h('div', { class: 'wm-form-error', role: 'alert' })
		const btn = h('button', { type: 'submit', class: 'primary' }, 'Ajouter')
		const form = h('form', {
			class: 'wm-event-form',
			onsubmit: async (e) => {
				e.preventDefault()
				err.textContent = ''
				btn.disabled = true
				btn.textContent = 'Vérification de l\'accès…'
				try {
					const c = await api('/accounts/' + core.accountId + '/calendar/shared', { method: 'POST', body: { email: email.value } })
					cal.hidden.delete(c.key)
					close()
					core.toast(c.mode === 'freebusy'
						? 'Agenda de ' + c.name + ' ajouté (disponibilités seulement)'
						: 'Agenda de ' + c.name + ' ajouté (' + (c.canEdit ? 'modification' : 'lecture') + ')')
					await loadCalendars(true)
					load()
				} catch (ex) {
					err.textContent = ex.message
					btn.disabled = false
					btn.textContent = 'Ajouter'
				}
			},
		},
		h('p', { class: 'wm-muted' }, 'Indiquez l\'adresse d\'un collègue. Le niveau d\'accès est détecté automatiquement : '
			+ 'modification ou lecture si l\'agenda vous a été partagé dans Outlook, sinon ses seules disponibilités (occupé / libre).'),
		h('label', { class: 'wm-field' }, h('span', {}, 'Adresse'), core.attachAutocomplete(email)),
		err,
		h('div', { class: 'wm-compose-actions' }, btn, h('button', { type: 'button', onclick: () => close() }, 'Annuler')))
		const close = modal('Ajouter un agenda partagé', form)
	}

	async function removeShared(c) {
		if (!window.confirm('Retirer l\'agenda de ' + c.name + ' de la liste ? (rien n\'est supprimé dans Exchange)')) return
		try {
			await api('/accounts/' + core.accountId + '/calendar/shared/delete', { method: 'POST', body: { email: c.email } })
			core.toast('Agenda retiré')
			await loadCalendars(true)
			load()
		} catch (e) { core.toast(e.message, true) }
	}

	/**
	 * Small month to move around: its own ‹ › arrows (the main view does not
	 * change until a day is clicked), a dot under the days that have events.
	 */
	function miniMonth() {
		const h = core.h
		const today = new Date()
		const shown = cal.miniMonth || startOfMonth(cal.cursor)
		const first = startOfWeek(shown)
		const weekStart = startOfWeek(cal.cursor)
		const busy = new Set()
		cal.events.forEach((ev) => eventDays(ev).forEach((d) => busy.add(ymd(d))))
		const grid = h('div', { class: 'wm-mini-grid' }, DAY_NAMES.map((d) => h('span', { class: 'wm-mini-head' }, d[0].toUpperCase())))
		for (let i = 0; i < 42; i++) {
			const d = addDays(first, i)
			grid.appendChild(h('button', {
				type: 'button',
				class: 'wm-mini-day' + (d.getMonth() !== shown.getMonth() ? ' other' : '') + (sameDay(d, today) ? ' today' : '')
					+ (cal.view === 'week' && d >= weekStart && d < addDays(weekStart, 7) ? ' in-week' : '')
					+ (sameDay(d, cal.cursor) ? ' selected' : '')
					+ (busy.has(ymd(d)) ? ' has-events' : ''),
				'aria-label': d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' }) + (busy.has(ymd(d)) ? ', avec des événements' : ''),
				dataset: { date: ymd(d) },
				onclick: () => { cal.cursor = d; cal.miniMonth = null; cal.weekScroll = null; refreshAll() },
				// double-click: this day on its own
				ondblclick: () => { cal.cursor = d; cal.miniMonth = null; setCalView('day') },
			}, String(d.getDate())))
		}
		const step = (n) => { cal.miniMonth = new Date(shown.getFullYear(), shown.getMonth() + n, 1); core.renderNav() }
		return h('div', { class: 'wm-mini' },
			h('div', { class: 'wm-mini-title' },
				h('button', { type: 'button', class: 'wm-mini-nav', 'aria-label': 'Mois précédent', onclick: () => step(-1) }, '‹'),
				h('span', {}, ucfirst(shown.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' }))),
				h('button', { type: 'button', class: 'wm-mini-nav', 'aria-label': 'Mois suivant', onclick: () => step(1) }, '›')),
			grid)
	}

	function render() {
		if (!core || core.state.module !== 'calendar') return
		const h = core.h
		const el = core.detailEl
		// a refresh while typing in the search field must not take the focus away
		const typing = searchInput && document.activeElement === searchInput
		core.clear(el)

		const title = cal.view === 'list'
			? 'À venir'
			: cal.view === 'day'
				? ucfirst(cal.cursor.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }))
				: cal.view === 'week'
					? weekTitle(startOfWeek(cal.cursor))
					: ucfirst(cal.cursor.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' }))
		const move = (n) => {
			cal.cursor = cal.view === 'day'
				? addDays(cal.cursor, n)
				: cal.view === 'week'
					? addDays(cal.cursor, 7 * n)
					: new Date(cal.cursor.getFullYear(), cal.cursor.getMonth() + n, 1)
			cal.miniMonth = null
			refreshAll()
		}
		const unit = { day: ['Jour précédent', 'Jour suivant'], week: ['Semaine précédente', 'Semaine suivante'] }[cal.view] || ['Mois précédent', 'Mois suivant']

		el.appendChild(h('div', { class: 'wm-toolbar wm-cal-toolbar' },
			h('button', { class: 'wm-back wm-mobile-only', 'aria-label': 'Menu', onclick: () => core.setView('nav') }, '☰'),
			h('button', { onclick: () => { cal.cursor = startOfDay(new Date()); cal.weekScroll = null; refreshAll() } }, 'Aujourd\'hui'),
			cal.view !== 'list' ? h('button', { 'aria-label': unit[0], onclick: () => move(-1) }, '‹') : null,
			cal.view !== 'list' ? h('button', { 'aria-label': unit[1], onclick: () => move(1) }, '›') : null,
			h('h2', { class: 'wm-cal-title' }, title),
			searchField(),
			cal.loading ? h('span', { class: 'icon-loading-small' }) : null,
			h('div', { class: 'wm-segmented' },
				h('button', { class: cal.view === 'day' ? 'active' : '', onclick: () => setCalView('day') }, 'Jour'),
				h('button', { class: cal.view === 'month' ? 'active' : '', onclick: () => setCalView('month') }, 'Mois'),
				h('button', { class: cal.view === 'week' ? 'active' : '', onclick: () => setCalView('week') }, 'Semaine'),
				h('button', { class: cal.view === 'list' ? 'active' : '', onclick: () => setCalView('list') }, 'Liste')),
			h('button', { class: 'primary wm-mobile-only', onclick: () => openForm(null, new Date()) }, '＋'),
		))

		if (typing) searchInput.focus()
		if (cal.search) {
			el.appendChild(renderSearch())
			return
		}
		if (cal.view === 'week' || cal.view === 'day') {
			const week = renderWeek()
			el.appendChild(week)
			restoreWeekScroll(week.querySelector('.wm-week-scroll'))
			return
		}
		el.appendChild(cal.view === 'list' ? renderList() : renderMonth())
	}

	// ------------------------------------------------------------------
	// searching events
	// ------------------------------------------------------------------

	// the same field across renders: a refresh must not wipe what is being typed
	let searchInput = null

	function searchField() {
		const h = core.h
		// what is typed stays, whatever is redrawn; only closing the search empties it
		if (searchInput) return searchInput
		const input = searchInput = h('input', {
			type: 'search',
			class: 'wm-cal-search',
			name: 'calSearch',
			placeholder: 'Rechercher un événement',
			'aria-label': 'Rechercher un événement',
			value: cal.search ? cal.search.q : '',
			onkeydown: (e) => {
				if (e.key === 'Enter') { e.preventDefault(); searchEvents(input.value.trim()) }
				if (e.key === 'Escape' && cal.search) { e.preventDefault(); endSearch() }
			},
		})
		return input
	}

	let searchSeq = 0

	function endSearch() {
		cal.search = null
		if (searchInput) searchInput.value = ''
		render()
	}

	/** In every visible calendar, six months back and ahead. */
	async function searchEvents(q) {
		if (!q) { endSearch(); return }
		if (q.length < 2) { core.toast('Tapez au moins 2 lettres', true); return }
		const mine = ++searchSeq
		cal.search = { q, loading: true, events: [] }
		render()
		const visible = cal.calendars.filter((c) => !cal.hidden.has(c.key) && c.mode !== 'freebusy')
		const results = await Promise.all(visible.map((c) => api('/accounts/' + core.accountId + '/calendar/search', {
			query: { q, tz: core.TZ, calendar: c.key },
		}).then((d) => d.events.map((ev) => Object.assign(ev, { calendar: c.key, color: c.color, readOnly: !c.canEdit, shared: c.kind === 'shared' })),
			(e) => { core.toast(c.name + ' : ' + e.message, true); return [] })))
		if (mine !== searchSeq || !cal.search) return
		cal.search = { q, loading: false, events: results.flat().sort((a, b) => new Date(a.start) - new Date(b.start)) }
		render()
	}

	function renderSearch() {
		const h = core.h
		const { q, loading, events } = cal.search
		const wrap = h('div', { class: 'wm-agenda wm-cal-results' },
			h('div', { class: 'wm-search-info' },
				'Événements contenant « ' + q + ' » ' + (loading ? '' : '(' + events.length + ') ') + '— six mois avant et après aujourd\'hui · ',
				h('a', { href: '#', onclick: (e) => { e.preventDefault(); endSearch() } }, 'revenir à l\'agenda')))
		if (loading) {
			wrap.appendChild(h('div', { class: 'wm-center' }, h('span', { class: 'icon-loading' })))
			return wrap
		}
		if (!events.length) {
			wrap.appendChild(h('div', { class: 'wm-empty' }, 'Aucun événement trouvé'))
			return wrap
		}
		let lastDay = null
		const today = startOfDay(new Date())
		events.forEach((ev) => {
			const s = new Date(ev.start)
			const day = startOfDay(s)
			if (!lastDay || !sameDay(day, lastDay)) {
				lastDay = day
				wrap.appendChild(h('h3', { class: 'wm-agenda-day' + (sameDay(day, today) ? ' today' : '') + (day < today ? ' past' : '') },
					ucfirst(day.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }))))
			}
			const item = h('button', { type: 'button', class: 'wm-agenda-item', onclick: () => openEvent(ev), dataset: { id: ev.id } },
				h('span', { class: 'wm-agenda-time' }, ev.allDay ? 'Journée' : timeLabel(s) + ' – ' + timeLabel(new Date(ev.end))),
				h('span', { class: 'wm-agenda-subject' + (ev.isCancelled ? ' cancelled' : '') }, ev.subject || '(sans titre)',
					ev.location ? h('span', { class: 'wm-muted' }, ' · ' + ev.location) : null,
					cal.calendars.length > 1 ? h('span', { class: 'wm-muted' }, ' · ' + (calendarOf(ev) || {}).name) : null))
			if (ev.color) item.style.setProperty('--ev-color', ev.color)
			wrap.appendChild(item)
		})
		return wrap
	}

	function weekTitle(first) {
		const last = addDays(first, 6)
		const sameMonth = first.getMonth() === last.getMonth()
		const from = first.toLocaleDateString('fr-FR', sameMonth ? { day: 'numeric' } : { day: 'numeric', month: 'long' })
		return 'Semaine du ' + from + ' au ' + last.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
	}

	/** Moving in the calendar closes the search results. */
	function closeSearch() {
		if (!cal.search) return
		cal.search = null
		if (searchInput) searchInput.value = ''
	}

	function setCalView(v) {
		closeSearch()
		cal.view = v
		cal.weekScroll = null
		try { localStorage.setItem('webmail.calview', v) } catch (e) { /* ignore */ }
		refreshAll() // the mini calendar highlights the displayed week
	}

	function chip(ev, day) {
		const el = chipBase(ev, day)
		if (ev.color) el.style.setProperty('--ev-color', ev.color)
		if (ev.freeBusyOnly) el.classList.add('freebusy')
		return el
	}

	function chipBase(ev, day) {
		const h = core.h
		const start = new Date(ev.start)
		const continued = !ev.allDay && !sameDay(start, day)
		return h('button', {
			type: 'button',
			class: 'wm-event' + (ev.allDay ? ' allday' : '') + (ev.isCancelled ? ' cancelled' : '')
				+ (ev.response === 'Tentative' || ev.response === 'NoResponseReceived' ? ' tentative' : '') + ' fb-' + (ev.freeBusy || 'Busy'),
			title: ev.subject + (ev.location ? ' — ' + ev.location : ''),
			onclick: (e) => { e.stopPropagation(); openEvent(ev) },
			dataset: { id: ev.id },
		},
		!ev.allDay && !continued ? h('span', { class: 'wm-event-time' }, timeLabel(start) + ' ') : null,
		ev.subject || '(sans titre)')
	}

	function renderMonth() {
		const h = core.h
		const [first] = visibleRange()
		const today = new Date()
		const weeks = Math.round((visibleRange()[1] - first) / (7 * 86400000))
		const grid = h('div', { class: 'wm-month', style: 'grid-template-rows: auto repeat(' + weeks + ', minmax(96px, 1fr))' },
			DAY_NAMES.map((d) => h('div', { class: 'wm-month-head' }, d)))
		for (let i = 0; i < weeks * 7; i++) {
			const day = addDays(first, i)
			const evs = eventsOn(day)
			const max = 3
			const cell = h('div', {
				class: 'wm-month-cell' + (day.getMonth() !== cal.cursor.getMonth() ? ' other' : '') + (sameDay(day, today) ? ' today' : ''),
				dataset: { date: ymd(day) },
				onclick: () => openForm(null, day),
			},
			h('div', { class: 'wm-month-day' }, String(day.getDate())),
			evs.slice(0, max).map((ev) => draggable(chip(ev, day), ev, 'day', day)),
			evs.length > max ? h('button', {
				type: 'button',
				class: 'wm-more',
				onclick: (e) => { e.stopPropagation(); showDay(day) },
			}, '+ ' + (evs.length - max) + ' autre' + (evs.length - max > 1 ? 's' : '')) : null)
			grid.appendChild(cell)
		}
		return grid
	}

	/**
	 * Timed events of `day`, clipped to the day, with side-by-side columns
	 * for overlapping events.
	 * @return {Array<{ev, start: Date, end: Date, col: number, cols: number}>}
	 */
	function layoutDay(day) {
		const dayStart = startOfDay(day)
		const dayEnd = addDays(dayStart, 1)
		const segs = cal.events
			.filter((ev) => !ev.allDay)
			.map((ev) => ({ ev, start: new Date(ev.start), end: new Date(ev.end) }))
			.filter((s) => s.start < dayEnd && s.end > dayStart)
			.map((s) => ({ ev: s.ev, start: s.start < dayStart ? dayStart : s.start, end: s.end > dayEnd ? dayEnd : s.end }))
			.sort((a, b) => (a.start - b.start) || (b.end - a.end))

		// minimum visual duration so that short events stay clickable
		const visualEnd = (s) => Math.max(s.end.getTime(), s.start.getTime() + 20 * 60000)
		const out = []
		let cluster = []
		let clusterEnd = 0
		const flush = () => {
			const columns = [] // end time of the last event in each column
			cluster.forEach((s) => {
				let c = columns.findIndex((end) => end <= s.start.getTime())
				if (c === -1) { c = columns.length; columns.push(0) }
				columns[c] = visualEnd(s)
				s.col = c
			})
			cluster.forEach((s) => { s.cols = columns.length; out.push(s) })
			cluster = []
		}
		segs.forEach((s) => {
			if (cluster.length && s.start.getTime() >= clusterEnd) flush()
			cluster.push(s)
			clusterEnd = Math.max(clusterEnd, visualEnd(s))
		})
		flush()
		return out
	}

	function renderWeek() {
		const h = core.h
		// the day view is the week grid with one column
		const days = cal.view === 'day'
			? [startOfDay(cal.cursor)]
			: Array.from({ length: 7 }, (_, i) => addDays(startOfWeek(cal.cursor), i))
		const now = new Date()

		const head = h('div', { class: 'wm-week-row wm-week-head' },
			h('div', { class: 'wm-week-gutter' }),
			days.map((d) => h('button', {
				type: 'button',
				class: 'wm-week-dayname' + (sameDay(d, now) ? ' today' : ''),
				title: 'Nouvel événement le ' + d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' }),
				onclick: () => openForm(null, d),
			}, h('span', { class: 'wm-week-dow' }, DAY_NAMES[(d.getDay() + 6) % 7]), h('span', { class: 'wm-week-date' }, String(d.getDate())))))

		const allDay = h('div', { class: 'wm-week-row wm-week-allday' },
			h('div', { class: 'wm-week-gutter wm-muted' }, 'Journée'),
			days.map((d) => h('div', { class: 'wm-week-allday-cell', dataset: { date: ymd(d) } },
				eventsOn(d).filter((ev) => ev.allDay).map((ev) => draggable(chip(ev, d), ev, 'day', d)))))

		const hours = h('div', { class: 'wm-week-gutter wm-week-hours' },
			Array.from({ length: 24 }, (_, hr) => h('div', { class: 'wm-week-hour', style: 'height:' + HOUR_PX + 'px' }, hr ? pad(hr) + ':00' : '')))
		const grid = h('div', { class: 'wm-week-row wm-week-grid', style: 'height:' + (24 * HOUR_PX) + 'px' }, hours)

		days.forEach((d) => {
			const col = h('div', {
				class: 'wm-week-col' + (sameDay(d, now) ? ' today' : ''),
				dataset: { date: ymd(d) },
				style: 'background-size: 100% ' + HOUR_PX + 'px',
			})
			// click on an empty slot: new event at that half hour
			col.addEventListener('click', (e) => {
				if (e.target !== col) return
				const minutes = Math.min(23 * 60 + 30, Math.floor(e.offsetY / HOUR_PX * 2) * 30)
				openForm(null, new Date(d.getFullYear(), d.getMonth(), d.getDate(), Math.floor(minutes / 60), minutes % 60), true)
			})
			layoutDay(d).forEach((s) => {
				const top = (s.start.getHours() * 60 + s.start.getMinutes()) * HOUR_PX / 60
				const minutes = (s.end - s.start) / 60000
				const height = Math.max(HOUR_PX * 20 / 60, minutes * HOUR_PX / 60) - 2
				const ev = s.ev
				const b = chip(ev, d)
				b.classList.add('wm-week-event')
				b.style.top = top + 'px'
				b.style.height = height + 'px'
				b.style.left = 'calc(' + (100 * s.col / s.cols) + '% + 1px)'
				b.style.width = 'calc(' + (100 / s.cols) + '% - 3px)'
				core.clear(b)
				const startsToday = sameDay(new Date(ev.start), d)
				const endsToday = sameDay(new Date(new Date(ev.end).getTime() - 1), d)
				b.append(
					h('span', { class: 'wm-week-event-time' },
						(startsToday ? timeLabel(new Date(ev.start)) : '…') + ' – ' + (endsToday ? timeLabel(new Date(ev.end)) : '…')),
					h('span', { class: 'wm-week-event-subject' }, ev.subject || '(sans titre)'))
				if (ev.location && minutes >= 45) b.appendChild(h('span', { class: 'wm-week-event-location' }, ev.location))
				if (canMove(ev)) b.appendChild(h('span', { class: 'wm-resize', title: 'Tirer pour changer l\'heure de fin', 'aria-hidden': 'true' }))
				col.appendChild(draggable(b, ev, 'time', d))
			})
			if (sameDay(d, now)) {
				col.appendChild(h('div', { class: 'wm-now', style: 'top:' + ((now.getHours() * 60 + now.getMinutes()) * HOUR_PX / 60) + 'px', 'aria-hidden': 'true' }))
			}
			grid.appendChild(col)
		})

		const scroll = h('div', { class: 'wm-week-scroll' }, grid)
		scroll.addEventListener('scroll', () => { cal.weekScroll = scroll.scrollTop })
		return h('div', { class: 'wm-week' + (cal.view === 'day' ? ' wm-day-view' : '') }, head, allDay, scroll)
	}

	function restoreWeekScroll(scroll) {
		if (!scroll) return
		if (cal.weekScroll !== null) {
			scroll.scrollTop = cal.weekScroll
			return
		}
		// first display: start of the working day, or the earliest event if sooner
		let hour = 7.5
		cal.events.filter((ev) => !ev.allDay).forEach((ev) => {
			const s = new Date(ev.start)
			if (s >= (cal.view === 'day' ? startOfDay(cal.cursor) : startOfWeek(cal.cursor))) hour = Math.min(hour, s.getHours())
		})
		scroll.scrollTop = Math.max(0, hour - 0.5) * HOUR_PX
	}

	function renderList() {
		const h = core.h
		const [from, to] = visibleRange()
		const wrap = h('div', { class: 'wm-agenda' })
		let any = false
		for (let d = from; d < to; d = addDays(d, 1)) {
			const evs = eventsOn(d)
			if (!evs.length) continue
			any = true
			wrap.appendChild(h('h3', { class: 'wm-agenda-day' + (sameDay(d, new Date()) ? ' today' : '') },
				ucfirst(d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' }))))
			evs.forEach((ev) => {
				const s = new Date(ev.start)
				const e = new Date(ev.end)
				wrap.appendChild(h('button', { type: 'button', class: 'wm-agenda-item', onclick: () => openEvent(ev), dataset: { id: ev.id } },
					h('span', { class: 'wm-agenda-time' }, ev.allDay ? 'Journée' : (sameDay(s, d) ? timeLabel(s) : '…') + ' – ' + (sameDay(e, d) ? timeLabel(e) : '…')),
					h('span', { class: 'wm-agenda-subject' + (ev.isCancelled ? ' cancelled' : '') }, ev.subject || '(sans titre)',
						ev.location ? h('span', { class: 'wm-muted' }, ' · ' + ev.location) : null,
						ev.isMeeting && !ev.isOrganizer && RESPONSES[ev.response] ? h('span', { class: 'wm-muted' }, ' · ' + RESPONSES[ev.response]) : null)))
			})
		}
		if (!any) wrap.appendChild(h('div', { class: 'wm-empty' }, cal.loading ? 'Chargement…' : 'Aucun événement dans les 30 prochains jours'))
		return wrap
	}

	// ------------------------------------------------------------------
	// drag and drop (mouse / pen; on touch screens a tap opens the event)
	// ------------------------------------------------------------------

	const SNAP_MIN = 15

	function canMove(ev) {
		return ev.isOrganizer && !ev.isCancelled && !ev.readOnly && !ev.freeBusyOnly
	}

	function daysBetween(a, b) {
		return Math.round((startOfDay(b) - startOfDay(a)) / 86400000)
	}

	function dateOfCell(cell) {
		const [y, m, d] = cell.dataset.date.split('-').map(Number)
		return new Date(y, m - 1, d)
	}

	/**
	 * mode "time": week grid, the event moves by 15 minutes and across days,
	 *              or is resized from its bottom edge.
	 * mode "day":  month cells / all-day row, the event moves to another day.
	 */
	function draggable(el, ev, mode, day) {
		if (!canMove(ev)) return el
		el.classList.add('wm-movable')
		el.title = (el.title ? el.title + ' — ' : '') + 'glisser pour déplacer'
		el.addEventListener('pointerdown', (e) => {
			if (e.button !== 0 || e.pointerType === 'touch') return
			startDrag(e, el, ev, mode, day, e.target.classList.contains('wm-resize'))
		})
		return el
	}

	function startDrag(down, el, ev, mode, day, resize) {
		const sx = down.clientX
		const sy = down.clientY
		const origStart = new Date(ev.start)
		const origEnd = new Date(ev.end)
		const duration = origEnd - origStart
		const origTop = parseFloat(el.style.top) || 0
		const origHeight = parseFloat(el.style.height) || 0
		let active = false
		let result = null
		let dropCell = null

		const setLabel = (s, e) => {
			const label = el.querySelector('.wm-week-event-time')
			if (label) label.textContent = timeLabel(s) + ' – ' + timeLabel(e)
		}
		const onMove = (m) => {
			const dx = m.clientX - sx
			const dy = m.clientY - sy
			if (!active) {
				if (Math.abs(dx) + Math.abs(dy) < 5) return
				active = true
				el.classList.add('wm-dragging')
				document.body.classList.add('wm-drag-active', resize ? 'wm-drag-resize' : 'wm-drag-move')
			}
			m.preventDefault()
			if (mode === 'time') {
				const delta = Math.round(dy / HOUR_PX * 60 / SNAP_MIN) * SNAP_MIN
				if (resize) {
					const end = new Date(Math.max(origStart.getTime() + SNAP_MIN * 60000, origEnd.getTime() + delta * 60000))
					result = { start: origStart, end }
					el.style.height = Math.max(HOUR_PX * SNAP_MIN / 60, origHeight + (end - origEnd) / 60000 * HOUR_PX / 60) + 'px'
					setLabel(origStart, end)
					return
				}
				const col = [...document.querySelectorAll('.wm-week-col')].find((c) => {
					const r = c.getBoundingClientRect()
					return m.clientX >= r.left && m.clientX < r.right
				})
				const dayDelta = col ? daysBetween(day, dateOfCell(col)) : 0
				const start = new Date(addDays(origStart, dayDelta).getTime() + delta * 60000)
				result = { start, end: new Date(start.getTime() + duration) }
				if (col && el.parentNode !== col) col.appendChild(el)
				el.style.top = Math.max(0, origTop + delta * HOUR_PX / 60) + 'px'
				el.style.left = '1px'
				el.style.width = 'calc(100% - 3px)'
				setLabel(result.start, result.end)
				return
			}
			// day mode: find the day cell under the pointer
			const cell = document.elementsFromPoint(m.clientX, m.clientY)
				.find((n) => n.matches && n.matches('.wm-month-cell, .wm-week-allday-cell'))
			if (dropCell && dropCell !== cell) dropCell.classList.remove('wm-drop-target')
			dropCell = cell || null
			if (!cell) { result = null; return }
			cell.classList.add('wm-drop-target')
			const dayDelta = daysBetween(day, dateOfCell(cell))
			result = { start: addDays(origStart, dayDelta), end: addDays(origEnd, dayDelta) }
			if (el.parentNode !== cell) {
				const anchor = cell.querySelector('.wm-month-day')
				anchor ? anchor.after(el) : cell.prepend(el)
			}
		}
		const finish = (commitIt) => {
			window.removeEventListener('pointermove', onMove)
			window.removeEventListener('pointerup', onUp)
			window.removeEventListener('keydown', onKey, true)
			document.body.classList.remove('wm-drag-active', 'wm-drag-resize', 'wm-drag-move')
			if (dropCell) dropCell.classList.remove('wm-drop-target')
			if (!active) return
			// the click that follows a drag must not open the event or the "new event" form
			const eat = (c) => { c.stopPropagation(); c.preventDefault() }
			window.addEventListener('click', eat, { capture: true, once: true })
			setTimeout(() => window.removeEventListener('click', eat, { capture: true }), 0)
			const changed = result && (result.start.getTime() !== origStart.getTime() || result.end.getTime() !== origEnd.getTime())
			if (commitIt && changed) {
				commitMove(ev, result)
			} else {
				render()
			}
		}
		const onUp = () => finish(true)
		const onKey = (k) => { if (k.key === 'Escape') { k.stopPropagation(); finish(false) } }
		window.addEventListener('pointermove', onMove)
		window.addEventListener('pointerup', onUp)
		window.addEventListener('keydown', onKey, true)
	}

	async function commitMove(ev, r) {
		const notify = ev.isMeeting && ev.isOrganizer
		if (notify && !window.confirm('Déplacer la réunion « ' + (ev.subject || '') + ' » au '
			+ core.formatRange(r.start.toISOString(), r.end.toISOString(), ev.allDay) + ' ? Les participants recevront la mise à jour.')) {
			render()
			return
		}
		const before = { start: ev.start, end: ev.end }
		// optimistic: show the new place right away
		ev.start = r.start.toISOString()
		ev.end = r.end.toISOString()
		render()
		const local = (d) => ymd(d) + 'T' + hm(d)
		try {
			await api('/accounts/' + core.accountId + '/calendar/move', {
				method: 'POST',
				body: {
					eventId: ev.id,
					start: ev.allDay ? ymd(r.start) : local(r.start),
					end: ev.allDay ? ymd(addDays(r.end, -1)) : local(r.end), // all-day: inclusive last day
					allDay: ev.allDay,
					notify,
					tz: core.TZ,
				},
			})
			core.toast((notify ? 'Réunion déplacée, participants prévenus : ' : 'Événement déplacé : ') + core.formatRange(ev.start, ev.end, ev.allDay))
		} catch (e) {
			ev.start = before.start
			ev.end = before.end
			render()
			core.toast(e.message, true)
		}
		load()
	}

	// ------------------------------------------------------------------
	// recurrence rules
	// ------------------------------------------------------------------

	const WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']
	const WEEKDAY_FR = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche']
	const UNITS = { daily: ['jour', 'jours'], weekly: ['semaine', 'semaines'], monthly: ['mois', 'mois'], yearly: ['an', 'ans'] }

	function weekdayOf(d) { return WEEKDAYS[(d.getDay() + 6) % 7] }

	function joinFr(list) {
		return list.length > 1 ? list.slice(0, -1).join(', ') + ' et ' + list[list.length - 1] : (list[0] || '')
	}

	const RANKS = ['1er', '2e', '3e', '4e']

	/** "le 2e mardi", "le dernier vendredi" of the month of `d` (a 5th one is the last) */
	function weekdayRank(d, by) {
		const n = Math.floor((d.getDate() - 1) / 7)
		const day = WEEKDAY_FR[(d.getDay() + 6) % 7]
		return 'le ' + (by === 'last' || n > 3 ? 'dernier' : RANKS[n]) + ' ' + day
	}

	/** "de novembre", "d'avril" */
	function monthOf(d) {
		const m = d.toLocaleDateString('fr-FR', { month: 'long' })
		return (/^[aeiou]/.test(m) ? 'd\'' : 'de ') + m
	}

	function inLastWeek(d) {
		const last = new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate()
		return d.getDate() + 7 > last
	}

	/** "Toutes les 2 semaines le lundi et le jeudi, jusqu'au 31 mars 2027" */
	function describeRule(r, start) {
		if (!r) return 'Ne se répète pas'
		if (r.freq === 'other' || r.freq === 'keep') return 'Répétition personnalisée (définie dans Outlook)'
		const n = r.interval || 1
		const fem = r.freq === 'weekly' // « semaine » est féminin
		let text = n === 1
			? { daily: 'Tous les jours', weekly: 'Toutes les semaines', monthly: 'Tous les mois', yearly: 'Tous les ans' }[r.freq]
			: (fem ? 'Toutes les ' : 'Tous les ') + n + ' ' + UNITS[r.freq][1]
		if (r.freq === 'weekly') {
			const days = (r.days && r.days.length ? r.days : [weekdayOf(start)])
			text += ' ' + joinFr(WEEKDAYS.filter((d) => days.includes(d)).map((d) => 'le ' + WEEKDAY_FR[WEEKDAYS.indexOf(d)]))
		} else if (r.freq === 'monthly') {
			text += r.by === 'weekday' || r.by === 'last' ? ' ' + weekdayRank(start, r.by) : ' le ' + (start.getDate() === 1 ? '1er' : start.getDate())
		} else if (r.freq === 'yearly') {
			text += r.by === 'weekday' || r.by === 'last'
				? ' ' + weekdayRank(start, r.by) + ' ' + monthOf(start)
				: ' le ' + start.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' })
		}
		if (r.until) {
			text += ', jusqu\'au ' + new Date(r.until + 'T12:00').toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
		} else if (r.count) {
			text += ', ' + r.count + ' fois'
		}
		return text
	}

	/**
	 * Recurrence editor. `initial` is a rule from the server (or null);
	 * `getStart()` returns the current start date of the form.
	 * Returns { el, value() } where value() is the rule to send.
	 */
	function recurrenceEditor(initial, getStart, allowKeep) {
		const h = core.h
		const repeat = h('select', { name: 'repeat', 'aria-label': 'Répétition' },
			allowKeep ? h('option', { value: 'keep' }, 'Conserver la répétition actuelle') : null,
			h('option', { value: '' }, 'Ne se répète pas'),
			h('option', { value: 'daily' }, 'Tous les jours'),
			h('option', { value: 'weekly' }, 'Toutes les semaines'),
			h('option', { value: 'monthly' }, 'Tous les mois'),
			h('option', { value: 'yearly' }, 'Tous les ans'))
		const interval = h('input', { type: 'number', name: 'interval', min: '1', max: '99', value: String((initial && initial.interval) || 1), 'aria-label': 'Intervalle' })
		const unit = h('span', {})
		const intervalWrap = h('label', { class: 'wm-inline' }, 'tous les ', interval, ' ', unit)
		const dayBoxes = WEEKDAYS.map((d, i) => {
			const box = h('input', { type: 'checkbox', name: 'day-' + d, value: d })
			return { d, box, el: h('label', { class: 'wm-day-check', title: WEEKDAY_FR[i] }, box, WEEKDAY_FR[i].slice(0, 2)) }
		})
		const daysWrap = h('div', { class: 'wm-days' }, dayBoxes.map((x) => x.el))
		// monthly / yearly: on the date, or on the weekday ("le 2e mardi", "le dernier mardi")
		const by = h('select', { name: 'repeatBy', 'aria-label': 'Quel jour' })
		const fillBy = (start, f) => {
			const keep = by.value || (initial && initial.by) || 'date'
			core.clear(by)
			if (isNaN(start)) return
			const date = f === 'yearly' ? 'le ' + start.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' }) : 'le ' + (start.getDate() === 1 ? '1er' : start.getDate())
			const suffix = f === 'yearly' ? ' ' + monthOf(start) : ''
			const options = [['date', date], ['weekday', weekdayRank(start, 'weekday') + suffix]]
			// the 4th Tuesday can also be the last one
			if (inLastWeek(start) && Math.floor((start.getDate() - 1) / 7) < 4) options.push(['last', weekdayRank(start, 'last') + suffix])
			options.forEach(([v, l]) => by.appendChild(h('option', { value: v }, l)))
			by.value = options.some(([v]) => v === keep) ? keep : (keep === 'last' ? 'weekday' : 'date')
		}
		const ends = h('select', { name: 'ends', 'aria-label': 'Fin de la répétition' },
			h('option', { value: 'never' }, 'sans fin'),
			h('option', { value: 'until' }, 'jusqu\'au'),
			h('option', { value: 'count' }, 'après'))
		const until = h('input', { type: 'date', name: 'until', 'aria-label': 'Jusqu\'au' })
		const count = h('input', { type: 'number', name: 'count', min: '1', max: '999', value: '10', 'aria-label': 'Nombre d\'occurrences' })
		const countWrap = h('span', { class: 'wm-inline' }, count, ' occurrences')
		const summary = h('div', { class: 'wm-muted wm-rule-summary', 'aria-live': 'polite' })

		if (initial && initial.freq !== 'other') {
			repeat.value = initial.freq
			if (initial.until) { ends.value = 'until'; until.value = initial.until }
			else if (initial.count) { ends.value = 'count'; count.value = String(initial.count) }
		} else if (initial && allowKeep) {
			repeat.value = 'keep'
		} else {
			repeat.value = ''
		}
		const initialDays = initial && initial.days && initial.days.length ? initial.days : null
		dayBoxes.forEach((x) => { x.box.checked = initialDays ? initialDays.includes(x.d) : false })

		const value = () => {
			if (repeat.value === 'keep') return { freq: 'keep' }
			if (!repeat.value) return null
			const r = { freq: repeat.value, interval: parseInt(interval.value, 10) || 1 }
			if (r.freq === 'weekly') r.days = dayBoxes.filter((x) => x.box.checked).map((x) => x.d)
			if (r.freq === 'monthly' || r.freq === 'yearly') r.by = by.value || 'date'
			if (ends.value === 'until' && until.value) r.until = until.value
			if (ends.value === 'count') r.count = parseInt(count.value, 10) || 1
			return r
		}
		const sync = () => {
			const f = repeat.value
			const active = f !== '' && f !== 'keep'
			intervalWrap.hidden = !active || f === 'yearly'
			daysWrap.hidden = f !== 'weekly'
			by.hidden = f !== 'monthly' && f !== 'yearly'
			if (!by.hidden) fillBy(getStart(), f)
			ends.hidden = !active
			until.hidden = !active || ends.value !== 'until'
			countWrap.hidden = !active || ends.value !== 'count'
			if (f && UNITS[f]) unit.textContent = (parseInt(interval.value, 10) || 1) > 1 ? UNITS[f][1] : UNITS[f][0]
			// a weekly rule always includes the weekday of the start date
			const start = getStart()
			if (f === 'weekly' && !isNaN(start) && !dayBoxes.some((x) => x.box.checked)) {
				dayBoxes.find((x) => x.d === weekdayOf(start)).box.checked = true
			}
			summary.textContent = f === 'keep' ? describeRule(initial, start) : (active && !isNaN(start) ? describeRule(value(), start) : '')
		}
		;[repeat, interval, ends, until, count, by].forEach((i) => { i.addEventListener('change', sync); i.addEventListener('input', sync) })
		dayBoxes.forEach((x) => x.box.addEventListener('change', sync))
		sync()

		const el = h('div', { class: 'wm-recurrence' },
			h('div', { class: 'wm-inline wm-wrap' }, repeat, intervalWrap, by, ends, until, countWrap),
			daysWrap,
			summary)
		return { el, value, sync }
	}

	// ------------------------------------------------------------------
	// dialogs
	// ------------------------------------------------------------------

	function modal(title, content, onClose) {
		const h = core.h
		const close = () => { overlay.remove(); document.removeEventListener('keydown', onKey); if (onClose) onClose() }
		const onKey = (e) => { if (e.key === 'Escape') close() }
		const dialog = h('div', { class: 'wm-modal', role: 'dialog', 'aria-modal': 'true', 'aria-label': title },
			h('div', { class: 'wm-modal-header' },
				h('h2', {}, title),
				h('button', { class: 'wm-modal-close', 'aria-label': 'Fermer', onclick: close }, '✕')),
			content)
		const overlay = h('div', { class: 'wm-overlay', onmousedown: (e) => { if (e.target === overlay) close() } }, dialog)
		document.addEventListener('keydown', onKey)
		document.body.appendChild(overlay)
		const focusable = dialog.querySelector('input, textarea, select, button.primary')
		if (focusable) focusable.focus()
		return close
	}

	function showDay(day) {
		const h = core.h
		const list = h('div', { class: 'wm-day-list' }, eventsOn(day).map((ev) => {
			const b = chip(ev, day)
			b.addEventListener('click', () => close())
			return b
		}))
		const close = modal(ucfirst(day.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })), list)
	}

	// ------------------------------------------------------------------
	// Nextcloud Talk: a video meeting room for an event
	// ------------------------------------------------------------------

	const TALK_LINK = /https?:\/\/[^\s<>"']+\/call\/[A-Za-z0-9]+/
	const ANY_LINK = /https?:\/\/[^\s<>"']+/

	function talkEnabled() {
		const root = document.getElementById('webmail-app')
		return !!root && root.dataset.talk === '1'
	}

	/** A public Talk conversation (anyone with the link joins, guests included): its link. */
	async function createTalkRoom(name) {
		const r = await fetch(OC.linkToOCS('apps/spreed/api/v4', 2) + 'room?format=json', {
			method: 'POST',
			headers: { 'OCS-APIRequest': 'true', requesttoken: OC.requestToken, 'Content-Type': 'application/json', Accept: 'application/json' },
			body: JSON.stringify({ roomType: 3, roomName: (name || 'Réunion').slice(0, 255) }),
		})
		const data = await r.json().catch(() => null)
		const token = data && data.ocs && data.ocs.data && data.ocs.data.token
		if (!r.ok || !token) throw new Error('Nextcloud Talk n\'a pas pu créer la salle de visio' + (data && data.ocs && data.ocs.meta && data.ocs.meta.message ? ' : ' + data.ocs.meta.message : ''))
		return window.location.origin + OC.generateUrl('/call/' + token)
	}

	/** The link to join a meeting: a Talk room, else a link given as the place (Teams, Zoom…). */
	function meetingLink(ev) {
		const talk = ((ev.location || '') + ' ' + (ev.body || '')).match(TALK_LINK)
		if (talk) return { url: talk[0], talk: true }
		const other = (ev.location || '').match(ANY_LINK)
		return other ? { url: other[0], talk: false } : null
	}

	async function openEvent(summary) {
		const h = core.h
		const calendar = calendarOf(summary)
		const calendarLine = calendar && calendar.key !== 'calendar'
			? h('div', { class: 'wm-muted' }, h('span', { class: 'wm-cal-dot', style: calendar.color ? 'background:' + calendar.color : '' }), ' ' + (calendar.kind === 'shared' ? 'Agenda de ' : 'Agenda ') + calendar.name)
			: null
		if (summary.freeBusyOnly) {
			// only availability is shared: nothing more to fetch
			modal(summary.subject, h('div', { class: 'wm-event-detail' },
				h('div', { class: 'wm-event-when' }, '🕑 ' + ucfirst(core.formatRange(summary.start, summary.end, summary.allDay))),
				summary.location ? h('div', {}, '📍 ' + summary.location) : null,
				calendarLine,
				h('p', { class: 'wm-muted' }, 'Cette personne ne partage que ses disponibilités : le détail de ses rendez-vous n\'est pas accessible.')))
			return
		}
		const body = h('div', { class: 'wm-event-detail' }, h('div', { class: 'wm-center' }, h('span', { class: 'icon-loading' })))
		const close = modal(summary.subject || '(sans titre)', body)
		let ev
		try {
			ev = await api('/accounts/' + core.accountId + '/calendar/event', { query: { eventId: summary.id, tz: core.TZ } })
		} catch (e) {
			core.clear(body)
			body.appendChild(h('div', { class: 'wm-form-error' }, e.message))
			return
		}
		core.clear(body)
		const people = (list) => list.map((a) => h('li', {}, core.personShort(a) || a.email,
			h('span', { class: 'wm-muted' }, ' — ' + (RESPONSES[a.response] || 'Pas de réponse'))))

		body.append(...[
			ev.isCancelled ? h('div', { class: 'wm-warning' }, 'Cet événement a été annulé.') : null,
			h('div', { class: 'wm-event-when' }, '🕑 ' + ucfirst(core.formatRange(ev.start, ev.end, ev.allDay))),
			ev.isRecurring ? h('div', { class: 'wm-muted wm-event-rule' }, '🔁 Événement périodique') : null,
			calendarLine,
			ev.location ? h('div', {}, '📍 ' + ev.location) : null,
			(() => {
				const link = meetingLink(ev)
				return link ? h('div', { class: 'wm-event-join' },
					h('a', { class: 'button primary wm-join-btn', href: link.url, target: '_blank', rel: 'noopener noreferrer' }, link.talk ? '🎥 Rejoindre la visio Nextcloud Talk' : '🎥 Rejoindre la réunion en ligne')) : null
			})(),
			ev.isMeeting && ev.organizer ? h('div', {}, '👤 Organisateur : ' + core.personLabel(ev.organizer)) : null,
			ev.attendees.length ? h('div', {}, h('strong', {}, 'Participants'), h('ul', { class: 'wm-attendees' }, people(ev.attendees))) : null,
			ev.optionalAttendees.length ? h('div', {}, h('strong', {}, 'Facultatifs'), h('ul', { class: 'wm-attendees' }, people(ev.optionalAttendees))) : null,
			ev.body.trim() ? h('div', { class: 'wm-event-body' }, ev.body.trim()) : null,
		].filter(Boolean))

		const actions = h('div', { class: 'wm-compose-actions' })
		const readOnly = !!summary.readOnly
		const shared = !!summary.shared
		const canEdit = ev.isOrganizer && !readOnly
		// in a colleague's calendar shared with edit rights, answer on their behalf
		const principal = shared ? calendar.name : null
		if (ev.isMeeting && !ev.isOrganizer && !ev.isCancelled && (!shared || !readOnly)) {
			const answer = async (response, label) => {
				if (principal && !window.confirm('Répondre « ' + label + ' » au nom de ' + principal + ' ? L\'organisateur recevra la réponse de votre part, au nom de ' + principal + '.')) return
				try {
					await api('/accounts/' + core.accountId + '/calendar/respond', {
						method: 'POST',
						body: { eventId: ev.id, response, calendar: summary.calendar || 'calendar' },
					})
					core.toast(principal ? 'Réponse envoyée au nom de ' + principal + ' : ' + label : 'Réponse envoyée : ' + label)
					close()
					load()
				} catch (e) { core.toast(e.message, true) }
			}
			actions.append(
				h('span', { class: 'wm-muted' }, (principal ? 'Réponse de ' + principal + ' : ' : 'Votre réponse : ') + (RESPONSES[ev.response] || 'aucune')),
				h('button', { class: 'primary', onclick: () => answer('accept', 'accepté') }, '✓ Accepter'),
				h('button', { onclick: () => answer('tentative', 'provisoire') }, '? Provisoire'),
				h('button', { class: 'wm-danger', onclick: () => answer('decline', 'refusé') }, '✗ Refuser'))
		}
		const isOccurrence = ev.type === 'Occurrence' || ev.type === 'Exception'
		if (canEdit) {
			actions.appendChild(h('button', { class: 'primary', onclick: () => { close(); openForm(ev) } }, isOccurrence ? '✏️ Modifier cette occurrence' : '✏️ Modifier'))
		}
		if (readOnly) {
			actions.appendChild(h('span', { class: 'wm-muted' }, '🔒 Agenda partagé en lecture seule'))
		}
		if (isOccurrence) {
			// describe the rule, and allow editing the whole series
			const ruleLine = body.querySelector('.wm-event-rule')
			const editSeries = h('button', { disabled: true, onclick: () => { close(); openForm(editSeries.series, null, false, { series: true }) } }, '🔁 Modifier la série')
			if (canEdit) actions.appendChild(editSeries)
			api('/accounts/' + core.accountId + '/calendar/series', { query: { eventId: ev.id, tz: core.TZ } }).then((series) => {
				editSeries.series = series
				editSeries.disabled = false
				if (ruleLine) ruleLine.textContent = '🔁 ' + describeRule(series.recurrence, new Date(series.start))
			}).catch(() => { editSeries.remove() })
		}
		if (ev.attendees.length || ev.optionalAttendees.length) {
			const emails = ev.attendees.concat(ev.optionalAttendees).map((a) => a.email).filter((e) => e && !e.startsWith('/'))
			if (ev.organizer && ev.organizer.email && !ev.isOrganizer) emails.unshift(ev.organizer.email)
			actions.appendChild(h('button', { onclick: () => { close(); core.openCompose('new', null, { to: emails.join(', '), subject: ev.subject }) } }, '✉️ Écrire aux participants'))
		}
		const del = async (series) => {
			const what = series ? 'toute la série' : 'cet événement'
			const notify = ev.isMeeting && ev.isOrganizer ? ' Les participants recevront une annulation.' : ''
			if (!window.confirm('Supprimer ' + what + ' ?' + notify)) return
			try {
				await api('/accounts/' + core.accountId + '/calendar/delete', { method: 'POST', body: { eventId: ev.id, series } })
				core.toast('Événement supprimé')
				close()
				load()
			} catch (e) { core.toast(e.message, true) }
		}
		if (!readOnly) actions.appendChild(h('button', { class: 'wm-danger', onclick: () => del(false) }, ev.type === 'Occurrence' || ev.type === 'Exception' ? '🗑 Supprimer cette occurrence' : '🗑 Supprimer'))
		if (!readOnly && (ev.type === 'Occurrence' || ev.type === 'Exception')) {
			actions.appendChild(h('button', { class: 'wm-danger', onclick: () => del(true) }, 'Supprimer la série'))
		}
		body.appendChild(actions)
	}

	/**
	 * Create (ev = null, on `day`) or edit an event.
	 */
	/**
	 * opts.series: `ev` is a series master (from /calendar/series); the rule
	 * and all occurrences are edited.
	 */
	function openForm(ev, day, withTime, opts) {
		const h = core.h
		const isNew = !ev
		const isSeries = !!(opts && opts.series)
		let start
		let end
		if (ev) {
			start = new Date(ev.start)
			end = new Date(ev.end)
			if (ev.allDay) end = addDays(end, -1) // inclusive last day in the form
		} else {
			if (withTime && day) {
				start = new Date(day)
			} else {
				const base = startOfDay(day || new Date())
				const now = new Date()
				const hour = sameDay(base, now) ? Math.min(now.getHours() + 1, 23) : 9
				start = new Date(base.getFullYear(), base.getMonth(), base.getDate(), hour, 0)
			}
			end = new Date(start.getTime() + 3600000)
		}

		const subject = h('input', { type: 'text', name: 'subject', required: true, value: ev ? ev.subject : '', placeholder: 'Titre' })
		const writable = cal.calendars.filter((c) => c.canEdit)
		let lastCalendar = 'calendar'
		try { lastCalendar = localStorage.getItem('webmail.lastcal.' + core.accountId) || 'calendar' } catch (e) { /* ignore */ }
		const calendarSelect = isNew && writable.length > 1
			? h('select', { name: 'calendar', 'aria-label': 'Agenda' }, writable.map((c) => h('option', { value: c.key, selected: c.key === lastCalendar },
				c.kind === 'shared' ? 'Agenda de ' + c.name : c.name)))
			: null
		const allDay = h('input', { type: 'checkbox', name: 'allDay', checked: ev ? ev.allDay : false })
		const startDate = h('input', { type: 'date', name: 'startDate', required: true, value: ymd(start) })
		const startTime = h('input', { type: 'time', name: 'startTime', value: hm(start), step: '300' })
		const endDate = h('input', { type: 'date', name: 'endDate', required: true, value: ymd(end) })
		const endTime = h('input', { type: 'time', name: 'endTime', value: hm(end), step: '300' })
		const location = h('input', { type: 'text', name: 'location', value: ev ? ev.location : '', placeholder: 'Salle, adresse ou lien visio' })
		const attendees = h('input', { type: 'text', name: 'attendees', placeholder: 'Inviter des participants (adresses e-mail)',
			value: ev ? ev.attendees.map((a) => a.email).filter((e) => e && !e.startsWith('/')).join(', ') : '' })
		const optional = h('input', { type: 'text', name: 'optionalAttendees', placeholder: 'Facultatif',
			value: ev ? ev.optionalAttendees.map((a) => a.email).filter((e) => e && !e.startsWith('/')).join(', ') : '' })
		const description = h('textarea', { name: 'body', rows: '5', placeholder: 'Description' })
		description.value = ev ? ev.body : ''
		// a video meeting: a Nextcloud Talk room, its link in the place and the description
		const talkBtn = talkEnabled() ? h('button', {
			type: 'button',
			class: 'wm-talk-btn',
			title: 'Créer une salle de visio Nextcloud Talk pour cet événement (les invités y entrent par le lien, sans compte)',
			hidden: TALK_LINK.test(location.value + ' ' + ((ev && ev.body) || '')),
			onclick: async () => {
				talkBtn.disabled = true
				try {
					const url = await createTalkRoom(subject.value.trim())
					if (!location.value.trim()) location.value = url
					description.value = (description.value.trim() ? description.value.trim() + '\n\n' : '') + 'Rejoindre la visio Nextcloud Talk : ' + url
					talkBtn.hidden = true
					core.toast('Salle de visio créée : son lien est dans le lieu et la description')
				} catch (e) {
					core.toast(e.message, true)
				} finally {
					talkBtn.disabled = false
				}
			},
		}, '🎥 Visio Talk') : null
		const reminder = h('select', { name: 'reminder' }, REMINDERS.map(([v, l]) => h('option', { value: String(v), selected: (ev ? ev.reminder : 15) === v }, l)))
		const freeBusy = h('select', { name: 'freeBusy' }, FREEBUSY.map(([v, l]) => h('option', { value: v, selected: (ev ? ev.freeBusy : 'Busy') === v }, l)))
		const customRule = isSeries && ev.recurrence && ev.recurrence.freq === 'other'
		const rule = (isNew || isSeries)
			? recurrenceEditor(isSeries ? ev.recurrence : null, () => new Date(startDate.value + 'T12:00'), customRule)
			: null
		if (rule) startDate.addEventListener('change', rule.sync)
		if (customRule) {
			// the start date is part of a rule this form cannot rewrite: only times can change
			startDate.disabled = true
			endDate.disabled = true
		}
		const syncAllDay = () => { startTime.hidden = allDay.checked; endTime.hidden = allDay.checked }
		allDay.addEventListener('change', () => {
			// 23:00 – 00:00 becomes one whole day, not two (the end date is inclusive for a whole day)
			if (allDay.checked && endDate.value > startDate.value) {
				const s = new Date(startDate.value + 'T' + (startTime.value || '00:00'))
				const e = new Date(endDate.value + 'T' + (endTime.value || '00:00'))
				if (e - s <= 86400000) endDate.value = startDate.value
			}
			syncAllDay()
		})
		syncAllDay()
		// keep the duration when the start moves
		let duration = end - start
		const readStart = () => new Date(startDate.value + 'T' + (startTime.value || '00:00'))
		const readEnd = () => new Date(endDate.value + 'T' + (endTime.value || '00:00'))
		const onStart = () => {
			const s = readStart()
			if (isNaN(s)) return
			const e = new Date(s.getTime() + (allDay.checked ? Math.max(0, duration) : Math.max(duration, 15 * 60000)))
			endDate.value = ymd(e)
			endTime.value = hm(e)
		}
		startDate.addEventListener('change', onStart)
		startTime.addEventListener('change', onStart)
		endDate.addEventListener('change', () => { duration = readEnd() - readStart() })
		endTime.addEventListener('change', () => { duration = readEnd() - readStart() })

		const err = h('div', { class: 'wm-form-error', role: 'alert' })
		const save = h('button', { type: 'submit', class: 'primary' }, isNew ? 'Créer' : 'Enregistrer')
		const field = (label, ...inputs) => h('label', { class: 'wm-field' }, h('span', {}, label), ...inputs)

		const form = h('form', {
			class: 'wm-event-form',
			onsubmit: async (e) => {
				e.preventDefault()
				err.textContent = ''
				const data = {
					subject: subject.value,
					allDay: allDay.checked,
					start: allDay.checked ? startDate.value : startDate.value + 'T' + startTime.value,
					end: allDay.checked ? endDate.value : endDate.value + 'T' + endTime.value,
					location: location.value,
					attendees: attendees.value,
					optionalAttendees: optional.value,
					body: description.value,
					reminder: parseInt(reminder.value, 10),
					freeBusy: freeBusy.value,
				}
				if (rule) data.recurrence = rule.value()
				if (isSeries && !window.confirm('Modifier toutes les occurrences de « ' + (subject.value || ev.subject) + ' » ?'
					+ (ev.attendees.length || ev.optionalAttendees.length ? ' Les participants recevront la mise à jour.' : ''))) return
				save.disabled = true
				try {
					if (isNew) {
						const target = calendarSelect ? calendarSelect.value : 'calendar'
					try { localStorage.setItem('webmail.lastcal.' + core.accountId, target) } catch (e) { /* ignore */ }
					await api('/accounts/' + core.accountId + '/calendar/events', { method: 'POST', body: { event: data, tz: core.TZ, calendar: target } })
					cal.hidden.delete(target)
					} else {
						await api('/accounts/' + core.accountId + '/calendar/update', { method: 'POST', body: { eventId: ev.id, event: data, tz: core.TZ, series: isSeries } })
					}
					const invited = (attendees.value + optional.value).trim() !== ''
					core.toast(isNew ? (invited ? 'Événement créé, invitations envoyées' : 'Événement créé')
						: isSeries ? (invited ? 'Série modifiée, participants prévenus' : 'Série modifiée')
							: (invited ? 'Événement modifié, participants prévenus' : 'Événement modifié'))
					close()
					const s = readStart()
					const [from, to] = visibleRange()
					if (!isNaN(s) && cal.view !== 'list' && (s < from || s >= to || (cal.view === 'month' && s.getMonth() !== cal.cursor.getMonth()))) {
						cal.cursor = startOfDay(s)
						cal.weekScroll = null
						refreshAll()
					} else {
						load()
					}
				} catch (ex) {
					err.textContent = ex.message
					save.disabled = false
				}
			},
		},
		field('Titre', subject),
		calendarSelect ? field('Agenda', calendarSelect) : null,
		h('label', { class: 'wm-inline' }, allDay, ' Journée entière'),
		field('Début', startDate, startTime),
		field('Fin', endDate, endTime),
		field('Lieu', location, talkBtn),
		field('Participants', core.attachAutocomplete(attendees)),
		field('Facultatifs', core.attachAutocomplete(optional)),
		rule ? h('div', { class: 'wm-field wm-field-top' }, h('span', {}, 'Répétition'), rule.el) : (ev.isRecurring ? h('p', { class: 'wm-muted' }, '🔁 Seule cette occurrence sera modifiée.') : null),
		isSeries ? h('p', { class: 'wm-muted' }, customRule
			? '🔁 Les modifications s\'appliquent à toutes les occurrences. La répétition a été définie dans Outlook : seuls les horaires sont modifiables ici, pas les dates.'
			: '🔁 Les modifications s\'appliquent à toutes les occurrences, à partir du début de la série. Les occurrences modifiées une par une peuvent reprendre les valeurs de la série.') : null,
		field('Rappel', reminder),
		field('Afficher comme', freeBusy),
		description,
		err,
		h('div', { class: 'wm-compose-actions' }, save, h('button', { type: 'button', onclick: () => close() }, 'Annuler')))

		const close = modal(isNew ? 'Nouvel événement' : isSeries ? 'Modifier la série' : 'Modifier l\'événement', form)
	}

	// ------------------------------------------------------------------

	window.WebmailModules = window.WebmailModules || {}

	function refreshAll() {
		closeSearch()
		core.renderNav()
		load()
	}

	window.WebmailModules.calendar = {
		activate(c) {
			core = c
			if (!cal.view) {
				let saved = null
				try { saved = localStorage.getItem('webmail.calview') } catch (e) { /* ignore */ }
				cal.view = ['list', 'month', 'week', 'day'].includes(saved) ? saved : (window.matchMedia('(max-width: 800px)').matches ? 'list' : 'month')
			}
			if (cal.calendarsFor !== core.accountId) {
				cal.calendars = []
				cal.events = []
			}
			core.setView('detail')
			core.renderNav()
			load()
		},
		renderNav(navEl, c) {
			core = c
			renderNav(navEl)
		},
		/** A link to an event (unified search, dashboard): show its day, open it. */
		async openLinked(c, eventId) {
			core = c
			try {
				const ev = await api('/accounts/' + core.accountId + '/calendar/event', { query: { eventId, tz: core.TZ } })
				const d = new Date(ev.start)
				if (!isNaN(d)) {
					cal.cursor = startOfDay(d)
					cal.weekScroll = null
					refreshAll()
				}
				openEvent(Object.assign({ calendar: 'calendar' }, ev))
			} catch (e) {
				core.toast(e.message, true)
			}
		},
	}
})()
