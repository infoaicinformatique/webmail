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

	/** "account|book" -> { items, total, hasMore }: a book seen before shows at once, then is refreshed */
	const listCache = new Map()

	function api(path, opts) {
		if (opts && opts.method && opts.method !== 'GET') listCache.clear()
		return core.api(path, opts)
	}

	function currentBook() {
		return ct.books.find((b) => b.key === ct.book) || { key: 'contacts', name: 'Contacts', kind: 'own', canEdit: true }
	}

	async function loadBooks(force) {
		if (!force && ct.booksFor === core.accountId && ct.books.length) return
		const accountId = core.accountId
		let books = [{ key: 'contacts', name: 'Contacts', kind: 'own', canEdit: true }]
		try {
			books = (await api('/accounts/' + accountId + '/contacts/books')).books
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
		const cacheKey = core.accountId + '|' + ct.book
		const cached = reset && !ct.query && listCache.get(cacheKey)
		if (cached) Object.assign(ct, { items: cached.items, total: cached.total, hasMore: cached.hasMore })
		else if (reset) { ct.items = []; ct.total = 0; ct.hasMore = false }
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

	/** "Contacts de Marie Martin", "« Clients VIP » de Marie Martin", or the book's own name */
	function bookTitle(b) {
		if (b.kind === 'public') return '« ' + b.name + ' » (dossiers publics)'
		if (b.kind !== 'shared') return b.name
		return (b.folderName ? '« ' + b.folderName + ' »' : 'Contacts') + ' de ' + b.name
	}

	function bookList() {
		const h = core.h
		const row = (b) => h('li', { class: 'wm-cal-row wm-book-row' + (b.key === ct.book ? ' active' : ''), dataset: { key: b.key } },
			h('a', {
				href: '#',
				class: 'wm-book-link',
				title: b.kind === 'public' ? 'Dossiers publics › ' + (b.path || b.name) : (b.email || b.name),
				onclick: (e) => { e.preventDefault(); selectBook(b.key) },
			},
			h('span', { class: 'wm-book-icon', 'aria-hidden': 'true' }, { directory: '🏢', shared: '👥', public: '🗂️' }[b.kind] || '📇'),
			h('span', { class: 'wm-cal-name' }, b.kind === 'shared' && b.folderName ? b.folderName + ' (' + b.name + ')' : b.name)),
			b.kind === 'shared' || b.kind === 'public' ? h('span', { class: 'wm-cal-mode' + (b.canEdit ? ' wm-cal-mode-edit' : '') }, b.canEdit ? 'modification' : 'lecture') : null,
			b.kind === 'own' ? h('button', {
				type: 'button',
				class: 'wm-cal-share',
				title: 'Partager ce carnet',
				'aria-label': 'Partager ' + b.name,
				onclick: () => openShare(b),
			}, '🔗') : null,
			b.kind === 'shared' ? h('button', {
				type: 'button',
				class: 'wm-cal-remove',
				title: 'Retirer ce carnet',
				'aria-label': 'Retirer ' + bookTitle(b),
				onclick: () => removeShared(b),
			}, '✕') : null,
			b.kind === 'public' ? h('button', {
				type: 'button',
				class: 'wm-cal-remove',
				title: 'Retirer ce carnet',
				'aria-label': 'Retirer ' + b.name,
				onclick: () => removePublic(b),
			}, '✕') : null)
		const own = ct.books.filter((b) => b.kind === 'own')
		const shared = ct.books.filter((b) => b.kind === 'shared')
		const publicBooks = ct.books.filter((b) => b.kind === 'public')
		const directory = ct.books.filter((b) => b.kind === 'directory')
		return h('div', { class: 'wm-cal-list' },
			h('div', { class: 'wm-cal-section' }, 'Mes carnets'),
			h('ul', {}, own.map(row)),
			h('div', { class: 'wm-cal-section' }, 'Contacts partagés'),
			shared.length ? h('ul', {}, shared.map(row)) : null,
			h('button', { type: 'button', class: 'wm-cal-add', onclick: openAddShared }, '＋ Ajouter les contacts d\'un collègue'),
			h('div', { class: 'wm-cal-section' }, 'Dossiers publics'),
			publicBooks.length ? h('ul', {}, publicBooks.map(row)) : null,
			h('button', { type: 'button', class: 'wm-cal-add wm-public-add', onclick: openAddPublic }, '＋ Ajouter un carnet des dossiers publics'),
			directory.length ? h('ul', { class: 'wm-directory' }, directory.map(row)) : null)
	}

	/**
	 * Share one of my address books without delegation (Outlook: right-click
	 * on the contacts folder > Properties > Permissions).
	 */
	function openShare(b) {
		const base = '/accounts/' + core.accountId + '/contacts/permissions'
		window.WebmailSharing.open(core, {
			title: 'Partager « ' + b.name + ' »',
			intro: 'Choisissez qui peut consulter ou modifier ce carnet d\'adresses, sans en faire un délégué. '
				+ 'C\'est le réglage « Autorisations » du dossier dans Outlook. Vos collègues l\'ajoutent ensuite avec « Ajouter les contacts d\'un collègue ». '
				+ 'Pour un carnet autre que « Contacts », donnez aussi le droit « Dossier visible » sur votre boîte (dans Outlook) ou rangez-le dans « Contacts ».',
			levels: [
				['None', 'Aucun accès'],
				['Reviewer', 'Lecture'],
				['Author', 'Lecture et ajout de contacts'],
				['Editor', 'Modification'],
			],
			newLevel: 'Reviewer',
			load: async () => (await api(base, { query: { book: b.key } })).permissions,
			save: (permissions) => api(base, { method: 'POST', body: { book: b.key, permissions } }),
			savedMessage: 'Partage de « ' + b.name + ' » enregistré',
		})
	}

	/**
	 * Add a colleague's address books: their « Contacts » and the other books
	 * they share. When they share several, the user ticks the ones to add.
	 */
	function openAddShared() {
		const h = core.h
		const base = '/accounts/' + core.accountId + '/contacts/shared'
		const email = h('input', { type: 'text', name: 'sharedEmail', required: true, placeholder: 'prenom.nom@exemple.fr' })
		const choices = h('div', { class: 'wm-shared-choices' })
		const err = h('div', { class: 'wm-form-error', role: 'alert' })
		const btn = h('button', { type: 'submit', class: 'primary' }, 'Ajouter')
		const overlay = h('div', { class: 'wm-overlay' })
		const close = () => overlay.remove()
		overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) close() })
		let offered = null // books found, once the colleague's address is checked
		const showChoices = (r) => {
			offered = r
			email.readOnly = true
			core.clear(choices)
			choices.appendChild(h('p', {}, r.name + ' vous partage ' + r.choices.length + ' carnets. Cochez ceux à ajouter :'))
			choices.appendChild(h('ul', { class: 'wm-choice-list' }, r.choices.map((b) => h('li', {},
				h('label', {},
					h('input', { type: 'checkbox', name: 'sharedFolder', value: b.folder, checked: b.added || b.folder === '', disabled: b.added }),
					' ' + (b.folderName || 'Contacts') + ' ',
					h('span', { class: 'wm-cal-mode' + (b.canEdit ? ' wm-cal-mode-edit' : '') }, b.canEdit ? 'modification' : 'lecture'),
					b.added ? h('span', { class: 'wm-muted' }, ' déjà ajouté') : null)))))
			btn.textContent = 'Ajouter les carnets cochés'
		}
		const form = h('form', {
			class: 'wm-event-form',
			onsubmit: async (e) => {
				e.preventDefault()
				err.textContent = ''
				const body = { email: email.value }
				if (offered) {
					body.folders = [...choices.querySelectorAll('input[name=sharedFolder]:checked:not(:disabled)')].map((c) => c.value)
					if (!body.folders.length) { err.textContent = 'Cochez au moins un carnet'; return }
				}
				const label = btn.textContent
				btn.disabled = true
				btn.textContent = offered ? 'Ajout…' : 'Recherche des carnets partagés…'
				try {
					const r = await api(base, { method: 'POST', body })
					btn.disabled = false
					btn.textContent = label
					if (r.choices) { showChoices(r); return }
					close()
					const one = r.added[0]
					const mode = (b) => (b.canEdit ? 'modification' : 'lecture')
					core.toast(r.added.length > 1
						? r.added.length + ' carnets de ' + r.name + ' ajoutés'
						: (one.folderName ? '« ' + one.folderName + ' » de ' + r.name + ' ajouté' : 'Contacts de ' + r.name + ' ajoutés') + ' (' + mode(one) + ')')
					await loadBooks(true)
					selectBook(one.key)
				} catch (ex) {
					err.textContent = ex.message
					btn.disabled = false
					btn.textContent = label
				}
			},
		},
		h('p', { class: 'wm-muted' }, 'Indiquez l\'adresse d\'un collègue qui vous a partagé un carnet d\'adresses dans Outlook '
			+ '(clic droit sur le carnet → Propriétés → Autorisations). Le niveau d\'accès, lecture ou modification, est détecté automatiquement. '
			+ 'Ses autres carnets apparaissent s\'ils sont rangés dans son dossier « Contacts » partagé, ou si sa boîte vous donne le droit « Dossier visible ».'),
		h('label', { class: 'wm-field' }, h('span', {}, 'Adresse'), core.attachAutocomplete(email)),
		choices,
		err,
		h('div', { class: 'wm-compose-actions' }, btn, h('button', { type: 'button', onclick: close }, 'Annuler')))
		overlay.appendChild(h('div', { class: 'wm-modal', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Ajouter des contacts partagés' },
			h('div', { class: 'wm-modal-header' }, h('h2', {}, 'Ajouter les contacts d\'un collègue'),
				h('button', { class: 'wm-modal-close', 'aria-label': 'Fermer', onclick: close }, '✕')),
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
			title: 'Ajouter un carnet des dossiers publics',
			intro: 'Les dossiers publics sont partagés par toute l\'organisation. Ouvrez les dossiers pour trouver les carnets d\'adresses ; '
				+ 'le niveau d\'accès, lecture ou modification, est celui défini par l\'administrateur Exchange.',
			icon: '📇',
			empty: 'Aucun carnet d\'adresses ici.',
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
		const crumbs = h('nav', { class: 'wm-pf-path', 'aria-label': 'Emplacement' })
		const list = h('ul', { class: 'wm-pf-list' })
		const err = h('div', { class: 'wm-form-error', role: 'alert' })
		const path = [{ id: '', name: 'Dossiers publics' }]
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
					f.isContacts ? h('span', { class: 'wm-cal-mode' + (f.canEdit ? ' wm-cal-mode-edit' : '') }, f.canEdit ? 'modification' : 'lecture') : null,
					f.isContacts
						? (f.added
							? h('span', { class: 'wm-muted' }, 'déjà ajouté')
							: h('button', {
								type: 'button',
								class: 'wm-pf-add',
								onclick: async (e) => {
									e.target.disabled = true
									try {
										const b = await core.api(base, { method: 'POST', body: { folder: f.id, path: where } })
										close()
										core.toast('« ' + b.name + ' » ajouté (' + (b.canEdit ? 'modification' : 'lecture') + ')')
										await opts.onAdded(b)
									} catch (ex) {
										err.textContent = ex.message
										e.target.disabled = false
									}
								},
							}, 'Ajouter'))
						: null))
			})
		}

		overlay.appendChild(h('div', { class: 'wm-modal', role: 'dialog', 'aria-modal': 'true', 'aria-label': opts.title },
			h('div', { class: 'wm-modal-header' }, h('h2', {}, opts.title),
				h('button', { class: 'wm-modal-close', 'aria-label': 'Fermer', onclick: close }, '✕')),
			h('div', { class: 'wm-event-form' },
				h('p', { class: 'wm-muted' }, opts.intro),
				crumbs, list, err,
				h('div', { class: 'wm-compose-actions' }, h('button', { type: 'button', onclick: close }, 'Fermer')))))
		document.body.appendChild(overlay)
		open()
	}

	async function removePublic(b) {
		if (!window.confirm('Retirer « ' + b.name + ' » de la liste ? (rien n\'est supprimé dans Exchange)')) return
		try {
			await api('/accounts/' + core.accountId + '/contacts/public/delete', { method: 'POST', body: { folder: b.folder } })
			core.toast('Carnet retiré')
			if (ct.book === b.key) ct.book = 'contacts'
			await loadBooks(true)
			selectBook(ct.book)
		} catch (e) { core.toast(e.message, true) }
	}

	async function removeShared(b) {
		if (!window.confirm('Retirer ' + bookTitle(b) + ' de la liste ? (rien n\'est supprimé dans Exchange)')) return
		try {
			await api('/accounts/' + core.accountId + '/contacts/shared/delete', { method: 'POST', body: { email: b.email, folder: b.folder || '' } })
			core.toast('Carnet retiré')
			if (ct.book === b.key) ct.book = 'contacts'
			await loadBooks(true)
			selectBook(ct.book)
		} catch (e) { core.toast(e.message, true) }
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
			core.toast('Import de « ' + file.name + ' »…')
			try {
				const r = await api('/accounts/' + core.accountId + '/contacts/import', { method: 'POST', body: fd })
				const parts = [r.created + ' contact' + (r.created > 1 ? 's importés' : ' importé') + ' dans « ' + (book.folderName || book.name) + ' »']
				if (r.skipped) parts.push(r.skipped + ' déjà présent' + (r.skipped > 1 ? 's' : '') + ' (même adresse e-mail)')
				if (r.failed.length) parts.push(r.failed.length + ' refusé' + (r.failed.length > 1 ? 's' : '') + ' : ' + r.failed.slice(0, 3).map((f) => f.name + ' (' + f.error + ')').join(', '))
				core.toast(parts.join(' ; '), r.failed.length > 0 && !r.created)
				load(true)
			} catch (e) {
				core.toast(e.message, true)
			}
		})
		input.click()
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
			placeholder: isDirectory ? 'Chercher dans l\'annuaire (nom, adresse…)' : 'Rechercher dans ' + (book.kind === 'shared' && book.folderName ? book.folderName : book.name),
			value: ct.query,
			'aria-label': 'Rechercher un contact',
		})
		el.appendChild(h('form', {
			class: 'wm-list-header',
			onsubmit: (e) => { e.preventDefault(); ct.query = search.value.trim(); load(true) },
		},
		h('button', { type: 'button', class: 'wm-back wm-mobile-only', 'aria-label': 'Menu', onclick: () => core.setView('nav') }, '☰'),
		search,
		isDirectory ? null : h('a', {
			class: 'button wm-icon-btn wm-vcf-export',
			href: OC.generateUrl('/apps/webmail/api/accounts/' + core.accountId + '/contacts/export') + '?' + new URLSearchParams({ book: ct.book, name: book.folderName || book.name }),
			download: '',
			title: 'Exporter ce carnet (fichier vCard .vcf, pour Outlook, un téléphone, Nextcloud Contacts…)',
			'aria-label': 'Exporter ce carnet',
		}, '⬇\uFE0E'),
		book.canEdit && !isDirectory ? h('button', { type: 'button', class: 'wm-icon-btn wm-vcf-import', title: 'Importer des contacts (fichier vCard .vcf)', 'aria-label': 'Importer des contacts', onclick: () => importVcf(book) }, '⬆\uFE0E') : null,
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
				: await api('/accounts/' + core.accountId + '/contact', { query: { contactId: id, book: book.key } })
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
			!book.canEdit ? h('span', { class: 'wm-muted' }, book.kind === 'directory' ? '🏢 Annuaire de l\'entreprise' : '🔒 ' + bookTitle(book) + ' (lecture seule)') : null))

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
			await api('/accounts/' + core.accountId + '/contacts', { method: 'POST', body: { contact: data, book: 'contacts' } })
			core.toast((c.displayName || 'Contact') + ' ajouté à vos contacts')
		} catch (e) { core.toast(e.message, true) }
	}

	async function remove(c) {
		const isPublic = currentBook().kind === 'public'
		if (!window.confirm('Supprimer ' + (isPublic ? 'définitivement ' : '') + 'le contact « ' + (c.displayName || '') + ' »'
			+ (isPublic ? ' du dossier public ? Il sera supprimé pour tout le monde.' : ' ?'))) return
		try {
			await api('/accounts/' + core.accountId + '/contacts/delete', { method: 'POST', body: { contactId: c.id, book: ct.book } })
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
	window.WebmailPublicPicker = publicPicker
})()
