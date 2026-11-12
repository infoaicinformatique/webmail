/* Webmail Exchange – calendar module (vanilla JS, no build step) */
(function () {
	'use strict'

	const DAY_NAMES = ['lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.']
	const REMINDERS = [[-1, 'Aucun rappel'], [0, 'Au début'], [5, '5 minutes avant'], [15, '15 minutes avant'], [30, '30 minutes avant'],
		[60, '1 heure avant'], [120, '2 heures avant'], [1440, '1 jour avant'], [10080, '1 semaine avant']]
	const FREEBUSY = [['Busy', 'Occupé'], ['Tentative', 'Provisoire'], ['Free', 'Disponible'], ['OOF', 'Absent du bureau'], ['WorkingElsewhere', 'Travail à distance']]
	const RESPONSES = { Accept: 'Accepté', Tentative: 'Provisoire', Decline: 'Refusé', NoResponseReceived: 'Pas de réponse', Unknown: 'Pas de réponse', Organizer: 'Organisateur' }

	const cal = {
		view: null, // month | list
		cursor: startOfMonth(new Date()),
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
			const from = startOfDay(cal.cursor.getTime() === startOfMonth(new Date()).getTime() ? new Date() : cal.cursor)
			return [from, addDays(from, 31)]
		}
		const first = startOfWeek(cal.cursor)
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
		const first = startOfWeek(cal.cursor)
		const grid = h('div', { class: 'wm-mini-grid' }, DAY_NAMES.map((d) => h('span', { class: 'wm-mini-head' }, d[0].toUpperCase())))
		for (let i = 0; i < 42; i++) {
			const d = addDays(first, i)
			grid.appendChild(h('button', {
				type: 'button',
				class: 'wm-mini-day' + (d.getMonth() !== cal.cursor.getMonth() ? ' other' : '') + (sameDay(d, today) ? ' today' : ''),
				'aria-label': d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' }),
				onclick: () => openForm(null, d),
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
			: ucfirst(cal.cursor.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' }))
		const move = (n) => { cal.cursor = new Date(cal.cursor.getFullYear(), cal.cursor.getMonth() + n, 1); refreshAll() }

		el.appendChild(h('div', { class: 'wm-toolbar wm-cal-toolbar' },
			h('button', { class: 'wm-back wm-mobile-only', 'aria-label': 'Menu', onclick: () => core.setView('nav') }, '☰'),
			h('button', { onclick: () => { cal.cursor = startOfMonth(new Date()); refreshAll() } }, 'Aujourd\'hui'),
			cal.view === 'month' ? h('button', { 'aria-label': 'Mois précédent', onclick: () => move(-1) }, '‹') : null,
			cal.view === 'month' ? h('button', { 'aria-label': 'Mois suivant', onclick: () => move(1) }, '›') : null,
			h('h2', { class: 'wm-cal-title' }, title),
			cal.loading ? h('span', { class: 'icon-loading-small' }) : null,
			h('div', { class: 'wm-segmented' },
				h('button', { class: cal.view === 'month' ? 'active' : '', onclick: () => setCalView('month') }, 'Mois'),
				h('button', { class: cal.view === 'list' ? 'active' : '', onclick: () => setCalView('list') }, 'Liste')),
			h('button', { class: 'primary wm-mobile-only', onclick: () => openForm(null, new Date()) }, '＋'),
		))

		el.appendChild(cal.view === 'list' ? renderList() : renderMonth())
	}

	function setCalView(v) {
		cal.view = v
		try { localStorage.setItem('webmail.calview', v) } catch (e) { /* ignore */ }
		load()
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
			ev.isRecurring ? h('div', { class: 'wm-muted' }, '🔁 Événement périodique') : null,
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
		if (ev.isOrganizer) {
			actions.appendChild(h('button', { class: 'primary', onclick: () => { close(); openForm(ev) } }, '✏️ Modifier'))
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
	function openForm(ev, day) {
		const h = core.h
		const isNew = !ev
		let start
		let end
		if (ev) {
			start = new Date(ev.start)
			end = new Date(ev.end)
			if (ev.allDay) end = addDays(end, -1) // inclusive last day in the form
		} else {
			const base = startOfDay(day || new Date())
			const now = new Date()
			const hour = sameDay(base, now) ? Math.min(now.getHours() + 1, 23) : 9
			start = new Date(base.getFullYear(), base.getMonth(), base.getDate(), hour, 0)
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
		const repeat = h('select', { name: 'repeat' },
			h('option', { value: '' }, 'Ne se répète pas'),
			h('option', { value: 'daily' }, 'Tous les jours'),
			h('option', { value: 'weekly' }, 'Toutes les semaines'),
			h('option', { value: 'monthly' }, 'Tous les mois'),
			h('option', { value: 'yearly' }, 'Tous les ans'))
		const until = h('input', { type: 'date', name: 'until', 'aria-label': 'Jusqu\'au' })
		const untilWrap = h('label', { class: 'wm-inline', hidden: true }, 'jusqu\'au ', until, h('span', { class: 'wm-muted' }, ' (facultatif)'))
		repeat.addEventListener('change', () => { untilWrap.hidden = !repeat.value })
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
				if (isNew && repeat.value) data.recurrence = { freq: repeat.value, until: until.value || null }
				save.disabled = true
				try {
					if (isNew) {
						await core.api('/accounts/' + core.accountId + '/calendar/events', { method: 'POST', body: { event: data, tz: core.TZ } })
					} else {
						await core.api('/accounts/' + core.accountId + '/calendar/update', { method: 'POST', body: { eventId: ev.id, event: data, tz: core.TZ } })
					}
					const invited = (attendees.value + optional.value).trim() !== ''
					core.toast(isNew ? (invited ? 'Événement créé, invitations envoyées' : 'Événement créé') : (invited ? 'Événement modifié, participants prévenus' : 'Événement modifié'))
					close()
					const s = readStart()
					if (!isNaN(s) && cal.view === 'month' && (s.getMonth() !== cal.cursor.getMonth() || s.getFullYear() !== cal.cursor.getFullYear())) {
						cal.cursor = startOfMonth(s)
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
		isNew ? field('Répétition', repeat, untilWrap) : (ev.isRecurring ? h('p', { class: 'wm-muted' }, '🔁 Seule cette occurrence sera modifiée.') : null),
		field('Rappel', reminder),
		field('Afficher comme', freeBusy),
		description,
		err,
		h('div', { class: 'wm-compose-actions' }, save, h('button', { type: 'button', onclick: () => close() }, 'Annuler')))

		const close = modal(isNew ? 'Nouvel événement' : 'Modifier l\'événement', form)
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
				cal.view = saved === 'list' || saved === 'month' ? saved : (window.matchMedia('(max-width: 800px)').matches ? 'list' : 'month')
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
