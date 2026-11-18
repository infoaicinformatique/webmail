/* Webmail Exchange – contacts module (vanilla JS, no build step) */
(function () {
	'use strict'

	const PAGE = 100
	const _ = (s, vars) => (window.t ? window.t('webmail', s, vars, undefined, { escape: false }) : s)
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
		photoV: {}, // contact id -> version of its photo (changed here: not the cached one)
		detailSeq: 0, // what the right pane shows: a card still loading does not replace a form opened since
		presence: {}, // address (lower case) -> online | away | dnd | offline: the contacts with a Nextcloud account
		everywhere: false, // the search is made in every address book (each result says its book)
		failed: [], // the books not searched (unreachable)
	}

	/** "account|book" -> { items, total, hasMore }: a book seen before shows at once, then is refreshed */
	const listCache = new Map()

	function api(path, opts) {
		if (opts && opts.method && opts.method !== 'GET') listCache.clear()
		return core.api(path, opts)
	}

	function currentBook() {
		return ct.books.find((b) => b.key === ct.book) || { key: 'contacts', name: _('Contacts'), kind: 'own', canEdit: true }
	}

	/** @param {boolean} quiet loaded ahead, while another module is shown: no message, no redraw */
	async function loadBooks(force, quiet) {
		if (!force && ct.booksFor === core.accountId && ct.books.length) return
		const accountId = core.accountId
		let books = [{ key: 'contacts', name: _('Contacts'), kind: 'own', canEdit: true }]
		try {
			books = (await api('/accounts/' + accountId + '/contacts/books')).books
		} catch (e) {
			if (quiet) throw e
			core.toast(e.message, true)
		}
		if (accountId !== core.accountId) return
		ct.books = books
		ct.booksFor = accountId
		let saved = null
		try { saved = localStorage.getItem('webmail.book.' + accountId) } catch (e) { /* ignore */ }
		ct.book = books.some((b) => b.key === saved) ? saved : (books.some((b) => b.key === ct.book) ? ct.book : 'contacts')
		if (core.state.module === 'contacts') core.renderNav()
	}

	function selectBook(key) {
		ct.book = key
		ct.query = ''
		ct.everywhere = false
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

	function avatar(name, photo) {
		// stable pastel colour from the name
		let hash = 0
		for (const ch of name || '') hash = (hash * 31 + ch.charCodeAt(0)) | 0
		const letters = core.h('span', { class: 'wm-avatar', style: 'background:hsl(' + (Math.abs(hash) % 360) + ',55%,45%)', 'aria-hidden': 'true' }, initials(name))
		if (!photo) return letters
		// the photo, or the initials when there is none after all
		const img = core.h('img', { class: 'wm-avatar wm-avatar-photo', src: photo, alt: '', loading: 'lazy', onerror: () => img.replaceWith(letters) })
		return img
	}

	/** The address whose Nextcloud status is shown: the first one of the contact. */
	const presenceEmail = (c) => String(c.email || (c.emails && c.emails[0]) || '').trim().toLowerCase()
	const PRESENCE_LABELS = {
		online: () => _('En ligne'),
		away: () => _('Absent pour le moment'),
		dnd: () => _('Ne pas déranger'),
		offline: () => _('Hors ligne'),
	}

	/** The avatar with the dot of the Nextcloud status (hidden while unknown, or without Nextcloud account). */
	function avatarWithPresence(c) {
		const email = presenceEmail(c)
		const dot = core.h('span', { class: 'wm-presence', role: 'img', dataset: { email } })
		paintPresence(dot)
		return core.h('span', { class: 'wm-avatar-wrap' }, avatar(c.displayName, photoUrl(c)), dot)
	}

	function paintPresence(dot) {
		const status = ct.presence[dot.dataset.email]
		dot.className = 'wm-presence' + (status ? ' wm-presence-' + status : '')
		dot.hidden = !status
		const label = status ? PRESENCE_LABELS[status]() : ''
		dot.title = label
		dot.setAttribute('aria-label', label)
	}

	let presenceSeq = 0
	/** Asks the statuses of the contacts shown, then repaints their dots (silent: a nicety). */
	async function refreshPresence() {
		if (!core || core.state.module !== 'contacts') return
		const emails = [...new Set(ct.items.map(presenceEmail).filter(Boolean))].slice(0, 200)
		const card = core.detailEl && core.detailEl.querySelector('.wm-contact-head .wm-presence')
		if (card && card.dataset.email && !emails.includes(card.dataset.email)) emails.unshift(card.dataset.email)
		if (!emails.length) return
		const mine = ++presenceSeq
		try {
			const r = await core.api('/presence', { method: 'POST', body: { emails: emails.slice(0, 200) } })
			if (mine !== presenceSeq) return
			emails.forEach((e) => { delete ct.presence[e] })
			Object.assign(ct.presence, r.presence || {})
			document.querySelectorAll('.wm-presence[data-email]').forEach(paintPresence)
		} catch (e) {
			// statuses not available (user_status disabled…): no dots
		}
	}
	// kept up to date while the contacts are shown
	setInterval(() => { if (!document.hidden) refreshPresence() }, 60000)

	/** Photo URL: a contact's own, or (directory) the one of their Exchange account. */
	function photoUrl(c) {
		const base = OC.generateUrl('/apps/webmail/api/accounts/' + core.accountId + '/contacts/')
		const email = c.email || (c.emails && c.emails[0])
		if (String(c.id).startsWith('directory:')) return email ? base + 'userphoto?email=' + encodeURIComponent(email) : null
		// an account of this Nextcloud: its avatar
		if (c.avatarUser) return OC.generateUrl('/avatar/{user}/64', { user: c.avatarUser })
		if (!c.hasPhoto) return null
		return base + 'photo?contactId=' + encodeURIComponent(c.id) + '&book=' + encodeURIComponent(c.book || ct.book) + (ct.photoV[c.id] ? '&v=' + ct.photoV[c.id] : '')
	}

	/** An image file as a small JPEG (256 pixels at most), as a data: URL. */
	function photoDataUrl(file) {
		return new Promise((resolve, reject) => {
			const r = new FileReader()
			r.onerror = () => reject(new Error(_('Image illisible')))
			r.onload = () => {
				const img = new Image()
				img.onerror = () => reject(new Error(_('Ce fichier n\'est pas une image')))
				img.onload = () => {
					// a centred square, like the avatars
					const side = Math.min(img.naturalWidth, img.naturalHeight)
					const size = Math.min(256, side)
					const canvas = document.createElement('canvas')
					canvas.width = canvas.height = size
					const ctx = canvas.getContext('2d')
					ctx.fillStyle = '#fff'
					ctx.fillRect(0, 0, size, size)
					ctx.drawImage(img, (img.naturalWidth - side) / 2, (img.naturalHeight - side) / 2, side, side, 0, 0, size, size)
					resolve(canvas.toDataURL('image/jpeg', 0.88))
				}
				img.src = r.result
			}
			r.readAsDataURL(file)
		})
	}

	async function load(reset) {
		const mine = ++ct.seq
		ct.loading = true
		const cacheKey = core.accountId + '|' + ct.book
		const cached = reset && !ct.query && listCache.get(cacheKey)
		if (cached) Object.assign(ct, { items: cached.items, total: cached.total, hasMore: cached.hasMore })
		else if (reset) { ct.items = []; ct.total = 0; ct.hasMore = false }
		renderList()
		try {
			await loadBooks(false)
			if (mine !== ct.seq) return
			if (ct.everywhere && ct.query.length >= 2) {
				// every address book at once
				const data = await api('/accounts/' + core.accountId + '/contacts/search', { query: { q: ct.query } })
				if (mine !== ct.seq) return
				ct.items = data.items
				ct.total = data.items.length
				ct.hasMore = false
				ct.failed = data.failed || []
				return
			}
			ct.everywhere = false
			if (ct.book === 'directory' && ct.query.length < 2) {
				// the directory can only be searched, not listed
				ct.items = []
				ct.total = 0
				ct.hasMore = false
				return
			}
			const data = await api('/accounts/' + core.accountId + '/contacts', {
				query: { offset: reset ? 0 : ct.items.length, limit: PAGE, q: ct.query, book: ct.book },
			})
			if (mine !== ct.seq) return
			ct.items = reset ? data.items : ct.items.concat(data.items)
			ct.total = data.total
			ct.hasMore = data.hasMore
			if (!ct.query) listCache.set(cacheKey, { items: ct.items, total: ct.total, hasMore: ct.hasMore })
		} catch (e) {
			core.toast(e.message, true)
		} finally {
			if (mine === ct.seq) {
				ct.loading = false
				renderList()
				refreshPresence()
			}
		}
	}

	function renderNav(navEl) {
		const h = core.h
		navEl.appendChild(h('button', { class: 'wm-compose-btn primary', onclick: () => openForm(null) }, '＋  ' + _('Nouveau contact')))
		navEl.appendChild(h('button', { class: 'wm-new-group', onclick: () => openGroupForm(null) }, '👥  ' + _('Nouveau groupe')))
		const sel = core.accountSelector()
		if (sel) navEl.appendChild(sel)
		navEl.appendChild(bookList())
		navEl.appendChild(h('div', { style: 'flex:1' }))
		navEl.appendChild(core.navFooter())
	}

	/** "Contacts de Marie Martin", "« Clients VIP » de Marie Martin", or the book's own name */
	function bookTitle(b) {
		if (b.kind === 'public') return _('« {name} » (dossiers publics)', { name: b.name })
		if (b.kind === 'nextcloud') return _('{name} (Nextcloud)', { name: b.name })
		if (b.kind !== 'shared') return b.name
		return b.folderName ? _('« {folder} » de {name}', { folder: b.folderName, name: b.name }) : _('Contacts de {name}', { name: b.name })
	}

	function bookList() {
		const h = core.h
		const row = (b) => h('li', { class: 'wm-cal-row wm-book-row' + (b.key === ct.book ? ' active' : ''), dataset: { key: b.key } },
			h('a', {
				href: '#',
				class: 'wm-book-link',
				title: b.kind === 'public' ? _('Dossiers publics') + ' › ' + (b.path || b.name) : (b.email || b.name),
				onclick: (e) => { e.preventDefault(); selectBook(b.key) },
			},
			h('span', { class: 'wm-book-icon', 'aria-hidden': 'true' }, b.accounts ? '👤' : ({ directory: '🏢', shared: '👥', public: '🗂️', nextcloud: '☁️' }[b.kind] || '📇')),
			h('span', { class: 'wm-cal-name' }, b.kind === 'shared' && b.folderName ? b.folderName + ' (' + b.name + ')' : b.name)),
			b.kind === 'shared' || b.kind === 'public' || (b.kind === 'nextcloud' && (b.shared || !b.canEdit)) ? h('span', { class: 'wm-cal-mode' + (b.canEdit ? ' wm-cal-mode-edit' : '') }, b.canEdit ? _('modification') : _('lecture')) : null,
			b.kind === 'own' ? h('button', {
				type: 'button',
				class: 'wm-cal-share',
				title: _('Partager ce carnet'),
				'aria-label': _('Partager {name}', { name: b.name }),
				onclick: () => openShare(b),
			}, '🔗') : null,
			b.kind === 'shared' ? h('button', {
				type: 'button',
				class: 'wm-cal-remove',
				title: _('Retirer ce carnet'),
				'aria-label': _('Retirer {name}', { name: bookTitle(b) }),
				onclick: () => removeShared(b),
			}, '✕') : null,
			b.kind === 'public' ? h('button', {
				type: 'button',
				class: 'wm-cal-remove',
				title: _('Retirer ce carnet'),
				'aria-label': _('Retirer {name}', { name: b.name }),
				onclick: () => removePublic(b),
			}, '✕') : null)
		const own = ct.books.filter((b) => b.kind === 'own')
		const shared = ct.books.filter((b) => b.kind === 'shared')
		const publicBooks = ct.books.filter((b) => b.kind === 'public')
		const directory = ct.books.filter((b) => b.kind === 'directory')
		const nextcloud = ct.books.filter((b) => b.kind === 'nextcloud')
		return h('div', { class: 'wm-cal-list' },
			h('div', { class: 'wm-cal-section' }, _('Mes carnets')),
			h('ul', {}, own.map(row)),
			h('div', { class: 'wm-cal-section' }, _('Contacts partagés')),
			shared.length ? h('ul', {}, shared.map(row)) : null,
			h('button', { type: 'button', class: 'wm-cal-add', onclick: openAddShared }, '＋ ' + _('Ajouter les contacts d\'un collègue')),
			h('div', { class: 'wm-cal-section' }, _('Dossiers publics')),
			publicBooks.length ? h('ul', {}, publicBooks.map(row)) : null,
			h('button', { type: 'button', class: 'wm-cal-add wm-public-add', onclick: openAddPublic }, '＋ ' + _('Ajouter un carnet des dossiers publics')),
			directory.length ? h('ul', { class: 'wm-directory' }, directory.map(row)) : null,
			// the address books of the Contacts app (own and shared with you): the same cards, read and changed here
			nextcloud.length ? h('div', { class: 'wm-cal-section' }, _('Carnets Nextcloud')) : null,
			nextcloud.length ? h('ul', { class: 'wm-nc-books' }, nextcloud.map(row)) : null)
	}

	/**
	 * Share one of my address books without delegation (Outlook: right-click
	 * on the contacts folder > Properties > Permissions).
	 */
	function openShare(b) {
		const base = '/accounts/' + core.accountId + '/contacts/permissions'
		window.WebmailSharing.open(core, {
			title: _('Partager « {name} »', { name: b.name }),
			intro: _('Choisissez qui peut consulter ou modifier ce carnet d\'adresses, sans en faire un délégué. C\'est le réglage « Autorisations » du dossier dans Outlook. Vos collègues l\'ajoutent ensuite avec « Ajouter les contacts d\'un collègue ». Pour un carnet autre que « Contacts », donnez aussi le droit « Dossier visible » sur votre boîte (dans Outlook) ou rangez-le dans « Contacts ».'),
			levels: [
				['None', _('Aucun accès')],
				['Reviewer', _('Lecture')],
				['Author', _('Lecture et ajout de contacts')],
				['Editor', _('Modification')],
			],
			newLevel: 'Reviewer',
			load: async () => (await api(base, { query: { book: b.key } })).permissions,
			save: (permissions) => api(base, { method: 'POST', body: { book: b.key, permissions } }),
			savedMessage: _('Partage de « {name} » enregistré', { name: b.name }),
		})
	}

	/**
	 * Add a colleague's address books: their « Contacts » and the other books
	 * they share. When they share several, the user ticks the ones to add.
	 */
	function openAddShared() {
		const h = core.h
		const base = '/accounts/' + core.accountId + '/contacts/shared'
		const email = h('input', { type: 'text', name: 'sharedEmail', required: true, placeholder: _('prenom.nom@exemple.fr') })
		const choices = h('div', { class: 'wm-shared-choices' })
		const err = h('div', { class: 'wm-form-error', role: 'alert' })
		const btn = h('button', { type: 'submit', class: 'primary' }, _('Ajouter'))
		const overlay = h('div', { class: 'wm-overlay' })
		const close = () => overlay.remove()
		overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) close() })
		let offered = null // books found, once the colleague's address is checked
		const showChoices = (r) => {
			offered = r
			email.readOnly = true
			core.clear(choices)
			choices.appendChild(h('p', {}, _('{name} vous partage {count} carnets. Cochez ceux à ajouter :', { name: r.name, count: r.choices.length })))
			choices.appendChild(h('ul', { class: 'wm-choice-list' }, r.choices.map((b) => h('li', {},
				h('label', {},
					h('input', { type: 'checkbox', name: 'sharedFolder', value: b.folder, checked: b.added || b.folder === '', disabled: b.added }),
					' ' + (b.folderName || _('Contacts')) + ' ',
					h('span', { class: 'wm-cal-mode' + (b.canEdit ? ' wm-cal-mode-edit' : '') }, b.canEdit ? _('modification') : _('lecture')),
					b.added ? h('span', { class: 'wm-muted' }, ' ' + _('déjà ajouté')) : null)))))
			btn.textContent = _('Ajouter les carnets cochés')
		}
		const form = h('form', {
			class: 'wm-event-form',
			onsubmit: async (e) => {
				e.preventDefault()
				err.textContent = ''
				const body = { email: email.value }
				if (offered) {
					body.folders = [...choices.querySelectorAll('input[name=sharedFolder]:checked:not(:disabled)')].map((c) => c.value)
					if (!body.folders.length) { err.textContent = _('Cochez au moins un carnet'); return }
				}
				const label = btn.textContent
				btn.disabled = true
				btn.textContent = offered ? _('Ajout…') : _('Recherche des carnets partagés…')
				try {
					const r = await api(base, { method: 'POST', body })
					btn.disabled = false
					btn.textContent = label
					if (r.choices) { showChoices(r); return }
					close()
					const one = r.added[0]
					const mode = (b) => (b.canEdit ? _('modification') : _('lecture'))
					core.toast(r.added.length > 1
						? _('{count} carnets de {name} ajoutés', { count: r.added.length, name: r.name })
						: (one.folderName ? _('« {folder} » de {name} ajouté', { folder: one.folderName, name: r.name }) : _('Contacts de {name} ajoutés', { name: r.name })) + ' (' + mode(one) + ')')
					await loadBooks(true)
					selectBook(one.key)
				} catch (ex) {
					err.textContent = ex.message
					btn.disabled = false
					btn.textContent = label
				}
			},
		},
		h('p', { class: 'wm-muted' }, _('Indiquez l\'adresse d\'un collègue qui vous a partagé un carnet d\'adresses dans Outlook (clic droit sur le carnet → Propriétés → Autorisations). Le niveau d\'accès, lecture ou modification, est détecté automatiquement. Ses autres carnets apparaissent s\'ils sont rangés dans son dossier « Contacts » partagé, ou si sa boîte vous donne le droit « Dossier visible ».')),
		h('label', { class: 'wm-field' }, h('span', {}, _('Adresse')), core.attachAutocomplete(email)),
		choices,
		err,
		h('div', { class: 'wm-compose-actions' }, btn, h('button', { type: 'button', onclick: close }, _('Annuler'))))
		overlay.appendChild(h('div', { class: 'wm-modal', role: 'dialog', 'aria-modal': 'true', 'aria-label': _('Ajouter des contacts partagés') },
			h('div', { class: 'wm-modal-header' }, h('h2', {}, _('Ajouter les contacts d\'un collègue')),
				h('button', { class: 'wm-modal-close', 'aria-label': _('Fermer'), onclick: close }, '✕')),
			form))
		document.body.appendChild(overlay)
		email.focus()
	}

	/**
	 * Browse the public folders level by level (like Outlook's folder list)
	 * and add the address books found there.
	 */
	function openAddPublic() {
		publicPicker(core, {
			base: '/accounts/' + core.accountId + '/contacts/public',
			title: _('Ajouter un carnet des dossiers publics'),
			intro: _('Les dossiers publics sont partagés par toute l\'organisation. Ouvrez les dossiers pour trouver les carnets d\'adresses ; le niveau d\'accès, lecture ou modification, est celui défini par l\'administrateur Exchange.'),
			icon: '📇',
			empty: _('Aucun carnet d\'adresses ici.'),
			onAdded: async (b) => { await loadBooks(true); selectBook(b.key) },
		})
	}

	/**
	 * Browse the public folders and add one of them (an address book, or a
	 * calendar for the calendar module: window.WebmailPublicPicker).
	 */
	function publicPicker(core, opts) {
		const h = core.h
		const base = opts.base
		const overlay = h('div', { class: 'wm-overlay' })
		const close = () => { overlay.remove(); document.removeEventListener('keydown', onKey) }
		const onKey = (e) => { if (e.key === 'Escape') close() }
		document.addEventListener('keydown', onKey)
		overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) close() })
		const crumbs = h('nav', { class: 'wm-pf-path', 'aria-label': _('Emplacement') })
		const list = h('ul', { class: 'wm-pf-list' })
		const err = h('div', { class: 'wm-form-error', role: 'alert' })
		const path = [{ id: '', name: _('Dossiers publics') }]
		let seq = 0

		const open = async () => {
			const mine = ++seq
			err.textContent = ''
			core.clear(crumbs)
			path.forEach((p, i) => {
				if (i) crumbs.appendChild(document.createTextNode(' › '))
				crumbs.appendChild(i === path.length - 1
					? h('strong', {}, p.name)
					: h('a', { href: '#', onclick: (e) => { e.preventDefault(); path.length = i + 1; open() } }, p.name))
			})
			core.clear(list)
			list.appendChild(h('li', { class: 'wm-center' }, h('span', { class: 'icon-loading-small' })))
			let folders
			try {
				folders = (await core.api(base, { query: { parent: path[path.length - 1].id } })).folders
			} catch (e) {
				if (mine !== seq) return
				core.clear(list)
				err.textContent = e.message
				return
			}
			if (mine !== seq) return
			core.clear(list)
			if (!folders.length) list.appendChild(h('li', { class: 'wm-muted' }, opts.empty))
			folders.forEach((f) => {
				const where = path.slice(1).map((p) => p.name).concat(f.name).join(' › ')
				list.appendChild(h('li', { class: 'wm-pf-folder', dataset: { name: f.name } },
					f.hasChildren
						? h('a', { href: '#', class: 'wm-pf-open', onclick: (e) => { e.preventDefault(); path.push({ id: f.id, name: f.name }); open() } },
							h('span', { 'aria-hidden': 'true' }, f.isContacts ? opts.icon + ' ' : '📁 '), f.name, h('span', { class: 'wm-muted' }, ' ›'))
						: h('span', {}, h('span', { 'aria-hidden': 'true' }, opts.icon + ' '), f.name),
					f.isContacts ? h('span', { class: 'wm-cal-mode' + (f.canEdit ? ' wm-cal-mode-edit' : '') }, f.canEdit ? _('modification') : _('lecture')) : null,
					f.isContacts
						? (f.added
							? h('span', { class: 'wm-muted' }, _('déjà ajouté'))
							: h('button', {
								type: 'button',
								class: 'wm-pf-add',
								onclick: async (e) => {
									e.target.disabled = true
									try {
										const b = await core.api(base, { method: 'POST', body: { folder: f.id, path: where } })
										close()
										core.toast(_('« {name} » ajouté ({mode})', { name: b.name, mode: b.canEdit ? _('modification') : _('lecture') }))
										await opts.onAdded(b)
									} catch (ex) {
										err.textContent = ex.message
										e.target.disabled = false
									}
								},
							}, _('Ajouter')))
						: null))
			})
		}

		overlay.appendChild(h('div', { class: 'wm-modal', role: 'dialog', 'aria-modal': 'true', 'aria-label': opts.title },
			h('div', { class: 'wm-modal-header' }, h('h2', {}, opts.title),
				h('button', { class: 'wm-modal-close', 'aria-label': _('Fermer'), onclick: close }, '✕')),
			h('div', { class: 'wm-event-form' },
				h('p', { class: 'wm-muted' }, opts.intro),
				crumbs, list, err,
				h('div', { class: 'wm-compose-actions' }, h('button', { type: 'button', onclick: close }, _('Fermer'))))))
		document.body.appendChild(overlay)
		open()
	}

	async function removePublic(b) {
		if (!window.confirm(_('Retirer « {name} » de la liste ? (rien n\'est supprimé dans Exchange)', { name: b.name }))) return
		try {
			await api('/accounts/' + core.accountId + '/contacts/public/delete', { method: 'POST', body: { folder: b.folder } })
			core.toast(_('Carnet retiré'))
			if (ct.book === b.key) ct.book = 'contacts'
			await loadBooks(true)
			selectBook(ct.book)
		} catch (e) { core.toast(e.message, true) }
	}

	async function removeShared(b) {
		if (!window.confirm(_('Retirer {name} de la liste ? (rien n\'est supprimé dans Exchange)', { name: bookTitle(b) }))) return
		try {
			await api('/accounts/' + core.accountId + '/contacts/shared/delete', { method: 'POST', body: { email: b.email, folder: b.folder || '' } })
			core.toast(_('Carnet retiré'))
			if (ct.book === b.key) ct.book = 'contacts'
			await loadBooks(true)
			selectBook(ct.book)
		} catch (e) { core.toast(e.message, true) }
	}

	/**
	 * The copy of the Exchange contacts in an address book of Nextcloud
	 * Contacts, brought up to date every 6 hours by Nextcloud.
	 */
	async function openCopy() {
		const h = core.h
		const base = '/accounts/' + core.accountId + '/contacts/copy'
		const body = h('div', { class: 'wm-copy-body' }, h('span', { class: 'icon-loading' }))
		const close = () => { overlay.remove(); document.removeEventListener('keydown', onKey) }
		const onKey = (e) => { if (e.key === 'Escape') close() }
		const title = _('Copie dans Nextcloud Contacts')
		const overlay = h('div', { class: 'wm-overlay', onmousedown: (e) => { if (e.target === overlay) close() } },
			h('div', { class: 'wm-modal wm-modal-small wm-nc-copy-dialog', role: 'dialog', 'aria-modal': 'true', 'aria-label': title },
				h('div', { class: 'wm-modal-header' }, h('h2', {}, title), h('button', { class: 'wm-modal-close', 'aria-label': _('Fermer'), onclick: close }, '✕')),
				body))
		document.addEventListener('keydown', onKey)
		document.body.appendChild(overlay)
		const contactsUrl = OC.generateUrl('/apps/contacts/')
		const render = (st, busy) => {
			core.clear(body)
			body.appendChild(h('p', {}, _('Vos contacts Exchange sont copiés dans un carnet « Exchange — {email} » de Nextcloud Contacts : ils y sont visibles par l\'application Contacts, les téléphones synchronisés avec Nextcloud et les autres applications. Exchange reste la référence : la copie est remise à jour toutes les 6 heures, et les changements faits dans la copie sont remplacés.', { email: ((core.state.accounts || []).find((a) => a.id === core.accountId) || {}).email || '' })))
			if (st.enabled) {
				const when = new Date(st.last).toLocaleString(((window.OC && OC.getLanguage && OC.getLanguage()) || 'fr').replace('_', '-'), { dateStyle: 'short', timeStyle: 'short' })
				body.appendChild(h('p', { class: 'wm-nc-copy-state' }, (st.count > 1 ? _('{count} contacts copiés', { count: st.count }) : _('{count} contact copié', { count: st.count })) + ' · ' + _('mise à jour : {when}', { when })))
			}
			body.appendChild(h('div', { class: 'wm-modal-actions' },
				st.enabled ? h('button', { type: 'button', class: 'wm-nc-copy-remove', disabled: busy, onclick: remove }, _('Arrêter et retirer le carnet')) : null,
				st.enabled ? h('a', { class: 'button', href: contactsUrl, target: '_blank', rel: 'noopener' }, _('Ouvrir Contacts')) : null,
				h('button', { type: 'button', class: 'primary wm-nc-copy-run', disabled: busy, onclick: run }, busy ? _('Copie en cours…') : (st.enabled ? _('Mettre à jour maintenant') : _('Copier mes contacts')))))
		}
		let state = { enabled: false }
		const run = async () => {
			render(state, true)
			try {
				const r = await api(base, { method: 'POST' })
				state = r
				const parts = []
				if (r.created) parts.push(r.created > 1 ? _('{count} ajoutés', { count: r.created }) : _('{count} ajouté', { count: r.created }))
				if (r.updated) parts.push(r.updated > 1 ? _('{count} mis à jour', { count: r.updated }) : _('{count} mis à jour', { count: r.updated }))
				if (r.deleted) parts.push(r.deleted > 1 ? _('{count} retirés', { count: r.deleted }) : _('{count} retiré', { count: r.deleted }))
				core.toast(_('Nextcloud Contacts à jour') + (parts.length ? ' : ' + parts.join(', ') : ''))
			} catch (e) { core.toast(e.message, true) }
			render(state, false)
		}
		const remove = async () => {
			render(state, true)
			try {
				state = await api(base + '/remove', { method: 'POST' })
				core.toast(_('Carnet retiré de Nextcloud Contacts'))
			} catch (e) { core.toast(e.message, true) }
			render(state, false)
		}
		try {
			state = await api(base)
			render(state, false)
			const b = body.querySelector('.wm-nc-copy-run')
			if (b) b.focus()
		} catch (e) {
			close()
			core.toast(e.message, true)
		}
	}

	/**
	 * « Doublons »: the contacts that look like the same person (an address in
	 * common, or the same name), merged into the card chosen; nothing is lost
	 * (what does not fit goes into its notes).
	 */
	async function openDuplicates(book) {
		const h = core.h
		const name = book.folderName || book.name
		const title = _('Doublons dans « {name} »', { name })
		const body = h('div', { class: 'wm-dup-body' }, h('span', { class: 'icon-loading' }))
		const close = () => { overlay.remove(); document.removeEventListener('keydown', onKey) }
		const onKey = (e) => { if (e.key === 'Escape') close() }
		const overlay = h('div', { class: 'wm-overlay', onmousedown: (e) => { if (e.target === overlay) close() } },
			h('div', { class: 'wm-modal wm-dup-dialog', role: 'dialog', 'aria-modal': 'true', 'aria-label': title },
				h('div', { class: 'wm-modal-header' }, h('h2', {}, title), h('button', { class: 'wm-modal-close', 'aria-label': _('Fermer'), onclick: close }, '✕')),
				body))
		document.addEventListener('keydown', onKey)
		document.body.appendChild(overlay)
		let groups
		try {
			groups = (await api('/accounts/' + core.accountId + '/contacts/duplicates', { query: { book: book.key } })).groups
		} catch (e) { close(); core.toast(e.message, true); return }
		const render = () => {
			core.clear(body)
			if (!groups.length) {
				body.appendChild(h('p', { class: 'wm-empty' }, _('Aucun doublon trouvé : chaque personne n\'a qu\'une fiche.')))
				return
			}
			body.appendChild(h('p', { class: 'wm-muted' }, _('Pour chaque groupe, choisissez la fiche à garder : elle est complétée par les autres (adresses, numéros, société…), qui sont ensuite supprimées. Ce qui ne trouve pas de place va dans ses notes.')))
			groups.forEach((g, gi) => {
				// the fullest card is kept by default
				const score = (c) => c.emails.length * 2 + (c.company ? 1 : 0) + (c.phone ? 1 : 0)
				const best = g.reduce((a, c) => (score(c) > score(a) ? c : a), g[0])
				const radios = []
				const ticks = []
				const box = h('div', { class: 'wm-dup-group', dataset: { group: String(gi) } },
					h('ul', {}, g.map((c) => {
						const radio = h('input', { type: 'radio', name: 'keep' + gi, value: c.id, checked: c === best, 'aria-label': _('Garder la fiche de {name}', { name: c.displayName || c.emails[0] || '' }) })
						const tick = h('input', { type: 'checkbox', value: c.id, checked: true, 'aria-label': _('Fusionner {name}', { name: c.displayName || c.emails[0] || '' }) })
						radios.push(radio)
						ticks.push(tick)
						return h('li', { class: 'wm-dup-item' },
							h('label', { class: 'wm-dup-keep', title: _('La fiche gardée') }, radio, ' ', _('garder')),
							tick,
							h('span', { class: 'wm-dup-name' }, c.displayName || _('(sans nom)')),
							h('span', { class: 'wm-muted' }, [c.emails.join(', '), c.company, c.phone].filter(Boolean).join(' · ')))
					})),
					h('button', {
						type: 'button',
						class: 'primary wm-dup-merge',
						onclick: async (e) => {
							const keepId = radios.find((r) => r.checked).value
							const ids = ticks.filter((t) => t.checked && t.value !== keepId).map((t) => t.value)
							if (!ids.length) { core.toast(_('Cochez au moins une autre fiche à fusionner'), true); return }
							e.currentTarget.disabled = true
							try {
								await api('/accounts/' + core.accountId + '/contacts/duplicates/merge', { method: 'POST', body: { book: book.key, keepId, ids } })
								core.toast(_('{count} fiches fusionnées', { count: ids.length + 1 }))
								groups = groups.filter((x) => x !== g)
								render()
								load(true)
							} catch (err) { core.toast(err.message, true); e.currentTarget.disabled = false }
						},
					}, _('Fusionner')))
				body.appendChild(box)
			})
		}
		render()
	}

	/** Add the cards of a .vcf file to the book shown. */
	function importVcf(book) {
		const input = document.createElement('input')
		input.type = 'file'
		input.accept = '.vcf,text/vcard,text/x-vcard'
		input.className = 'wm-vcf-input'
		input.hidden = true
		document.body.appendChild(input)
		input.addEventListener('change', async () => {
			const file = input.files[0]
			input.remove()
			if (!file) return
			const fd = new FormData()
			fd.append('file', file, file.name)
			fd.append('book', book.key)
			core.toast(_('Import de « {name} »…', { name: file.name }))
			try {
				const r = await api('/accounts/' + core.accountId + '/contacts/import', { method: 'POST', body: fd })
				const into = { count: r.created, name: book.folderName || book.name }
				const parts = [r.created > 1 ? _('{count} contacts importés dans « {name} »', into) : _('{count} contact importé dans « {name} »', into)]
				if (r.skipped) parts.push(r.skipped > 1 ? _('{count} déjà présents (même adresse e-mail)', { count: r.skipped }) : _('{count} déjà présent (même adresse e-mail)', { count: r.skipped }))
				if (r.failed.length) {
					const list = r.failed.slice(0, 3).map((f) => f.name + ' (' + f.error + ')').join(', ')
					parts.push(r.failed.length > 1 ? _('{count} refusés : {list}', { count: r.failed.length, list }) : _('{count} refusé : {list}', { count: r.failed.length, list }))
				}
				core.toast(parts.join(_(' ; ')), r.failed.length > 0 && !r.created)
				load(true)
			} catch (e) {
				core.toast(e.message, true)
			}
		})
		input.click()
	}

	/** Outlook's year for a birthday without a year. */
	const NO_YEAR = '1604'
	/** In how many days a birthday « 1980-05-12 » comes (0: today), and the age then (null without a year). */
	function nextBirthday(date) {
		const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date || '')
		if (!m) return null
		const now = new Date()
		const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
		// (29 February: the 1st of March in other years)
		const on = (y) => new Date(y, +m[2] - 1, +m[3])
		let next = on(today.getFullYear())
		if (next < today) next = on(today.getFullYear() + 1)
		const days = Math.round((next - today) / 86400000)
		return { days, age: m[1] === NO_YEAR ? null : next.getFullYear() - +m[1] }
	}
	/** « 12 mai 1980 (46 ans) », « 12 mai » without a year. */
	function birthdayLabel(date) {
		const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date || '')
		if (!m) return null
		const d = new Date(+m[1] === +NO_YEAR ? 2000 : +m[1], +m[2] - 1, +m[3])
		const opts = m[1] === NO_YEAR ? { day: 'numeric', month: 'long' } : { day: 'numeric', month: 'long', year: 'numeric' }
		const text = d.toLocaleDateString(((window.OC && OC.getLanguage && OC.getLanguage()) || 'fr').replace('_', '-'), opts)
		const n = nextBirthday(date)
		if (!n || n.age === null) return text
		// the age now: the next one minus one, unless it is today
		const age = n.days === 0 ? n.age : n.age - 1
		return text + ' ' + (age > 1 ? _('({age} ans)', { age }) : _('({age} an)', { age }))
	}
	/** « aujourd'hui », « demain », « dans 5 jours » */
	const inDays = (days) => days === 0 ? _('aujourd\'hui') : days === 1 ? _('demain') : _('dans {days} jours', { days })
	/** Upcoming birthdays shown above the list. */
	const BIRTHDAY_DAYS = 14

	function renderList() {
		if (!core || core.state.module !== 'contacts') return
		const h = core.h
		const el = core.listEl
		// (a search being typed, not sent yet, outlives a new drawing of the list)
		const typing = el.querySelector('input.wm-search')
		const typed = typing && typing.value !== ct.query ? { value: typing.value, focused: document.activeElement === typing, start: typing.selectionStart, end: typing.selectionEnd } : null
		core.clear(el)
		const book = currentBook()
		const isDirectory = book.kind === 'directory'
		const search = h('input', {
			type: 'search',
			class: 'wm-search',
			placeholder: isDirectory ? _('Chercher dans l\'annuaire (nom, adresse…)') : _('Rechercher dans {name}', { name: book.kind === 'shared' && book.folderName ? book.folderName : book.name }),
			value: ct.query,
			'aria-label': _('Rechercher un contact'),
		})
		el.appendChild(h('form', {
			class: 'wm-list-header',
			onsubmit: (e) => { e.preventDefault(); ct.query = search.value.trim(); if (!ct.query) ct.everywhere = false; load(true) },
		},
		h('button', { type: 'button', class: 'wm-back wm-mobile-only', 'aria-label': _('Menu'), onclick: () => core.setView('nav') }, '☰'),
		search,
		isDirectory ? null : h('a', {
			class: 'button wm-icon-btn wm-vcf-export',
			href: OC.generateUrl('/apps/webmail/api/accounts/' + core.accountId + '/contacts/export') + '?' + new URLSearchParams({ book: ct.book, name: book.folderName || book.name }),
			download: '',
			title: _('Exporter ce carnet (fichier vCard .vcf, pour Outlook, un téléphone, Nextcloud Contacts…)'),
			'aria-label': _('Exporter ce carnet'),
		}, '⬇\uFE0E'),
		book.key === 'contacts' ? h('button', { type: 'button', class: 'wm-icon-btn wm-nc-copy', title: _('Copier ces contacts dans Nextcloud Contacts (téléphones, autres applications)'), 'aria-label': _('Copier dans Nextcloud Contacts'), onclick: () => openCopy() }, '☁\uFE0E') : null,
		book.kind === 'own' && !isDirectory ? h('button', { type: 'button', class: 'wm-icon-btn wm-dup-btn', title: _('Chercher les doublons de ce carnet et les fusionner'), 'aria-label': _('Doublons'), onclick: () => openDuplicates(book) }, '👥\uFE0E') : null,
		book.canEdit && !isDirectory ? h('button', { type: 'button', class: 'wm-icon-btn wm-vcf-import', title: _('Importer des contacts (fichier vCard .vcf)'), 'aria-label': _('Importer des contacts'), onclick: () => importVcf(book) }, '⬆\uFE0E') : null,
		h('button', { type: 'button', class: 'wm-icon-btn primary wm-mobile-only', 'aria-label': _('Nouveau contact'), onclick: () => openForm(null) }, '＋')))
		if (typed) {
			search.value = typed.value
			if (typed.focused) { search.focus(); try { search.setSelectionRange(typed.start, typed.end) } catch (e) {} }
		}

		if (ct.query) {
			const everywhere = (on) => (e) => { e.preventDefault(); ct.everywhere = on; load(true) }
			el.appendChild(h('div', { class: 'wm-search-info' },
				ct.everywhere ? _('Résultats pour « {query} » dans tous les carnets', { query: ct.query }) : _('Résultats pour « {query} »', { query: ct.query }),
				' ',
				h('a', { href: '#', onclick: (e) => { e.preventDefault(); ct.query = ''; ct.everywhere = false; load(true) } }, _('effacer')),
				ct.query.length >= 2 ? ' · ' : null,
				ct.query.length >= 2 ? h('a', { href: '#', class: 'wm-search-everywhere', onclick: everywhere(!ct.everywhere) },
					ct.everywhere ? _('seulement dans {name}', { name: bookTitle(book) }) : '🔎 ' + _('chercher dans tous les carnets')) : null))
			if (ct.everywhere && ct.failed.length) {
				el.appendChild(h('div', { class: 'wm-search-info wm-muted' }, _('Non consultés : {books}', { books: ct.failed.join(', ') })))
			}
		}
		if (ct.loading && ct.items.length === 0) {
			el.appendChild(h('div', { class: 'wm-center' }, h('span', { class: 'icon-loading' })))
			return
		}
		if (ct.items.length === 0) {
			el.appendChild(h('div', { class: 'wm-empty' }, isDirectory && ct.query.length < 2
				? _('Tapez au moins 2 lettres pour chercher dans l\'annuaire de l\'entreprise.')
				: (ct.query ? _('Aucun contact trouvé') : _('Aucun contact'))))
			if (isDirectory) setTimeout(() => search.focus(), 0)
			return
		}
		if (isDirectory && ct.items.length >= 100) {
			el.appendChild(h('div', { class: 'wm-search-info' }, _('Exchange limite la recherche à 100 résultats : précisez votre recherche.')))
		}
		// the birthdays of the next days (among the contacts loaded), soonest first
		const soon = ct.query || isDirectory ? [] : ct.items
			.map((c) => ({ c, n: nextBirthday(c.birthday) }))
			.filter((x) => x.n && x.n.days <= BIRTHDAY_DAYS)
			.sort((a, b) => a.n.days - b.n.days)
		if (soon.length) {
			el.appendChild(h('div', { class: 'wm-birthdays', role: 'region', 'aria-label': _('Anniversaires à venir') },
				h('div', { class: 'wm-birthdays-title' }, '🎂 ' + _('Anniversaires à venir')),
				h('ul', {}, soon.map(({ c, n }) => h('li', {},
					h('a', { href: '#', onclick: (e) => { e.preventDefault(); openContact(c.id) } }, c.displayName || _('(sans nom)')),
					' ',
					h('span', { class: n.days === 0 ? 'wm-birthday-today' : 'wm-muted' }, inDays(n.days) + (n.age !== null ? ' · ' + (n.age > 1 ? _('{age} ans', { age: n.age }) : _('{age} an', { age: n.age })) : '')))))))
		}
		const ul = h('ul', { class: 'wm-messages wm-contacts', role: 'list' })
		let letter = null
		ct.items.forEach((c) => {
			const l = (c.displayName || '#')[0].toLocaleUpperCase('fr-FR').normalize('NFD').replace(/[̀-ͯ]/g, '')
			if ((!ct.query || isDirectory) && l !== letter) {
				letter = l
				ul.appendChild(h('li', { class: 'wm-letter', 'aria-hidden': 'true' }, l))
			}
			// a result of the search in every book: opened in its own book
			const open = () => {
				if (ct.everywhere && c.book && c.book !== ct.book) {
					ct.book = c.book
					core.renderNav()
				}
				openContact(c.id)
			}
			const from = ct.everywhere && c.book ? ct.books.find((b) => b.key === c.book) : null
			ul.appendChild(h('li', {
				class: 'wm-contact' + (c.id === ct.selectedId ? ' selected' : ''),
				tabindex: '0',
				dataset: { id: c.id, book: c.book || '' },
				onclick: open,
				onkeydown: (e) => { if (e.key === 'Enter') open() },
			},
			avatarWithPresence(c),
			h('div', { class: 'wm-contact-text' },
				h('div', { class: 'wm-contact-name' }, c.displayName || _('(sans nom)'), c.isGroup ? h('span', { class: 'wm-muted' }, ' · ' + _('groupe')) : null),
				h('div', { class: 'wm-message-preview' }, [c.email, c.company].filter(Boolean).join(' · ') || c.phone || ''),
				from || (ct.everywhere && c.bookName) ? h('div', { class: 'wm-contact-book wm-muted' }, '📇 ' + (from ? bookTitle(from) : c.bookName)) : null)))
		})
		el.appendChild(ul)
		if (ct.hasMore) {
			el.appendChild(h('div', { class: 'wm-center' },
				h('button', { onclick: () => load(false) }, _('Charger plus ({shown} / {total})', { shown: ct.items.length, total: ct.total }))))
		}
	}

	function renderEmpty() {
		ct.detailSeq++
		const h = core.h
		core.clear(core.detailEl)
		core.detailEl.appendChild(h('div', { class: 'wm-empty wm-empty-detail' }, h('div', { class: 'wm-empty-icon' }, '👤'), _('Sélectionnez un contact')))
	}

	async function openContact(id) {
		const h = core.h
		const mine = ++ct.detailSeq
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
				: await api('/accounts/' + core.accountId + '/contact', { query: { contactId: id, book: book.key } })
			if (!c) throw new Error(_('Contact introuvable'))
		} catch (e) {
			if (mine !== ct.detailSeq) return
			core.clear(el)
			el.appendChild(h('div', { class: 'wm-empty' }, e.message))
			return
		}
		// another contact, or a form, opened meanwhile (« Nouveau contact » right after « Enregistrer »)
		if (ct.selectedId !== id || mine !== ct.detailSeq) return
		core.clear(el)

		const row = (icon, label, value) => value ? h('div', { class: 'wm-contact-row' }, h('span', { class: 'wm-contact-icon', 'aria-hidden': 'true' }, icon),
			h('span', { class: 'wm-muted wm-contact-label' }, label), value) : null
		const tel = (v) => v ? h('a', { href: 'tel:' + v.replace(/[^+\d]/g, '') }, v) : null
		const address = [c.street, [c.postalCode, c.city].filter(Boolean).join(' '), c.country].filter(Boolean).join(', ')
		const writeTo = (email) => core.openCompose('new', null, { to: (c.displayName ? c.displayName + ' <' + email + '>' : email) + ', ' })
		const members = (c.members || [])
		const writeToGroup = () => core.openCompose('new', null, { to: members.filter((m) => m.email).map((m) => (m.name && m.name !== m.email ? m.name + ' <' + m.email + '>' : m.email)).join(', ') + ', ' })

		el.appendChild(h('div', { class: 'wm-toolbar' },
			h('button', { class: 'wm-back wm-mobile-only', 'aria-label': _('Retour'), onclick: () => core.setView('list') }, '←'),
			c.emails.length ? h('button', { class: 'primary', onclick: () => writeTo(c.emails[0]) }, '✉️ ' + _('Écrire')) : null,
			c.matrix ? h('a', { class: 'button wm-matrix-btn', href: 'https://matrix.to/#/' + c.matrix, target: '_blank', rel: 'noopener noreferrer' }, '💬 ' + _('Écrire sur Matrix')) : null,
			c.isGroup && members.some((m) => m.email) ? h('button', { class: 'primary', onclick: writeToGroup }, '✉️ ' + _('Écrire au groupe')) : null,
			!book.canEdit ? null : h('button', { onclick: () => (c.isGroup ? openGroupForm(c) : openForm(c)) }, '✏️ ' + _('Modifier')),
			book.canEdit ? h('button', { class: 'wm-danger wm-delete-action', onclick: () => remove(c) }, _('Supprimer')) : null,
			!book.canEdit && !c.isGroup ? h('button', { onclick: () => copyToMine(c) }, '＋ ' + _('Ajouter à mes contacts')) : null,
			!book.canEdit ? h('span', { class: 'wm-muted' }, book.kind === 'directory' ? '🏢 ' + _('Annuaire de l\'entreprise') : '🔒 ' + _('{name} (lecture seule)', { name: bookTitle(book) })) : null))

		el.appendChild(h('div', { class: 'wm-contact-card' },
			h('div', { class: 'wm-contact-head' }, avatarWithPresence(c),
				h('div', {},
					h('h2', { class: 'wm-subject' }, c.displayName || _('(sans nom)')),
					h('div', { class: 'wm-muted' }, [c.jobTitle, c.department, c.company].filter(Boolean).join(' · ')))),
			c.emails.map((e, i) => row('✉️', i === 0 ? _('E-mail') : _('E-mail {n}', { n: i + 1 }),
				h('a', { href: '#', onclick: (ev) => { ev.preventDefault(); writeTo(e) } }, e))),
			row('📱', _('Mobile'), tel(c.mobilePhone)),
			row('☎️', _('Bureau'), tel(c.businessPhone)),
			row('🏠', _('Domicile'), tel(c.homePhone)),
			row('🏢', _('Site'), c.office || null),
			row('🎂', _('Anniversaire'), birthdayLabel(c.birthday)),
			// Matrix (Element…): matrix.to opens the conversation in the app chosen by the person
			row('💬', _('Matrix'), c.matrix ? h('a', { class: 'wm-matrix-link', href: 'https://matrix.to/#/' + c.matrix, target: '_blank', rel: 'noopener noreferrer', title: _('Écrire sur Matrix (Element)') }, c.matrix) : null),
			row('📍', _('Adresse'), address ? h('a', { href: 'https://www.openstreetmap.org/search?query=' + encodeURIComponent(address), target: '_blank', rel: 'noopener noreferrer' }, address) : null),
			(c.notes || '').trim() ? h('div', { class: 'wm-event-body' }, c.notes.trim()) : null,
			c.isGroup ? h('div', { class: 'wm-group-members' },
				h('strong', {}, members.length > 1 ? _('{count} membres', { count: members.length }) : _('{count} membre', { count: members.length })),
				h('ul', { class: 'wm-attendees' }, members.map((m) => h('li', {}, m.email
					? h('a', { href: '#', onclick: (ev) => { ev.preventDefault(); core.openCompose('new', null, { to: (m.name !== m.email ? m.name + ' <' + m.email + '>' : m.email) + ', ' }) } },
						m.name !== m.email ? m.name + ' <' + m.email + '>' : m.email)
					: h('span', { class: 'wm-muted', title: _('Adresse interne d\'Exchange') }, m.name))))) : null))		// a contact outside the list (« Mes contacts » of a message…): its status asked now
		if (presenceEmail(c) && !(presenceEmail(c) in ct.presence)) refreshPresence()
	}

	/**
	 * A contact group (Outlook distribution list): a name and members,
	 * added from the contacts and the directory (completion) or by address.
	 */
	function openGroupForm(g) {
		ct.detailSeq++
		const h = core.h
		const isNew = !g
		const members = ((g && g.members) || []).map((m) => ({ name: m.name, email: m.email }))
		core.setView('detail')
		const el = core.detailEl
		core.clear(el)
		const name = h('input', { type: 'text', name: 'groupName', required: true, value: g ? g.displayName : '', placeholder: _('Équipe chantier, Clients VIP…') })
		const who = h('input', { type: 'text', name: 'groupMember', placeholder: _('Nom ou adresse (complétion)'), autocomplete: 'off' })
		const list = h('ul', { class: 'wm-group-edit' })
		const err = h('div', { class: 'wm-form-error', role: 'alert' })
		const save = h('button', { type: 'submit', class: 'primary' }, isNew ? _('Créer le groupe') : _('Enregistrer'))
		// (the groups are Outlook's distribution lists: in an Exchange book)
		const writable = ct.books.filter((b) => b.canEdit && b.kind !== 'nextcloud')
		const bookSelect = isNew && writable.length > 1
			? h('select', { name: 'book', 'aria-label': _('Carnet') }, writable.map((b) => h('option', { value: b.key, selected: b.key === (currentBook().canEdit && currentBook().kind !== 'nextcloud' ? ct.book : 'contacts') }, bookTitle(b))))
			: null
		const render = () => {
			core.clear(list)
			if (!members.length) list.appendChild(h('li', { class: 'wm-muted' }, _('Aucun membre pour l\'instant')))
			members.forEach((m, i) => list.appendChild(h('li', { dataset: { email: m.email } },
				m.name && m.name !== m.email ? m.name + ' <' + m.email + '>' : (m.email || m.name), ' ',
				h('button', { type: 'button', class: 'wm-small wm-icon-btn', 'aria-label': _('Retirer {name}', { name: m.email || m.name }), onclick: () => { members.splice(i, 1); render() } }, '✕'))))
		}
		/** "Jean Dupont <jean@x.fr>, paul@x.fr" → members */
		const addFromInput = () => {
			const bad = []
			who.value.split(/[,;\n]/).map((x) => x.trim()).filter(Boolean).forEach((part) => {
				const m = part.match(/^(.*?)\s*<([^>]+)>$/)
				const email = (m ? m[2] : part).trim().toLowerCase()
				const label = m ? m[1].replace(/^"|"$/g, '').trim() : ''
				if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { bad.push(part); return }
				if (!members.some((x) => x.email === email)) members.push({ name: label || email, email })
			})
			who.value = bad.join(', ')
			err.textContent = bad.length ? _('Adresse invalide : {list}', { list: bad.join(', ') }) : ''
			render()
		}
		who.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); addFromInput() } })
		render()
		el.appendChild(h('form', {
			class: 'wm-compose wm-contact-form wm-group-form',
			onsubmit: async (e) => {
				e.preventDefault()
				if (who.value.trim()) addFromInput()
				if (err.textContent) return
				save.disabled = true
				const data = { isGroup: true, displayName: name.value, members }
				try {
					let id = g && g.id
					if (isNew) {
						const target = bookSelect ? bookSelect.value : 'contacts'
						id = (await api('/accounts/' + core.accountId + '/contacts', { method: 'POST', body: { contact: data, book: target } })).id
						if (target !== ct.book) { ct.book = target; core.renderNav() }
					} else {
						await api('/accounts/' + core.accountId + '/contacts/update', { method: 'POST', body: { contactId: g.id, contact: data, book: ct.book } })
					}
					core.toast(isNew ? _('Groupe créé') : _('Groupe enregistré'))
					await load(true)
					openContact(id)
				} catch (ex) {
					err.textContent = ex.message
					save.disabled = false
				}
			},
		},
		h('div', { class: 'wm-compose-title' },
			h('button', { type: 'button', class: 'wm-back wm-mobile-only', 'aria-label': _('Retour'), onclick: () => core.setView('list') }, '←'),
			h('h2', {}, isNew ? _('Nouveau groupe') : _('Modifier le groupe'))),
		bookSelect ? h('label', { class: 'wm-field' }, h('span', {}, _('Carnet')), bookSelect) : null,
		h('label', { class: 'wm-field' }, h('span', {}, _('Nom du groupe')), name),
		h('div', { class: 'wm-field' }, h('span', {}, _('Ajouter')), core.attachAutocomplete(who),
			h('button', { type: 'button', class: 'wm-small', onclick: addFromInput }, _('Ajouter'))),
		h('div', { class: 'wm-field wm-field-top' }, h('span', {}, _('Membres')), list),
		err,
		h('div', { class: 'wm-compose-actions' }, save,
			h('button', { type: 'button', onclick: () => (isNew ? (renderEmpty(), core.setView('list')) : openContact(g.id)) }, _('Annuler')))))
		name.focus()
	}

	/** Copy a read-only card (directory, shared) into the user's own contacts. */
	async function copyToMine(c) {
		const data = {}
		;['givenName', 'surname', 'displayName', 'company', 'department', 'jobTitle', 'mobilePhone', 'businessPhone', 'homePhone',
			'street', 'postalCode', 'city', 'country', 'notes', 'matrix', 'birthday'].forEach((k) => { data[k] = c[k] || '' })
		data.emails = c.emails || []
		try {
			await api('/accounts/' + core.accountId + '/contacts', { method: 'POST', body: { contact: data, book: 'contacts' } })
			core.toast(_('{name} ajouté à vos contacts', { name: c.displayName || _('Contact') }))
		} catch (e) { core.toast(e.message, true) }
	}

	async function remove(c) {
		const isPublic = currentBook().kind === 'public'
		const name = { name: c.displayName || '' }
		if (!window.confirm(isPublic
			? _('Supprimer définitivement le contact « {name} » du dossier public ? Il sera supprimé pour tout le monde.', name)
			: _('Supprimer le contact « {name} » ?', name))) return
		try {
			await api('/accounts/' + core.accountId + '/contacts/delete', { method: 'POST', body: { contactId: c.id, book: ct.book } })
			core.toast(_('Contact supprimé'))
			ct.items = ct.items.filter((x) => x.id !== c.id)
			ct.selectedId = null
			renderList()
			renderEmpty()
			core.setView('list')
		} catch (e) { core.toast(e.message, true) }
	}

	function openForm(c) {
		ct.detailSeq++
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
			givenName: input('givenName', _('Prénom')),
			surname: input('surname', _('Nom de famille')),
			displayName: input('displayName', _('Nom affiché')),
			company: input('company', _('Société')),
			department: input('department', _('Service')),
			jobTitle: input('jobTitle', _('Fonction')),
			mobilePhone: input('mobilePhone', _('Mobile'), 'tel'),
			businessPhone: input('businessPhone', _('Tél. bureau'), 'tel'),
			homePhone: input('homePhone', _('Tél. domicile'), 'tel'),
			matrix: input('matrix', _('Matrix')),
			birthday: input('birthday', _('Anniversaire'), 'date'),
			street: input('street', _('Rue')),
			postalCode: input('postalCode', _('Code postal')),
			city: input('city', _('Ville')),
			country: input('country', _('Pays')),
		}
		f.displayName.i.placeholder = _('Calculé à partir du prénom et du nom')
		f.matrix.i.placeholder = '@prenom.nom:matrix.example.org'
		f.birthday.i.max = new Date().toISOString().slice(0, 10)
		const emails = [0, 1, 2].map((i) => h('input', { type: 'email', name: 'email' + (i + 1), value: c.emails[i] || '' }))
		const notes = h('textarea', { name: 'notes', rows: '4', placeholder: _('Notes') })
		notes.value = c.notes || ''
		// the photo: changed with the contact (undefined: unchanged, '': removed, data: URL: new)
		let pendingPhoto
		const photoBox = h('span', { class: 'wm-photo-preview' })
		const showPhoto = () => {
			core.clear(photoBox)
			const url = pendingPhoto === undefined ? (isNew ? null : photoUrl(c)) : pendingPhoto || null
			photoBox.appendChild(avatar(c.displayName || '?', url))
			removePhoto.hidden = !url
		}
		const photoInput = h('input', { type: 'file', accept: 'image/jpeg,image/png,image/gif,image/webp', name: 'photo', hidden: true })
		photoInput.addEventListener('change', async () => {
			const file = photoInput.files[0]
			photoInput.value = ''
			if (!file) return
			try { pendingPhoto = await photoDataUrl(file); showPhoto() } catch (e) { core.toast(e.message, true) }
		})
		const removePhoto = h('button', { type: 'button', class: 'wm-small', onclick: () => { pendingPhoto = ''; showPhoto() } }, _('Retirer la photo'))
		const photoRow = h('div', { class: 'wm-field wm-photo-field' }, h('span', {}, _('Photo')), photoBox,
			h('button', { type: 'button', class: 'wm-small', onclick: () => photoInput.click() }, '📷 ' + _('Choisir une photo')), removePhoto, photoInput)
		const err = h('div', { class: 'wm-form-error', role: 'alert' })
		const save = h('button', { type: 'submit', class: 'primary' }, isNew ? _('Créer le contact') : _('Enregistrer'))
		const writable = ct.books.filter((b) => b.canEdit)
		const bookSelect = isNew && writable.length > 1
			? h('select', { name: 'book', 'aria-label': _('Carnet') }, writable.map((b) => h('option', { value: b.key, selected: b.key === (currentBook().canEdit ? ct.book : 'contacts') },
				bookTitle(b))))
			: null

		el.appendChild(h('form', {
			class: 'wm-compose wm-contact-form',
			onsubmit: async (e) => {
				e.preventDefault()
				err.textContent = ''
				const data = { emails: emails.map((i) => i.value), notes: notes.value }
				Object.entries(f).forEach(([k, v]) => { data[k] = v.i.value })
				save.disabled = true
				// (the form shown now: the card opens after saving only if nothing else was opened meanwhile)
				const formSeq = ct.detailSeq
				try {
					let id = c.id
					if (isNew) {
						const target = bookSelect ? bookSelect.value : 'contacts'
						id = (await api('/accounts/' + core.accountId + '/contacts', { method: 'POST', body: { contact: data, book: target } })).id
						if (target !== ct.book) {
							ct.book = target
							core.renderNav()
						}
					} else {
						await api('/accounts/' + core.accountId + '/contacts/update', { method: 'POST', body: { contactId: c.id, contact: data, book: ct.book } })
					}
					if (pendingPhoto !== undefined) {
						await api('/accounts/' + core.accountId + '/contacts/photo', { method: 'POST', body: { contactId: id, photo: pendingPhoto, book: ct.book } })
						ct.photoV[id] = Date.now()
					}
					core.toast(isNew ? _('Contact créé') : _('Contact enregistré'))
					await load(true)
					if (ct.detailSeq === formSeq) openContact(id)
				} catch (ex) {
					err.textContent = ex.message
					save.disabled = false
				}
			},
		},
		h('div', { class: 'wm-compose-title' },
			h('button', { type: 'button', class: 'wm-back wm-mobile-only', 'aria-label': _('Retour'), onclick: () => core.setView('list') }, '←'),
			h('h2', {}, isNew ? _('Nouveau contact') : _('Modifier le contact'))),
		bookSelect ? h('label', { class: 'wm-field' }, h('span', {}, _('Carnet')), bookSelect) : null,
		photoRow,
		f.givenName.el, f.surname.el, f.displayName.el, f.company.el, f.department.el, f.jobTitle.el,
		emails.map((i, n) => h('label', { class: 'wm-field' }, h('span', {}, n === 0 ? _('E-mail') : _('E-mail {n}', { n: n + 1 })), i)),
		f.mobilePhone.el, f.businessPhone.el, f.homePhone.el, f.matrix.el, f.birthday.el,
		f.street.el, f.postalCode.el, f.city.el, f.country.el,
		notes,
		err,
		h('div', { class: 'wm-compose-actions' }, save,
			h('button', { type: 'button', onclick: () => (isNew ? (renderEmpty(), core.setView('list')) : openContact(c.id)) }, _('Annuler')))))
		showPhoto()
		f.givenName.i.focus()
	}

	window.WebmailModules = window.WebmailModules || {}
	window.WebmailModules.contacts = {
		/** Loaded ahead, while the mail is shown and the browser is idle: the books and the first contacts. */
		async prefetch(c) {
			core = c
			if (ct.booksFor !== core.accountId) {
				ct.books = []
				ct.book = 'contacts'
			}
			await loadBooks(false, true)
			const accountId = core.accountId
			const key = accountId + '|' + ct.book
			if (listCache.has(key) || ct.book === 'directory') return
			const data = await core.api('/accounts/' + accountId + '/contacts', { query: { offset: 0, limit: PAGE, q: '', book: ct.book } })
			if (accountId === core.accountId) listCache.set(key, { items: data.items, total: data.total, hasMore: data.hasMore })
		},
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
	window.WebmailPublicPicker = publicPicker
})()
