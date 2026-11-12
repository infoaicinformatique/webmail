/* Webmail Exchange – "share this folder" dialog (calendars and address books), vanilla JS */
(function () {
	'use strict'

	/**
	 * Folder permissions dialog, like Outlook's "Permissions" tab.
	 *
	 * opts: {
	 *   title, intro,
	 *   levels: [[value, label], ...] (offered levels; others are shown as "Autre (défini dans Outlook)" and kept),
	 *   newLevel: default level for a person added,
	 *   load(): Promise<[{user, name, level}]>,
	 *   save(entries [{user, level|'keep'}]): Promise,
	 *   savedMessage
	 * }
	 */
	async function open(core, opts) {
		const h = core.h
		const overlay = h('div', { class: 'wm-overlay' })
		const onKey = (e) => { if (e.key === 'Escape') close() }
		const close = () => { overlay.remove(); document.removeEventListener('keydown', onKey) }
		overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) close() })
		document.addEventListener('keydown', onKey)
		const body = h('div', { class: 'wm-event-form wm-share' }, h('div', { class: 'wm-center' }, h('span', { class: 'icon-loading' })))
		overlay.appendChild(h('div', { class: 'wm-modal', role: 'dialog', 'aria-modal': 'true', 'aria-label': opts.title },
			h('div', { class: 'wm-modal-header' }, h('h2', {}, opts.title),
				h('button', { class: 'wm-modal-close', 'aria-label': 'Fermer', onclick: close }, '✕')),
			body))
		document.body.appendChild(overlay)

		let perms
		try {
			perms = await opts.load()
		} catch (e) {
			core.clear(body)
			body.appendChild(h('div', { class: 'wm-form-error' }, e.message))
			return
		}
		const offered = (level) => opts.levels.some(([v]) => v === level)
		// rows edited locally, saved together; "Anonymous" is kept by the server
		const rows = perms.filter((p) => p.user !== 'Anonymous').map((p) => ({ ...p, original: p.level }))
		if (!rows.some((p) => p.user === 'Default')) rows.unshift({ user: 'Default', name: '', level: 'None', original: 'None' })

		const levelSelect = (row) => {
			const sel = h('select', { name: 'level', 'aria-label': 'Niveau' },
				offered(row.level) ? null : h('option', { value: 'keep', selected: true }, 'Autre (défini dans Outlook)'),
				opts.levels.map(([v, l]) => h('option', { value: v, selected: v === row.level }, l)))
			sel.addEventListener('change', () => { row.level = sel.value === 'keep' ? row.original : sel.value })
			return sel
		}
		const list = h('div', { class: 'wm-share-list' })
		const renderRows = () => {
			core.clear(list)
			rows.forEach((row, i) => {
				const isDefault = row.user === 'Default'
				list.appendChild(h('div', { class: 'wm-share-row', dataset: { user: row.user } },
					h('div', { class: 'wm-share-who' },
						isDefault ? h('strong', {}, 'Tout le monde dans l\'organisation') : h('strong', {}, row.name || row.user),
						isDefault ? h('span', { class: 'wm-muted' }, ' (par défaut)') : (row.name ? h('span', { class: 'wm-muted' }, ' ' + row.user) : null)),
					levelSelect(row),
					isDefault ? h('span', { class: 'wm-share-spacer' }) : h('button', {
						type: 'button',
						class: 'wm-cal-remove',
						'aria-label': 'Retirer ' + (row.name || row.user),
						title: 'Ne plus partager avec cette personne',
						onclick: () => { rows.splice(i, 1); renderRows() },
					}, '✕')))
			})
		}
		renderRows()

		const email = h('input', { type: 'text', name: 'shareEmail', placeholder: 'Ajouter une personne (adresse e-mail)' })
		const newLevel = h('select', { name: 'newLevel', 'aria-label': 'Niveau pour la nouvelle personne' },
			opts.levels.filter(([v]) => v !== 'None').map(([v, l]) => h('option', { value: v, selected: v === opts.newLevel }, l)))
		const err = h('div', { class: 'wm-form-error', role: 'alert' })
		const addPerson = () => {
			err.textContent = ''
			const value = email.value.trim().replace(/,\s*$/, '')
			const m = value.match(/<([^>]+)>/)
			const address = (m ? m[1] : value).trim().toLowerCase()
			if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(address)) { err.textContent = 'Adresse e-mail invalide'; return }
			if (rows.some((r) => r.user.toLowerCase() === address)) { err.textContent = 'Cette personne est déjà dans la liste'; return }
			rows.push({ user: address, name: m ? value.replace(/<[^>]+>/, '').trim() : '', level: newLevel.value, original: null })
			email.value = ''
			renderRows()
		}

		const save = h('button', { type: 'submit', class: 'primary' }, 'Enregistrer')
		const form = h('form', {
			class: 'wm-event-form',
			onsubmit: async (e) => {
				e.preventDefault()
				if (email.value.trim()) addPerson()
				save.disabled = true
				try {
					await opts.save(rows.map((r) => ({
						user: r.user,
						// levels set in Outlook that this form does not offer are left as they are
						level: r.original !== null && r.level === r.original && !offered(r.level) ? 'keep' : r.level,
					})))
					core.toast(opts.savedMessage)
					close()
				} catch (ex) {
					err.textContent = ex.message
					save.disabled = false
				}
			},
		},
		h('p', { class: 'wm-muted' }, opts.intro),
		list,
		h('div', { class: 'wm-share-add' }, h('div', { class: 'wm-ac-wrap' }, core.attachAutocomplete(email)), newLevel,
			h('button', { type: 'button', onclick: addPerson }, 'Ajouter')),
		err,
		h('div', { class: 'wm-compose-actions' }, save, h('button', { type: 'button', onclick: close }, 'Annuler')))
		// after the autocompletion handler: Enter picks a suggestion first
		email.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.defaultPrevented) { e.preventDefault(); addPerson() } })
		core.clear(body)
		body.appendChild(form)
		email.focus()
	}

	window.WebmailSharing = { open }
})()
