/* Webmail Exchange – contacts module (vanilla JS, no build step) */
(function () {
	'use strict'

	const PAGE = 100
	const ct = {
		items: [],
		total: 0,
		hasMore: false,
		query: '',
		selectedId: null,
		loading: false,
		seq: 0,
	}
	let core = null

	function initials(name) {
		const parts = (name || '?').replace(/[^\p{L}\p{N} ]/gu, ' ').trim().split(/\s+/)
		return ((parts[0] || '?')[0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase()
	}

	function avatar(name) {
		// stable pastel colour from the name
		let hash = 0
		for (const ch of name || '') hash = (hash * 31 + ch.charCodeAt(0)) | 0
		return core.h('span', { class: 'wm-avatar', style: 'background:hsl(' + (Math.abs(hash) % 360) + ',55%,45%)', 'aria-hidden': 'true' }, initials(name))
	}

	async function load(reset) {
		const mine = ++ct.seq
		ct.loading = true
		if (reset) { ct.items = []; ct.total = 0; ct.hasMore = false }
		renderList()
		try {
			const data = await core.api('/accounts/' + core.accountId + '/contacts', {
				query: { offset: reset ? 0 : ct.items.length, limit: PAGE, q: ct.query },
			})
			if (mine !== ct.seq) return
			ct.items = reset ? data.items : ct.items.concat(data.items)
			ct.total = data.total
			ct.hasMore = data.hasMore
		} catch (e) {
			core.toast(e.message, true)
		} finally {
			if (mine === ct.seq) {
				ct.loading = false
				renderList()
			}
		}
	}

	function renderNav(navEl) {
		const h = core.h
		navEl.appendChild(h('button', { class: 'wm-compose-btn primary', onclick: () => openForm(null) }, '＋  Nouveau contact'))
		const sel = core.accountSelector()
		if (sel) navEl.appendChild(sel)
		navEl.appendChild(h('p', { class: 'wm-muted wm-cal-hint' }, 'Contacts personnels de la boîte Exchange. La recherche d\'adresses lors de la rédaction inclut aussi l\'annuaire de l\'entreprise.'))
		navEl.appendChild(h('div', { style: 'flex:1' }))
		navEl.appendChild(core.navFooter())
	}

	function renderList() {
		if (!core || core.state.module !== 'contacts') return
		const h = core.h
		const el = core.listEl
		core.clear(el)
		const search = h('input', { type: 'search', class: 'wm-search', placeholder: 'Rechercher un contact', value: ct.query, 'aria-label': 'Rechercher un contact' })
		el.appendChild(h('form', {
			class: 'wm-list-header',
			onsubmit: (e) => { e.preventDefault(); ct.query = search.value.trim(); load(true) },
		},
		h('button', { type: 'button', class: 'wm-back wm-mobile-only', 'aria-label': 'Menu', onclick: () => core.setView('nav') }, '☰'),
		search,
		h('button', { type: 'button', class: 'wm-icon-btn primary wm-mobile-only', 'aria-label': 'Nouveau contact', onclick: () => openForm(null) }, '＋')))

		if (ct.query) {
			el.appendChild(h('div', { class: 'wm-search-info' }, 'Résultats pour « ' + ct.query + ' » ',
				h('a', { href: '#', onclick: (e) => { e.preventDefault(); ct.query = ''; load(true) } }, 'effacer')))
		}
		if (ct.loading && ct.items.length === 0) {
			el.appendChild(h('div', { class: 'wm-center' }, h('span', { class: 'icon-loading' })))
			return
		}
		if (ct.items.length === 0) {
			el.appendChild(h('div', { class: 'wm-empty' }, ct.query ? 'Aucun contact trouvé' : 'Aucun contact'))
			return
		}
		const ul = h('ul', { class: 'wm-messages wm-contacts', role: 'list' })
		let letter = null
		ct.items.forEach((c) => {
			const l = (c.displayName || '#')[0].toLocaleUpperCase('fr-FR').normalize('NFD').replace(/[̀-ͯ]/g, '')
			if (!ct.query && l !== letter) {
				letter = l
				ul.appendChild(h('li', { class: 'wm-letter', 'aria-hidden': 'true' }, l))
			}
			ul.appendChild(h('li', {
				class: 'wm-contact' + (c.id === ct.selectedId ? ' selected' : ''),
				tabindex: '0',
				dataset: { id: c.id },
				onclick: () => openContact(c.id),
				onkeydown: (e) => { if (e.key === 'Enter') openContact(c.id) },
			},
			avatar(c.displayName),
			h('div', { class: 'wm-contact-text' },
				h('div', { class: 'wm-contact-name' }, c.displayName || '(sans nom)', c.isGroup ? h('span', { class: 'wm-muted' }, ' · groupe') : null),
				h('div', { class: 'wm-message-preview' }, [c.email, c.company].filter(Boolean).join(' · ') || c.phone || ''))))
		})
		el.appendChild(ul)
		if (ct.hasMore) {
			el.appendChild(h('div', { class: 'wm-center' },
				h('button', { onclick: () => load(false) }, 'Charger plus (' + ct.items.length + ' / ' + ct.total + ')')))
		}
	}

	function renderEmpty() {
		const h = core.h
		core.clear(core.detailEl)
		core.detailEl.appendChild(h('div', { class: 'wm-empty wm-empty-detail' }, h('div', { class: 'wm-empty-icon' }, '👤'), 'Sélectionnez un contact'))
	}

	async function openContact(id) {
		const h = core.h
		ct.selectedId = id
		renderList()
		core.setView('detail')
		const el = core.detailEl
		core.clear(el)
		el.appendChild(h('div', { class: 'wm-center' }, h('span', { class: 'icon-loading' })))
		let c
		try {
			c = await core.api('/accounts/' + core.accountId + '/contact', { query: { contactId: id } })
		} catch (e) {
			core.clear(el)
			el.appendChild(h('div', { class: 'wm-empty' }, e.message))
			return
		}
		if (ct.selectedId !== id) return
		core.clear(el)

		const row = (icon, label, value) => value ? h('div', { class: 'wm-contact-row' }, h('span', { class: 'wm-contact-icon', 'aria-hidden': 'true' }, icon),
			h('span', { class: 'wm-muted wm-contact-label' }, label), value) : null
		const tel = (v) => v ? h('a', { href: 'tel:' + v.replace(/[^+\d]/g, '') }, v) : null
		const address = [c.street, [c.postalCode, c.city].filter(Boolean).join(' '), c.country].filter(Boolean).join(', ')
		const writeTo = (email) => core.openCompose('new', null, { to: (c.displayName ? c.displayName + ' <' + email + '>' : email) + ', ' })

		el.appendChild(h('div', { class: 'wm-toolbar' },
			h('button', { class: 'wm-back wm-mobile-only', 'aria-label': 'Retour', onclick: () => core.setView('list') }, '←'),
			c.emails.length ? h('button', { class: 'primary', onclick: () => writeTo(c.emails[0]) }, '✉️ Écrire') : null,
			c.isGroup ? null : h('button', { onclick: () => openForm(c) }, '✏️ Modifier'),
			h('button', { class: 'wm-danger', onclick: () => remove(c) }, '🗑 Supprimer')))

		el.appendChild(h('div', { class: 'wm-contact-card' },
			h('div', { class: 'wm-contact-head' }, avatar(c.displayName),
				h('div', {},
					h('h2', { class: 'wm-subject' }, c.displayName || '(sans nom)'),
					h('div', { class: 'wm-muted' }, [c.jobTitle, c.department, c.company].filter(Boolean).join(' · ')))),
			c.emails.map((e, i) => row('✉️', i === 0 ? 'E-mail' : 'E-mail ' + (i + 1),
				h('a', { href: '#', onclick: (ev) => { ev.preventDefault(); writeTo(e) } }, e))),
			row('📱', 'Mobile', tel(c.mobilePhone)),
			row('☎️', 'Bureau', tel(c.businessPhone)),
			row('🏠', 'Domicile', tel(c.homePhone)),
			row('📍', 'Adresse', address ? h('a', { href: 'https://www.openstreetmap.org/search?query=' + encodeURIComponent(address), target: '_blank', rel: 'noopener noreferrer' }, address) : null),
			c.notes.trim() ? h('div', { class: 'wm-event-body' }, c.notes.trim()) : null,
			c.isGroup ? h('p', { class: 'wm-muted' }, 'Les groupes de contacts se gèrent dans Outlook.') : null))
	}

	async function remove(c) {
		if (!window.confirm('Supprimer le contact « ' + (c.displayName || '') + ' » ?')) return
		try {
			await core.api('/accounts/' + core.accountId + '/contacts/delete', { method: 'POST', body: { contactId: c.id } })
			core.toast('Contact supprimé')
			ct.items = ct.items.filter((x) => x.id !== c.id)
			ct.selectedId = null
			renderList()
			renderEmpty()
			core.setView('list')
		} catch (e) { core.toast(e.message, true) }
	}

	function openForm(c) {
		const h = core.h
		const isNew = !c
		c = c || { emails: [] }
		core.setView('detail')
		const el = core.detailEl
		core.clear(el)
		const input = (name, label, type) => {
			const i = h('input', { type: type || 'text', name, value: c[name] || '' })
			return { i, el: h('label', { class: 'wm-field' }, h('span', {}, label), i) }
		}
		const f = {
			givenName: input('givenName', 'Prénom'),
			surname: input('surname', 'Nom'),
			displayName: input('displayName', 'Nom affiché'),
			company: input('company', 'Société'),
			department: input('department', 'Service'),
			jobTitle: input('jobTitle', 'Fonction'),
			mobilePhone: input('mobilePhone', 'Mobile', 'tel'),
			businessPhone: input('businessPhone', 'Tél. bureau', 'tel'),
			homePhone: input('homePhone', 'Tél. domicile', 'tel'),
			street: input('street', 'Rue'),
			postalCode: input('postalCode', 'Code postal'),
			city: input('city', 'Ville'),
			country: input('country', 'Pays'),
		}
		f.displayName.i.placeholder = 'Calculé à partir du prénom et du nom'
		const emails = [0, 1, 2].map((i) => h('input', { type: 'email', name: 'email' + (i + 1), value: c.emails[i] || '' }))
		const notes = h('textarea', { name: 'notes', rows: '4', placeholder: 'Notes' })
		notes.value = c.notes || ''
		const err = h('div', { class: 'wm-form-error', role: 'alert' })
		const save = h('button', { type: 'submit', class: 'primary' }, isNew ? 'Créer le contact' : 'Enregistrer')

		el.appendChild(h('form', {
			class: 'wm-compose wm-contact-form',
			onsubmit: async (e) => {
				e.preventDefault()
				err.textContent = ''
				const data = { emails: emails.map((i) => i.value), notes: notes.value }
				Object.entries(f).forEach(([k, v]) => { data[k] = v.i.value })
				save.disabled = true
				try {
					let id = c.id
					if (isNew) {
						id = (await core.api('/accounts/' + core.accountId + '/contacts', { method: 'POST', body: { contact: data } })).id
					} else {
						await core.api('/accounts/' + core.accountId + '/contacts/update', { method: 'POST', body: { contactId: c.id, contact: data } })
					}
					core.toast(isNew ? 'Contact créé' : 'Contact enregistré')
					await load(true)
					openContact(id)
				} catch (ex) {
					err.textContent = ex.message
					save.disabled = false
				}
			},
		},
		h('div', { class: 'wm-compose-title' },
			h('button', { type: 'button', class: 'wm-back wm-mobile-only', 'aria-label': 'Retour', onclick: () => core.setView('list') }, '←'),
			h('h2', {}, isNew ? 'Nouveau contact' : 'Modifier le contact')),
		f.givenName.el, f.surname.el, f.displayName.el, f.company.el, f.department.el, f.jobTitle.el,
		emails.map((i, n) => h('label', { class: 'wm-field' }, h('span', {}, n === 0 ? 'E-mail' : 'E-mail ' + (n + 1)), i)),
		f.mobilePhone.el, f.businessPhone.el, f.homePhone.el,
		f.street.el, f.postalCode.el, f.city.el, f.country.el,
		notes,
		err,
		h('div', { class: 'wm-compose-actions' }, save,
			h('button', { type: 'button', onclick: () => (isNew ? (renderEmpty(), core.setView('list')) : openContact(c.id)) }, 'Annuler'))))
		f.givenName.i.focus()
	}

	window.WebmailModules = window.WebmailModules || {}
	window.WebmailModules.contacts = {
		activate(c) {
			core = c
			ct.selectedId = null
			core.renderNav()
			core.setView('list')
			renderEmpty()
			load(true)
		},
		renderNav(navEl, c) {
			core = c
			renderNav(navEl)
		},
	}
})()
