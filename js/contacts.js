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
		books: [], // own contact folders, shared ones, the company directory
		booksFor: null, // account id the list belongs to
		book: 'contacts', // displayed address book
	}

	function currentBook() {
		return ct.books.find((b) => b.key === ct.book) || { key: 'contacts', name: 'Contacts', kind: 'own', canEdit: true }
	}

	async function loadBooks(force) {
		if (!force && ct.booksFor === core.accountId && ct.books.length) return
		const accountId = core.accountId
		let books = [{ key: 'contacts', name: 'Contacts', kind: 'own', canEdit: true }]
		try {
			books = (await core.api('/accounts/' + accountId + '/contacts/books')).books
		} catch (e) { core.toast(e.message, true) }
		if (accountId !== core.accountId) return
		ct.books = books
		ct.booksFor = accountId
		let saved = null
		try { saved = localStorage.getItem('webmail.book.' + accountId) } catch (e) { /* ignore */ }
		ct.book = books.some((b) => b.key === saved) ? saved : (books.some((b) => b.key === ct.book) ? ct.book : 'contacts')
		core.renderNav()
	}

	function selectBook(key) {
		ct.book = key
		ct.query = ''
		ct.selectedId = null
		try { localStorage.setItem('webmail.book.' + core.accountId, key) } catch (e) { /* ignore */ }
		core.renderNav()
		core.setView('list')
		renderEmpty()
		load(true)
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
			await loadBooks(false)
			if (mine !== ct.seq) return
			if (ct.book === 'directory' && ct.query.length < 2) {
				// the directory can only be searched, not listed
				ct.items = []
				ct.total = 0
				ct.hasMore = false
				return
			}
			const data = await core.api('/accounts/' + core.accountId + '/contacts', {
				query: { offset: reset ? 0 : ct.items.length, limit: PAGE, q: ct.query, book: ct.book },
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
		navEl.appendChild(bookList())
		navEl.appendChild(h('div', { style: 'flex:1' }))
		navEl.appendChild(core.navFooter())
	}

	function bookList() {
		const h = core.h
		const row = (b) => h('li', { class: 'wm-cal-row wm-book-row' + (b.key === ct.book ? ' active' : ''), dataset: { key: b.key } },
			h('a', {
				href: '#',
				class: 'wm-book-link',
				title: b.email || b.name,
				onclick: (e) => { e.preventDefault(); selectBook(b.key) },
			},
			h('span', { class: 'wm-book-icon', 'aria-hidden': 'true' }, b.kind === 'directory' ? '🏢' : (b.kind === 'shared' ? '👥' : '📇')),
			h('span', { class: 'wm-cal-name' }, b.name)),
			b.kind === 'shared' ? h('span', { class: 'wm-cal-mode' + (b.canEdit ? ' wm-cal-mode-edit' : '') }, b.canEdit ? 'modification' : 'lecture') : null,
			b.kind === 'shared' ? h('button', {
				type: 'button',
				class: 'wm-cal-remove',
				title: 'Retirer ce carnet',
				'aria-label': 'Retirer les contacts de ' + b.name,
				onclick: () => removeShared(b),
			}, '✕') : null)
		const own = ct.books.filter((b) => b.kind === 'own')
		const shared = ct.books.filter((b) => b.kind === 'shared')
		const directory = ct.books.filter((b) => b.kind === 'directory')
		return h('div', { class: 'wm-cal-list' },
			h('div', { class: 'wm-cal-section' }, 'Mes carnets'),
			h('ul', {}, own.map(row)),
			h('div', { class: 'wm-cal-section' }, 'Contacts partagés'),
			shared.length ? h('ul', {}, shared.map(row)) : null,
			h('button', { type: 'button', class: 'wm-cal-add', onclick: openAddShared }, '＋ Ajouter les contacts d\'un collègue'),
			directory.length ? h('ul', { class: 'wm-directory' }, directory.map(row)) : null)
	}

	function openAddShared() {
		const h = core.h
		const email = h('input', { type: 'text', name: 'sharedEmail', required: true, placeholder: 'prenom.nom@exemple.fr' })
		const err = h('div', { class: 'wm-form-error', role: 'alert' })
		const btn = h('button', { type: 'submit', class: 'primary' }, 'Ajouter')
		const overlay = h('div', { class: 'wm-overlay' })
		const close = () => overlay.remove()
		overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) close() })
		const form = h('form', {
			class: 'wm-event-form',
			onsubmit: async (e) => {
				e.preventDefault()
				err.textContent = ''
				btn.disabled = true
				btn.textContent = 'Vérification de l\'accès…'
				try {
					const b = await core.api('/accounts/' + core.accountId + '/contacts/shared', { method: 'POST', body: { email: email.value } })
					close()
					core.toast('Contacts de ' + b.name + ' ajoutés (' + (b.canEdit ? 'modification' : 'lecture') + ')')
					await loadBooks(true)
					selectBook(b.key)
				} catch (ex) {
					err.textContent = ex.message
					btn.disabled = false
					btn.textContent = 'Ajouter'
				}
			},
		},
		h('p', { class: 'wm-muted' }, 'Indiquez l\'adresse d\'un collègue qui vous a partagé son dossier « Contacts » dans Outlook '
			+ '(clic droit sur « Contacts » → Propriétés → Autorisations). Le niveau d\'accès, lecture ou modification, est détecté automatiquement.'),
		h('label', { class: 'wm-field' }, h('span', {}, 'Adresse'), core.attachAutocomplete(email)),
		err,
		h('div', { class: 'wm-compose-actions' }, btn, h('button', { type: 'button', onclick: close }, 'Annuler')))
		overlay.appendChild(h('div', { class: 'wm-modal', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Ajouter des contacts partagés' },
			h('div', { class: 'wm-modal-header' }, h('h2', {}, 'Ajouter les contacts d\'un collègue'),
				h('button', { class: 'wm-modal-close', 'aria-label': 'Fermer', onclick: close }, '✕')),
			form))
		document.body.appendChild(overlay)
		email.focus()
	}

	async function removeShared(b) {
		if (!window.confirm('Retirer les contacts de ' + b.name + ' de la liste ? (rien n\'est supprimé dans Exchange)')) return
		try {
			await core.api('/accounts/' + core.accountId + '/contacts/shared/delete', { method: 'POST', body: { email: b.email } })
			core.toast('Carnet retiré')
			if (ct.book === b.key) ct.book = 'contacts'
			await loadBooks(true)
			selectBook(ct.book)
		} catch (e) { core.toast(e.message, true) }
	}

	function renderList() {
		if (!core || core.state.module !== 'contacts') return
		const h = core.h
		const el = core.listEl
		core.clear(el)
		const book = currentBook()
		const isDirectory = book.kind === 'directory'
		const search = h('input', {
			type: 'search',
			class: 'wm-search',
			placeholder: isDirectory ? 'Chercher dans l\'annuaire (nom, adresse…)' : 'Rechercher dans ' + book.name,
			value: ct.query,
			'aria-label': 'Rechercher un contact',
		})
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
			el.appendChild(h('div', { class: 'wm-empty' }, isDirectory && ct.query.length < 2
				? 'Tapez au moins 2 lettres pour chercher dans l\'annuaire de l\'entreprise.'
				: (ct.query ? 'Aucun contact trouvé' : 'Aucun contact')))
			if (isDirectory) setTimeout(() => search.focus(), 0)
			return
		}
		if (isDirectory && ct.items.length >= 100) {
			el.appendChild(h('div', { class: 'wm-search-info' }, 'Exchange limite la recherche à 100 résultats : précisez votre recherche.'))
		}
		const ul = h('ul', { class: 'wm-messages wm-contacts', role: 'list' })
		let letter = null
		ct.items.forEach((c) => {
			const l = (c.displayName || '#')[0].toLocaleUpperCase('fr-FR').normalize('NFD').replace(/[̀-ͯ]/g, '')
			if ((!ct.query || isDirectory) && l !== letter) {
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
		const book = currentBook()
		try {
			c = String(id).startsWith('directory:')
				? ct.items.find((x) => x.id === id)
				: await core.api('/accounts/' + core.accountId + '/contact', { query: { contactId: id } })
			if (!c) throw new Error('Contact introuvable')
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
			!book.canEdit || c.isGroup ? null : h('button', { onclick: () => openForm(c) }, '✏️ Modifier'),
			book.canEdit ? h('button', { class: 'wm-danger', onclick: () => remove(c) }, '🗑 Supprimer') : null,
			!book.canEdit && !c.isGroup ? h('button', { onclick: () => copyToMine(c) }, '＋ Ajouter à mes contacts') : null,
			!book.canEdit ? h('span', { class: 'wm-muted' }, book.kind === 'directory' ? '🏢 Annuaire de l\'entreprise' : '🔒 Contacts de ' + book.name + ' (lecture seule)') : null))

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
			row('🏢', 'Site', c.office || null),
			row('📍', 'Adresse', address ? h('a', { href: 'https://www.openstreetmap.org/search?query=' + encodeURIComponent(address), target: '_blank', rel: 'noopener noreferrer' }, address) : null),
			(c.notes || '').trim() ? h('div', { class: 'wm-event-body' }, c.notes.trim()) : null,
			c.isGroup ? h('p', { class: 'wm-muted' }, 'Les groupes de contacts se gèrent dans Outlook.') : null))
	}

	/** Copy a read-only card (directory, shared) into the user's own contacts. */
	async function copyToMine(c) {
		const data = {}
		;['givenName', 'surname', 'displayName', 'company', 'department', 'jobTitle', 'mobilePhone', 'businessPhone', 'homePhone',
			'street', 'postalCode', 'city', 'country', 'notes'].forEach((k) => { data[k] = c[k] || '' })
		data.emails = c.emails || []
		try {
			await core.api('/accounts/' + core.accountId + '/contacts', { method: 'POST', body: { contact: data, book: 'contacts' } })
			core.toast((c.displayName || 'Contact') + ' ajouté à vos contacts')
		} catch (e) { core.toast(e.message, true) }
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
		const writable = ct.books.filter((b) => b.canEdit)
		const bookSelect = isNew && writable.length > 1
			? h('select', { name: 'book', 'aria-label': 'Carnet' }, writable.map((b) => h('option', { value: b.key, selected: b.key === (currentBook().canEdit ? ct.book : 'contacts') },
				b.kind === 'shared' ? 'Contacts de ' + b.name : b.name)))
			: null

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
						const target = bookSelect ? bookSelect.value : 'contacts'
						id = (await core.api('/accounts/' + core.accountId + '/contacts', { method: 'POST', body: { contact: data, book: target } })).id
						if (target !== ct.book) {
							ct.book = target
							core.renderNav()
						}
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
		bookSelect ? h('label', { class: 'wm-field' }, h('span', {}, 'Carnet'), bookSelect) : null,
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
			if (ct.booksFor !== core.accountId) {
				ct.books = []
				ct.book = 'contacts'
			}
			ct.query = ''
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
