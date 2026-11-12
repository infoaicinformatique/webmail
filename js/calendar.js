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
		weekScroll: null, // remembered vertical scroll of the week grid
		events: [],
		loading: false,
		seq: 0,
	}
	let core = null

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

	function visibleRange() {
		if (cal.view === 'list') {
			const from = startOfDay(cal.cursor)
			return [from, addDays(from, 31)]
		}
		if (cal.view === 'week') {
			const first = startOfWeek(cal.cursor)
			return [first, addDays(first, 7)]
		}
		const first = startOfWeek(startOfMonth(cal.cursor))
		const lastOfMonth = new Date(cal.cursor.getFullYear(), cal.cursor.getMonth() + 1, 0)
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

	async function load() {
		const [start, end] = visibleRange()
		const mine = ++cal.seq
		cal.loading = true
		render()
		try {
			const data = await core.api('/accounts/' + core.accountId + '/calendar/events', {
				query: { start: start.toISOString(), end: end.toISOString(), tz: core.TZ },
			})
			if (mine !== cal.seq) return
			cal.events = data.events
		} catch (e) {
			core.toast(e.message, true)
			cal.events = []
		} finally {
			if (mine === cal.seq) {
				cal.loading = false
				render()
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
		navEl.appendChild(h('p', { class: 'wm-muted wm-cal-hint' }, 'Agenda Exchange principal. Les modifications sont visibles immédiatement dans Outlook et sur les téléphones.'))
		navEl.appendChild(h('div', { style: 'flex:1' }))
		navEl.appendChild(core.navFooter())
	}

	function miniMonth() {
		const h = core.h
		const today = new Date()
		const first = startOfWeek(startOfMonth(cal.cursor))
		const weekStart = startOfWeek(cal.cursor)
		const grid = h('div', { class: 'wm-mini-grid' }, DAY_NAMES.map((d) => h('span', { class: 'wm-mini-head' }, d[0].toUpperCase())))
		for (let i = 0; i < 42; i++) {
			const d = addDays(first, i)
			grid.appendChild(h('button', {
				type: 'button',
				class: 'wm-mini-day' + (d.getMonth() !== cal.cursor.getMonth() ? ' other' : '') + (sameDay(d, today) ? ' today' : '')
					+ (cal.view === 'week' && d >= weekStart && d < addDays(weekStart, 7) ? ' in-week' : '')
					+ (sameDay(d, cal.cursor) ? ' selected' : ''),
				'aria-label': d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' }),
				dataset: { date: ymd(d) },
				onclick: () => { cal.cursor = d; cal.weekScroll = null; refreshAll() },
			}, String(d.getDate())))
		}
		return h('div', { class: 'wm-mini' },
			h('div', { class: 'wm-mini-title' }, ucfirst(cal.cursor.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' }))), grid)
	}

	function render() {
		if (!core || core.state.module !== 'calendar') return
		const h = core.h
		const el = core.detailEl
		core.clear(el)

		const title = cal.view === 'list'
			? 'À venir'
			: cal.view === 'week'
				? weekTitle(startOfWeek(cal.cursor))
				: ucfirst(cal.cursor.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' }))
		const move = (n) => {
			cal.cursor = cal.view === 'week'
				? addDays(cal.cursor, 7 * n)
				: new Date(cal.cursor.getFullYear(), cal.cursor.getMonth() + n, 1)
			refreshAll()
		}
		const unit = cal.view === 'week' ? 'Semaine' : 'Mois'

		el.appendChild(h('div', { class: 'wm-toolbar wm-cal-toolbar' },
			h('button', { class: 'wm-back wm-mobile-only', 'aria-label': 'Menu', onclick: () => core.setView('nav') }, '☰'),
			h('button', { onclick: () => { cal.cursor = startOfDay(new Date()); cal.weekScroll = null; refreshAll() } }, 'Aujourd\'hui'),
			cal.view !== 'list' ? h('button', { 'aria-label': unit + ' précédent' + (unit === 'Semaine' ? 'e' : ''), onclick: () => move(-1) }, '‹') : null,
			cal.view !== 'list' ? h('button', { 'aria-label': unit + ' suivant' + (unit === 'Semaine' ? 'e' : ''), onclick: () => move(1) }, '›') : null,
			h('h2', { class: 'wm-cal-title' }, title),
			cal.loading ? h('span', { class: 'icon-loading-small' }) : null,
			h('div', { class: 'wm-segmented' },
				h('button', { class: cal.view === 'month' ? 'active' : '', onclick: () => setCalView('month') }, 'Mois'),
				h('button', { class: cal.view === 'week' ? 'active' : '', onclick: () => setCalView('week') }, 'Semaine'),
				h('button', { class: cal.view === 'list' ? 'active' : '', onclick: () => setCalView('list') }, 'Liste')),
			h('button', { class: 'primary wm-mobile-only', onclick: () => openForm(null, new Date()) }, '＋'),
		))

		if (cal.view === 'week') {
			const week = renderWeek()
			el.appendChild(week)
			restoreWeekScroll(week.querySelector('.wm-week-scroll'))
			return
		}
		el.appendChild(cal.view === 'list' ? renderList() : renderMonth())
	}

	function weekTitle(first) {
		const last = addDays(first, 6)
		const sameMonth = first.getMonth() === last.getMonth()
		const from = first.toLocaleDateString('fr-FR', sameMonth ? { day: 'numeric' } : { day: 'numeric', month: 'long' })
		return 'Semaine du ' + from + ' au ' + last.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
	}

	function setCalView(v) {
		cal.view = v
		cal.weekScroll = null
		try { localStorage.setItem('webmail.calview', v) } catch (e) { /* ignore */ }
		refreshAll() // the mini calendar highlights the displayed week
	}

	function chip(ev, day) {
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
			evs.slice(0, max).map((ev) => chip(ev, day)),
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
		const first = startOfWeek(cal.cursor)
		const days = Array.from({ length: 7 }, (_, i) => addDays(first, i))
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
				eventsOn(d).filter((ev) => ev.allDay).map((ev) => chip(ev, d)))))

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
				col.appendChild(b)
			})
			if (sameDay(d, now)) {
				col.appendChild(h('div', { class: 'wm-now', style: 'top:' + ((now.getHours() * 60 + now.getMinutes()) * HOUR_PX / 60) + 'px', 'aria-hidden': 'true' }))
			}
			grid.appendChild(col)
		})

		const scroll = h('div', { class: 'wm-week-scroll' }, grid)
		scroll.addEventListener('scroll', () => { cal.weekScroll = scroll.scrollTop })
		return h('div', { class: 'wm-week' }, head, allDay, scroll)
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
			if (s >= startOfWeek(cal.cursor)) hour = Math.min(hour, s.getHours())
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
	// recurrence rules
	// ------------------------------------------------------------------

	const WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']
	const WEEKDAY_FR = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche']
	const UNITS = { daily: ['jour', 'jours'], weekly: ['semaine', 'semaines'], monthly: ['mois', 'mois'], yearly: ['an', 'ans'] }

	function weekdayOf(d) { return WEEKDAYS[(d.getDay() + 6) % 7] }

	function joinFr(list) {
		return list.length > 1 ? list.slice(0, -1).join(', ') + ' et ' + list[list.length - 1] : (list[0] || '')
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
			text += ' le ' + (start.getDate() === 1 ? '1er' : start.getDate())
		} else if (r.freq === 'yearly') {
			text += ' le ' + start.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' })
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
			if (ends.value === 'until' && until.value) r.until = until.value
			if (ends.value === 'count') r.count = parseInt(count.value, 10) || 1
			return r
		}
		const sync = () => {
			const f = repeat.value
			const active = f !== '' && f !== 'keep'
			intervalWrap.hidden = !active || f === 'yearly'
			daysWrap.hidden = f !== 'weekly'
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
		;[repeat, interval, ends, until, count].forEach((i) => { i.addEventListener('change', sync); i.addEventListener('input', sync) })
		dayBoxes.forEach((x) => x.box.addEventListener('change', sync))
		sync()

		const el = h('div', { class: 'wm-recurrence' },
			h('div', { class: 'wm-inline wm-wrap' }, repeat, intervalWrap, ends, until, countWrap),
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

	async function openEvent(summary) {
		const h = core.h
		const body = h('div', { class: 'wm-event-detail' }, h('div', { class: 'wm-center' }, h('span', { class: 'icon-loading' })))
		const close = modal(summary.subject || '(sans titre)', body)
		let ev
		try {
			ev = await core.api('/accounts/' + core.accountId + '/calendar/event', { query: { eventId: summary.id, tz: core.TZ } })
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
			ev.location ? h('div', {}, '📍 ' + ev.location) : null,
			ev.isMeeting && ev.organizer ? h('div', {}, '👤 Organisateur : ' + core.personLabel(ev.organizer)) : null,
			ev.attendees.length ? h('div', {}, h('strong', {}, 'Participants'), h('ul', { class: 'wm-attendees' }, people(ev.attendees))) : null,
			ev.optionalAttendees.length ? h('div', {}, h('strong', {}, 'Facultatifs'), h('ul', { class: 'wm-attendees' }, people(ev.optionalAttendees))) : null,
			ev.body.trim() ? h('div', { class: 'wm-event-body' }, ev.body.trim()) : null,
		].filter(Boolean))

		const actions = h('div', { class: 'wm-compose-actions' })
		if (ev.isMeeting && !ev.isOrganizer && !ev.isCancelled) {
			const answer = async (response, label) => {
				try {
					await core.api('/accounts/' + core.accountId + '/calendar/respond', { method: 'POST', body: { eventId: ev.id, response } })
					core.toast('Réponse envoyée : ' + label)
					close()
					load()
				} catch (e) { core.toast(e.message, true) }
			}
			actions.append(
				h('span', { class: 'wm-muted' }, 'Votre réponse : ' + (RESPONSES[ev.response] || 'aucune')),
				h('button', { class: 'primary', onclick: () => answer('accept', 'accepté') }, '✓ Accepter'),
				h('button', { onclick: () => answer('tentative', 'provisoire') }, '? Provisoire'),
				h('button', { class: 'wm-danger', onclick: () => answer('decline', 'refusé') }, '✗ Refuser'))
		}
		const isOccurrence = ev.type === 'Occurrence' || ev.type === 'Exception'
		if (ev.isOrganizer) {
			actions.appendChild(h('button', { class: 'primary', onclick: () => { close(); openForm(ev) } }, isOccurrence ? '✏️ Modifier cette occurrence' : '✏️ Modifier'))
		}
		if (isOccurrence) {
			// describe the rule, and allow editing the whole series
			const ruleLine = body.querySelector('.wm-event-rule')
			const editSeries = h('button', { disabled: true, onclick: () => { close(); openForm(editSeries.series, null, false, { series: true }) } }, '🔁 Modifier la série')
			if (ev.isOrganizer) actions.appendChild(editSeries)
			core.api('/accounts/' + core.accountId + '/calendar/series', { query: { eventId: ev.id, tz: core.TZ } }).then((series) => {
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
				await core.api('/accounts/' + core.accountId + '/calendar/delete', { method: 'POST', body: { eventId: ev.id, series } })
				core.toast('Événement supprimé')
				close()
				load()
			} catch (e) { core.toast(e.message, true) }
		}
		actions.appendChild(h('button', { class: 'wm-danger', onclick: () => del(false) }, ev.type === 'Occurrence' || ev.type === 'Exception' ? '🗑 Supprimer cette occurrence' : '🗑 Supprimer'))
		if (ev.type === 'Occurrence' || ev.type === 'Exception') {
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
		allDay.addEventListener('change', syncAllDay)
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
						await core.api('/accounts/' + core.accountId + '/calendar/events', { method: 'POST', body: { event: data, tz: core.TZ } })
					} else {
						await core.api('/accounts/' + core.accountId + '/calendar/update', { method: 'POST', body: { eventId: ev.id, event: data, tz: core.TZ, series: isSeries } })
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
		h('label', { class: 'wm-inline' }, allDay, ' Journée entière'),
		field('Début', startDate, startTime),
		field('Fin', endDate, endTime),
		field('Lieu', location),
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
		core.renderNav()
		load()
	}

	window.WebmailModules.calendar = {
		activate(c) {
			core = c
			if (!cal.view) {
				let saved = null
				try { saved = localStorage.getItem('webmail.calview') } catch (e) { /* ignore */ }
				cal.view = ['list', 'month', 'week'].includes(saved) ? saved : (window.matchMedia('(max-width: 800px)').matches ? 'list' : 'month')
			}
			core.setView('detail')
			core.renderNav()
			load()
		},
		renderNav(navEl, c) {
			core = c
			renderNav(navEl)
		},
	}
})()
