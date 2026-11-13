/* Webmail Exchange – tasks (vanilla JS, no build step) */
(function () {
	'use strict'

	/** Translated text (Nextcloud l10n: l10n/<language>.js); the French text is the key. */
	const _ = (s, vars) => (window.t ? window.t('webmail', s, vars, undefined, { escape: false }) : s)
	const locale = () => ((window.OC && OC.getLanguage && OC.getLanguage()) || 'fr').replace('_', '-')
	const STATUS = [['NotStarted', _('Non commencée')], ['InProgress', _('En cours')], ['WaitingOnOthers', _('En attente de quelqu\'un')], ['Deferred', _('Reportée')], ['Completed', _('Terminée')]]
	const IMPORTANCE = [['Normal', _('Normale')], ['High', _('Haute')], ['Low', _('Basse')]]

	let core = null
	const tk = { accountId: null, filter: 'todo', tasks: [], loading: false, selectedId: null, seq: 0 }

	function api(path, opts) { return core.api(path, opts) }
	const base = () => '/accounts/' + core.accountId

	function pad(n) { return String(n).padStart(2, '0') }
	function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) }

	/** "Aujourd'hui", "Demain", "En retard · 3 nov.", "ven. 20 nov." */
	function dueLabel(due) {
		if (!due) return ''
		const today = ymd(new Date())
		const tomorrow = ymd(new Date(Date.now() + 86400000))
		const d = new Date(due + 'T12:00')
		const label = d.toLocaleDateString(locale(), { weekday: 'short', day: 'numeric', month: 'short', year: d.getFullYear() !== new Date().getFullYear() ? 'numeric' : undefined })
		if (due === today) return _('Aujourd\'hui')
		if (due === tomorrow) return _('Demain')
		return due < today ? _('En retard · {date}', { date: label }) : label
	}

	/** "account|filter" -> tasks: a list seen before (or loaded ahead) shows at once, then is refreshed */
	const listCache = new Map()

	async function fetchTasks(filter) {
		const r = await api(base() + '/tasks', { query: { completed: filter === 'done' ? 1 : 0 } })
		return filter === 'done' ? r.tasks.filter((t) => t.done) : r.tasks
	}

	async function load() {
		const mine = ++tk.seq
		const key = core.accountId + '|' + tk.filter
		const cached = listCache.get(key)
		// shown at once while it is refreshed (not « loading »: the list is there)
		if (cached) { tk.tasks = cached; tk.accountId = core.accountId }
		tk.loading = !cached
		renderList()
		try {
			const tasks = await fetchTasks(tk.filter)
			if (mine !== tk.seq) return
			tk.tasks = tasks
			listCache.set(key, tasks)
			tk.accountId = core.accountId
		} catch (e) {
			if (mine === tk.seq) core.toast(e.message, true)
		} finally {
			if (mine === tk.seq) { tk.loading = false; renderList(); core.renderNav() }
		}
	}

	function renderNav(navEl) {
		const h = core.h
		navEl.appendChild(h('button', { class: 'wm-compose-btn primary', onclick: () => openForm(null) }, '＋  ' + _('Nouvelle tâche')))
		const sel = core.accountSelector()
		if (sel) navEl.appendChild(sel)
		const item = (key, icon, label) => h('li', {}, h('a', {
			href: '#',
			class: 'wm-folder wm-task-filter' + (tk.filter === key ? ' active' : ''),
			dataset: { filter: key },
			onclick: (e) => { e.preventDefault(); tk.filter = key; tk.selectedId = null; core.renderNav(); renderEmpty(); load() },
		}, h('span', { class: 'wm-folder-icon', 'aria-hidden': 'true' }, icon), h('span', { class: 'wm-folder-name' }, label),
		key === 'todo' && tk.filter === 'todo' && tk.tasks.length ? h('span', { class: 'wm-badge' }, String(tk.tasks.length)) : null))
		navEl.appendChild(h('ul', { class: 'wm-folders' }, item('todo', '☐', _('À faire')), item('done', '☑', _('Terminées'))))
		navEl.appendChild(h('div', { style: 'flex:1' }))
		navEl.appendChild(core.navFooter())
	}

	function renderList() {
		if (!core || core.state.module !== 'tasks') return
		const h = core.h
		const el = core.listEl
		core.clear(el)
		const quick = h('input', { type: 'text', class: 'wm-search wm-task-quick', name: 'quickTask', placeholder: _('Ajouter une tâche (Entrée)'), 'aria-label': _('Ajouter une tâche') })
		el.appendChild(h('form', {
			class: 'wm-list-header',
			onsubmit: async (e) => {
				e.preventDefault()
				const subject = quick.value.trim()
				if (!subject) return
				quick.disabled = true
				try {
					await api(base() + '/tasks', { method: 'POST', body: { task: { subject } } })
					quick.value = ''
					core.toast(_('Tâche ajoutée'))
					if (tk.filter !== 'todo') tk.filter = 'todo'
					await load()
				} catch (ex) { core.toast(ex.message, true) } finally { quick.disabled = false; quick.focus() }
			},
		},
		h('button', { type: 'button', class: 'wm-back wm-mobile-only', 'aria-label': _('Menu'), onclick: () => core.setView('nav') }, '☰'),
		tk.filter === 'todo' ? quick : h('div', { class: 'wm-muted wm-task-title' }, _('Tâches terminées'))))
		if (tk.loading && !tk.tasks.length) {
			el.appendChild(h('div', { class: 'wm-center' }, h('span', { class: 'icon-loading' })))
			return
		}
		if (!tk.tasks.length) {
			el.appendChild(h('div', { class: 'wm-empty' }, tk.filter === 'todo' ? _('Rien à faire') + ' 🎉' : _('Aucune tâche terminée')))
			return
		}
		const today = ymd(new Date())
		const ul = h('ul', { class: 'wm-tasks', role: 'list' })
		tk.tasks.forEach((t) => {
			const box = h('input', {
				type: 'checkbox',
				class: 'wm-task-done',
				checked: t.done,
				'aria-label': t.done ? _('Remettre à faire « {subject} »', { subject: t.subject }) : _('Terminer « {subject} »', { subject: t.subject }),
				onclick: (e) => e.stopPropagation(),
				onchange: () => complete(t, box.checked),
			})
			ul.appendChild(h('li', {
				class: 'wm-task' + (t.done ? ' done' : '') + (t.id === tk.selectedId ? ' selected' : '') + (!t.done && t.due && t.due < today ? ' late' : ''),
				dataset: { id: t.id },
				tabindex: '0',
				onclick: () => openTask(t.id),
				onkeydown: (e) => { if (e.key === 'Enter') openTask(t.id) },
			},
			box,
			h('div', { class: 'wm-task-text' },
				h('div', { class: 'wm-task-subject' }, t.importance === 'High' ? h('span', { class: 'wm-important', title: _('Importance haute') }, '❗ ') : null, t.subject || _('(sans titre)')),
				h('div', { class: 'wm-task-meta' },
					t.due ? h('span', { class: 'wm-task-due' }, '📅 ' + dueLabel(t.due)) : null,
					t.status === 'InProgress' || t.status === 'WaitingOnOthers' || t.status === 'Deferred'
						? h('span', { class: 'wm-task-status' }, (STATUS.find(([v]) => v === t.status) || [0, ''])[1]) : null,
					t.recurring ? h('span', { title: _('Tâche périodique') }, '🔁') : null,
					t.reminder ? h('span', { class: 'wm-task-reminder', title: _('Rappel le {date}', { date: new Date(t.reminder).toLocaleString(locale(), { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) }) }, '🔔') : null))))
		})
		el.appendChild(ul)
	}

	/** Done / to do again, at once on screen. */
	async function complete(t, done) {
		const before = t.done
		t.done = done
		const li = core.listEl.querySelector('.wm-task[data-id="' + CSS.escape(t.id) + '"]')
		if (li) li.classList.toggle('done', done)
		try {
			await api(base() + '/tasks/complete', { method: 'POST', body: { taskId: t.id, done } })
			core.toast(done ? _('Tâche terminée') : _('Tâche remise à faire'))
			// it leaves the list it no longer belongs to
			setTimeout(() => { if ((tk.filter === 'todo') === done) load() }, 600)
			if (tk.selectedId === t.id) openTask(t.id)
		} catch (e) {
			t.done = before
			if (li) li.classList.toggle('done', before)
			core.toast(e.message, true)
		}
	}

	function renderEmpty() {
		const h = core.h
		core.clear(core.detailEl)
		core.detailEl.appendChild(h('div', { class: 'wm-empty-detail' }, h('div', { class: 'wm-empty-icon', 'aria-hidden': 'true' }, '☑'), h('p', {}, _('Sélectionnez une tâche'))))
	}

	async function openTask(id) {
		tk.selectedId = id
		core.listEl.querySelectorAll('.wm-task').forEach((li) => li.classList.toggle('selected', li.dataset.id === id))
		core.setView('detail')
		core.clear(core.detailEl)
		core.detailEl.appendChild(core.h('div', { class: 'wm-center' }, core.h('span', { class: 'icon-loading' })))
		try {
			const t = await api(base() + '/task', { query: { taskId: id } })
			if (tk.selectedId === id) openForm(t)
		} catch (e) {
			core.clear(core.detailEl)
			core.detailEl.appendChild(core.h('div', { class: 'wm-empty' }, e.message))
		}
	}

	/** The form of a task (t: from the server, or null for a new one; prefill: the title and notes of a new one). */
	function openForm(t, prefill) {
		const h = core.h
		const el = core.detailEl
		const isNew = !t
		core.setView('detail')
		core.clear(el)
		const subject = h('input', { type: 'text', name: 'subject', required: true, placeholder: _('Titre') })
		subject.value = t ? t.subject : ((prefill && prefill.subject) || '')
		const due = h('input', { type: 'date', name: 'due' })
		due.value = t && t.due ? t.due : ''
		const status = h('select', { name: 'status' }, STATUS.map(([v, l]) => h('option', { value: v, selected: (t ? t.status : 'NotStarted') === v }, l)))
		const importance = h('select', { name: 'importance' }, IMPORTANCE.map(([v, l]) => h('option', { value: v, selected: (t ? t.importance : 'Normal') === v }, l)))
		const notes = h('textarea', { name: 'notes', rows: '8', placeholder: _('Notes') })
		notes.value = t ? (t.notes || '') : ((prefill && prefill.notes) || '')
		// the reminder: a local date and time (the bell of Nextcloud rings then, and Outlook)
		const localInput = (d) => ymd(d) + 'T' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0')
		const reminder = h('input', { type: 'datetime-local', name: 'reminder' })
		reminder.value = t && t.reminder ? localInput(new Date(t.reminder)) : ''
		// « Demain 9:00 », « Le jour de l'échéance 9:00 »
		const quickReminder = (label, at) => h('button', { type: 'button', class: 'wm-small', onclick: () => { const d = at(); if (d) reminder.value = localInput(d) } }, label)
		const nineOn = (dayStr) => { const d = new Date(dayStr + 'T09:00:00'); return isNaN(d) ? null : d }
		const err = h('div', { class: 'wm-form-error', role: 'alert' })
		const save = h('button', { type: 'submit', class: 'primary' }, isNew ? _('Créer') : _('Enregistrer'))
		const field = (label, ...inputs) => h('label', { class: 'wm-field' }, h('span', {}, label), ...inputs)
		const quickDue = (days, label) => h('button', {
			type: 'button',
			class: 'wm-small',
			onclick: () => { due.value = ymd(new Date(Date.now() + days * 86400000)) },
		}, label)
		el.appendChild(h('form', {
			class: 'wm-settings wm-task-form',
			onsubmit: async (e) => {
				e.preventDefault()
				err.textContent = ''
				save.disabled = true
				// (sent with its offset: the server keeps it in UTC)
				const remindAt = reminder.value ? new Date(reminder.value) : null
				const task = { subject: subject.value, due: due.value, status: status.value, importance: importance.value, notes: notes.value,
					reminder: remindAt && !isNaN(remindAt) ? remindAt.toISOString() : '' }
				try {
					if (isNew) {
						const r = await api(base() + '/tasks', { method: 'POST', body: { task } })
						tk.selectedId = r.id
						core.toast(_('Tâche créée'))
					} else {
						await api(base() + '/tasks/update', { method: 'POST', body: { taskId: t.id, task } })
						core.toast(_('Tâche enregistrée'))
					}
					await load()
					save.disabled = false
				} catch (ex) {
					err.textContent = ex.message
					save.disabled = false
				}
			},
		},
		h('div', { class: 'wm-compose-title' },
			h('button', { type: 'button', class: 'wm-back wm-mobile-only', 'aria-label': _('Retour'), onclick: () => core.setView('list') }, '←'),
			h('h2', {}, isNew ? _('Nouvelle tâche') : _('Tâche'))),
		field(_('Titre'), subject),
		field(_('Échéance'), due, quickDue(0, _('Aujourd\'hui')), quickDue(1, _('Demain')), quickDue(7, _('Dans une semaine')),
			h('button', { type: 'button', class: 'wm-small', onclick: () => { due.value = '' } }, _('Aucune'))),
		field(_('Rappel'), reminder,
			quickReminder(_('Demain 9:00'), () => nineOn(ymd(new Date(Date.now() + 86400000)))),
			quickReminder(_('Le jour de l\'échéance'), () => (due.value ? nineOn(due.value) : (core.toast(_('Choisissez d\'abord une échéance'), true), null))),
			h('button', { type: 'button', class: 'wm-small', onclick: () => { reminder.value = '' } }, _('Aucun'))),
		field(_('État'), status),
		field(_('Importance'), importance),
		h('label', { class: 'wm-field wm-field-top' }, h('span', {}, _('Notes')), notes),
		err,
		h('div', { class: 'wm-compose-actions' }, save,
			!isNew ? h('button', {
				type: 'button',
				class: 'wm-task-toggle',
				onclick: () => complete(tk.tasks.find((x) => x.id === t.id) || t, !t.done),
			}, t.done ? '↺ ' + _('Remettre à faire') : '✓ ' + _('Marquer comme terminée')) : null,
			!isNew ? h('button', {
				type: 'button',
				class: 'wm-danger',
				onclick: async () => {
					if (!window.confirm(_('Supprimer la tâche « {subject} » ?', { subject: t.subject }))) return
					try {
						await api(base() + '/tasks/delete', { method: 'POST', body: { taskId: t.id } })
						core.toast(_('Tâche supprimée'))
						tk.selectedId = null
						renderEmpty()
						load()
					} catch (ex) { core.toast(ex.message, true) }
				},
			}, '🗑 ' + _('Supprimer')) : null,
			isNew ? h('button', { type: 'button', onclick: () => { renderEmpty(); core.setView('list') } }, _('Annuler')) : null)))
		subject.focus()
	}

	window.WebmailModules = window.WebmailModules || {}
	window.WebmailModules.tasks = {
		/** Loaded ahead, while the mail is shown and the browser is idle: the tasks to do. */
		async prefetch(c) {
			core = c
			const key = core.accountId + '|todo'
			if (listCache.has(key)) return
			const accountId = core.accountId
			const tasks = await fetchTasks('todo')
			if (accountId === core.accountId) listCache.set(key, tasks)
		},
		activate(c) {
			core = c
			if (tk.accountId !== core.accountId) { tk.tasks = []; tk.selectedId = null }
			core.renderNav()
			core.setView('list')
			renderEmpty()
			load()
		},
		renderNav(navEl, c) {
			core = c
			renderNav(navEl)
		},
		/** A task opened from a link (the reminder in the bell of Nextcloud). */
		openLinked(c, taskId) {
			core = c
			openTask(taskId)
		},
		/** A new task with its title and notes filled in (from a message); the tasks module is already shown. */
		newFromMessage(c, prefill) {
			core = c
			openForm(null, prefill)
		},
	}
})()
