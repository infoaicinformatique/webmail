/* Webmail Exchange – tasks (vanilla JS, no build step) */
(function () {
	'use strict'

	const STATUS = [['NotStarted', 'Non commencée'], ['InProgress', 'En cours'], ['WaitingOnOthers', 'En attente de quelqu\'un'], ['Deferred', 'Reportée'], ['Completed', 'Terminée']]
	const IMPORTANCE = [['Normal', 'Normale'], ['High', 'Haute'], ['Low', 'Basse']]

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
		const label = d.toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short', year: d.getFullYear() !== new Date().getFullYear() ? 'numeric' : undefined })
		if (due === today) return 'Aujourd\'hui'
		if (due === tomorrow) return 'Demain'
		return due < today ? 'En retard · ' + label : label
	}

	async function load() {
		const mine = ++tk.seq
		tk.loading = true
		renderList()
		try {
			const r = await api(base() + '/tasks', { query: { completed: tk.filter === 'done' ? 1 : 0 } })
			if (mine !== tk.seq) return
			tk.tasks = tk.filter === 'done' ? r.tasks.filter((t) => t.done) : r.tasks
			tk.accountId = core.accountId
		} catch (e) {
			if (mine === tk.seq) core.toast(e.message, true)
		} finally {
			if (mine === tk.seq) { tk.loading = false; renderList(); core.renderNav() }
		}
	}

	function renderNav(navEl) {
		const h = core.h
		navEl.appendChild(h('button', { class: 'wm-compose-btn primary', onclick: () => openForm(null) }, '＋  Nouvelle tâche'))
		const sel = core.accountSelector()
		if (sel) navEl.appendChild(sel)
		const item = (key, icon, label) => h('li', {}, h('a', {
			href: '#',
			class: 'wm-folder wm-task-filter' + (tk.filter === key ? ' active' : ''),
			dataset: { filter: key },
			onclick: (e) => { e.preventDefault(); tk.filter = key; tk.selectedId = null; core.renderNav(); renderEmpty(); load() },
		}, h('span', { class: 'wm-folder-icon', 'aria-hidden': 'true' }, icon), h('span', { class: 'wm-folder-name' }, label),
		key === 'todo' && tk.filter === 'todo' && tk.tasks.length ? h('span', { class: 'wm-badge' }, String(tk.tasks.length)) : null))
		navEl.appendChild(h('ul', { class: 'wm-folders' }, item('todo', '☐', 'À faire'), item('done', '☑', 'Terminées')))
		navEl.appendChild(h('div', { style: 'flex:1' }))
		navEl.appendChild(core.navFooter())
	}

	function renderList() {
		if (!core || core.state.module !== 'tasks') return
		const h = core.h
		const el = core.listEl
		core.clear(el)
		const quick = h('input', { type: 'text', class: 'wm-search wm-task-quick', name: 'quickTask', placeholder: 'Ajouter une tâche (Entrée)', 'aria-label': 'Ajouter une tâche' })
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
					core.toast('Tâche ajoutée')
					if (tk.filter !== 'todo') tk.filter = 'todo'
					await load()
				} catch (ex) { core.toast(ex.message, true) } finally { quick.disabled = false; quick.focus() }
			},
		},
		h('button', { type: 'button', class: 'wm-back wm-mobile-only', 'aria-label': 'Menu', onclick: () => core.setView('nav') }, '☰'),
		tk.filter === 'todo' ? quick : h('div', { class: 'wm-muted wm-task-title' }, 'Tâches terminées')))
		if (tk.loading && !tk.tasks.length) {
			el.appendChild(h('div', { class: 'wm-center' }, h('span', { class: 'icon-loading' })))
			return
		}
		if (!tk.tasks.length) {
			el.appendChild(h('div', { class: 'wm-empty' }, tk.filter === 'todo' ? 'Rien à faire 🎉' : 'Aucune tâche terminée'))
			return
		}
		const today = ymd(new Date())
		const ul = h('ul', { class: 'wm-tasks', role: 'list' })
		tk.tasks.forEach((t) => {
			const box = h('input', {
				type: 'checkbox',
				class: 'wm-task-done',
				checked: t.done,
				'aria-label': (t.done ? 'Remettre à faire « ' : 'Terminer « ') + t.subject + ' »',
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
				h('div', { class: 'wm-task-subject' }, t.importance === 'High' ? h('span', { class: 'wm-important', title: 'Importance haute' }, '❗ ') : null, t.subject || '(sans titre)'),
				h('div', { class: 'wm-task-meta' },
					t.due ? h('span', { class: 'wm-task-due' }, '📅 ' + dueLabel(t.due)) : null,
					t.status === 'InProgress' || t.status === 'WaitingOnOthers' || t.status === 'Deferred'
						? h('span', { class: 'wm-task-status' }, (STATUS.find(([v]) => v === t.status) || [0, ''])[1]) : null,
					t.recurring ? h('span', { title: 'Tâche périodique' }, '🔁') : null))))
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
			core.toast(done ? 'Tâche terminée' : 'Tâche remise à faire')
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
		core.detailEl.appendChild(h('div', { class: 'wm-empty-detail' }, h('div', { class: 'wm-empty-icon', 'aria-hidden': 'true' }, '☑'), h('p', {}, 'Sélectionnez une tâche')))
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

	/** The form of a task (t: from the server, or null for a new one). */
	function openForm(t) {
		const h = core.h
		const el = core.detailEl
		const isNew = !t
		core.setView('detail')
		core.clear(el)
		const subject = h('input', { type: 'text', name: 'subject', required: true, placeholder: 'Titre' })
		subject.value = t ? t.subject : ''
		const due = h('input', { type: 'date', name: 'due' })
		due.value = t && t.due ? t.due : ''
		const status = h('select', { name: 'status' }, STATUS.map(([v, l]) => h('option', { value: v, selected: (t ? t.status : 'NotStarted') === v }, l)))
		const importance = h('select', { name: 'importance' }, IMPORTANCE.map(([v, l]) => h('option', { value: v, selected: (t ? t.importance : 'Normal') === v }, l)))
		const notes = h('textarea', { name: 'notes', rows: '8', placeholder: 'Notes' })
		notes.value = t && t.notes ? t.notes : ''
		const err = h('div', { class: 'wm-form-error', role: 'alert' })
		const save = h('button', { type: 'submit', class: 'primary' }, isNew ? 'Créer' : 'Enregistrer')
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
				const task = { subject: subject.value, due: due.value, status: status.value, importance: importance.value, notes: notes.value }
				try {
					if (isNew) {
						const r = await api(base() + '/tasks', { method: 'POST', body: { task } })
						tk.selectedId = r.id
						core.toast('Tâche créée')
					} else {
						await api(base() + '/tasks/update', { method: 'POST', body: { taskId: t.id, task } })
						core.toast('Tâche enregistrée')
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
			h('button', { type: 'button', class: 'wm-back wm-mobile-only', 'aria-label': 'Retour', onclick: () => core.setView('list') }, '←'),
			h('h2', {}, isNew ? 'Nouvelle tâche' : 'Tâche')),
		field('Titre', subject),
		field('Échéance', due, quickDue(0, 'Aujourd\'hui'), quickDue(1, 'Demain'), quickDue(7, 'Dans une semaine'),
			h('button', { type: 'button', class: 'wm-small', onclick: () => { due.value = '' } }, 'Aucune')),
		field('État', status),
		field('Importance', importance),
		h('label', { class: 'wm-field wm-field-top' }, h('span', {}, 'Notes'), notes),
		err,
		h('div', { class: 'wm-compose-actions' }, save,
			!isNew ? h('button', {
				type: 'button',
				class: 'wm-task-toggle',
				onclick: () => complete(tk.tasks.find((x) => x.id === t.id) || t, !t.done),
			}, t.done ? '↺ Remettre à faire' : '✓ Marquer comme terminée') : null,
			!isNew ? h('button', {
				type: 'button',
				class: 'wm-danger',
				onclick: async () => {
					if (!window.confirm('Supprimer la tâche « ' + t.subject + ' » ?')) return
					try {
						await api(base() + '/tasks/delete', { method: 'POST', body: { taskId: t.id } })
						core.toast('Tâche supprimée')
						tk.selectedId = null
						renderEmpty()
						load()
					} catch (ex) { core.toast(ex.message, true) }
				},
			}, '🗑 Supprimer') : null,
			isNew ? h('button', { type: 'button', onclick: () => { renderEmpty(); core.setView('list') } }, 'Annuler') : null)))
		subject.focus()
	}

	window.WebmailModules = window.WebmailModules || {}
	window.WebmailModules.tasks = {
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
	}
})()
