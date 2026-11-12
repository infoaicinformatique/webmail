/* Webmail Exchange for Nextcloud – main UI (vanilla JS, no build step) */
(function () {
	'use strict'

	const BASE = '/apps/webmail/api'
	const PAGE_SIZE = 50
	const REFRESH_MS = 120000
	const ROLE_ORDER = ['inbox', 'drafts', 'sentitems', 'junkemail', 'deleteditems']
	const ROLE_ICON = { inbox: '📥', drafts: '📝', sentitems: '📤', junkemail: '⚠️', deleteditems: '🗑️' }

	const state = {
		accounts: [],
		accountId: null,
		folders: [],
		folderId: 'inbox',
		messages: [],
		total: 0,
		hasMore: false,
		query: '',
		selectedId: null,
		current: null, // opened message
		view: 'list', // mobile: list | detail
		loadingList: false,
		domains: [],
		isAdmin: false,
		module: 'mail', // mail | calendar | contacts
	}

	/** Browser time zone, sent with calendar requests (wall-clock times are interpreted in it). */
	const TZ = (() => { try { return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC' } catch (e) { return 'UTC' } })()
	const MODULES = [
		{ id: 'mail', label: 'Courrier', icon: '✉️' },
		{ id: 'calendar', label: 'Agenda', icon: '📅' },
		{ id: 'contacts', label: 'Contacts', icon: '👤' },
	]
	// calendar.js and contacts.js register themselves here
	window.WebmailModules = window.WebmailModules || {}

	let root, navEl, listEl, detailEl, refreshTimer

	// ------------------------------------------------------------------
	// helpers
	// ------------------------------------------------------------------

	function url(path) {
		return OC.generateUrl(BASE + path)
	}

	async function api(path, opts = {}) {
		const init = { method: opts.method || 'GET', headers: { requesttoken: OC.requestToken, Accept: 'application/json' } }
		if (opts.body instanceof FormData) {
			init.body = opts.body
		} else if (opts.body !== undefined) {
			init.headers['Content-Type'] = 'application/json'
			init.body = JSON.stringify(opts.body)
		}
		let full = url(path)
		if (opts.query) {
			const qs = new URLSearchParams()
			Object.entries(opts.query).forEach(([k, v]) => { if (v !== undefined && v !== null) qs.append(k, v) })
			full += (full.includes('?') ? '&' : '?') + qs.toString()
		}
		const res = await fetch(full, init)
		let data = null
		try { data = await res.json() } catch (e) { /* empty */ }
		if (!res.ok) {
			throw new Error((data && data.message) || ('Erreur ' + res.status))
		}
		return data
	}

	/** Tiny DOM builder: h('div', {class: 'x', onclick: fn}, child, 'text') */
	function h(tag, attrs, ...children) {
		const e = document.createElement(tag)
		if (attrs) {
			for (const [k, v] of Object.entries(attrs)) {
				if (v === undefined || v === null || v === false) continue
				if (k.startsWith('on') && typeof v === 'function') e.addEventListener(k.slice(2), v)
				else if (k === 'class') e.className = v
				else if (k === 'dataset') Object.assign(e.dataset, v)
				else if (k in e && typeof v !== 'string') e[k] = v
				else e.setAttribute(k, v === true ? '' : v)
			}
		}
		for (const c of children.flat()) {
			if (c === null || c === undefined || c === false) continue
			e.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c)
		}
		return e
	}

	function clear(e) { while (e.firstChild) e.removeChild(e.firstChild) }

	function toast(message, isError) {
		let box = document.getElementById('wm-toasts')
		if (!box) {
			box = h('div', { id: 'wm-toasts', 'aria-live': 'polite' })
			document.body.appendChild(box)
		}
		const t = h('div', { class: 'wm-toast' + (isError ? ' wm-toast-error' : ''), role: 'status' }, message)
		box.appendChild(t)
		while (box.children.length > 3) box.firstChild.remove()
		setTimeout(() => t.classList.add('wm-toast-hide'), 4000)
		setTimeout(() => t.remove(), 4600)
	}

	function formatDate(iso, long) {
		if (!iso) return ''
		const d = new Date(iso)
		const now = new Date()
		if (long) {
			return d.toLocaleString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })
		}
		if (d.toDateString() === now.toDateString()) {
			return d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
		}
		if (d.getFullYear() === now.getFullYear()) {
			return d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })
		}
		return d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' })
	}

	function formatSize(bytes) {
		if (!bytes) return ''
		if (bytes < 1024) return bytes + ' o'
		if (bytes < 1024 * 1024) return Math.round(bytes / 1024) + ' Ko'
		return (bytes / 1024 / 1024).toFixed(1).replace('.', ',') + ' Mo'
	}

	function personLabel(p) {
		if (!p) return ''
		if (p.name && p.email && !p.email.startsWith('/')) return p.name + ' <' + p.email + '>'
		return p.name || p.email || ''
	}

	function personShort(p) {
		if (!p) return ''
		return p.name || (p.email && !p.email.startsWith('/') ? p.email : '') || ''
	}

	function currentFolder() {
		return state.folders.find((f) => f.id === state.folderId || f.role === state.folderId)
	}

	function folderRole() {
		const f = currentFolder()
		return f ? f.role : (ROLE_ORDER.includes(state.folderId) ? state.folderId : null)
	}

	function setView(v) {
		state.view = v
		root.dataset.view = v
	}

	// ------------------------------------------------------------------
	// layout
	// ------------------------------------------------------------------

	function buildLayout() {
		clear(root)
		navEl = h('nav', { class: 'wm-nav', 'aria-label': 'Dossiers' })
		listEl = h('section', { class: 'wm-list', 'aria-label': 'Messages' })
		detailEl = h('section', { class: 'wm-detail', 'aria-label': 'Lecture' })
		root.append(navEl, listEl, detailEl)
		root.dataset.module = state.module
		setView('list')
	}

	function moduleTabs() {
		return h('div', { class: 'wm-tabs', role: 'tablist' }, MODULES.map((m) => h('button', {
			type: 'button',
			role: 'tab',
			class: 'wm-tab' + (state.module === m.id ? ' active' : ''),
			'aria-selected': state.module === m.id ? 'true' : 'false',
			dataset: { module: m.id },
			onclick: () => selectModule(m.id),
		}, h('span', { class: 'wm-tab-icon', 'aria-hidden': 'true' }, m.icon), h('span', { class: 'wm-tab-label' }, m.label))))
	}

	function accountSelector() {
		if (state.accounts.length > 1) {
			return h('select', {
				class: 'wm-account-select',
				'aria-label': 'Compte',
				onchange: (e) => selectAccount(parseInt(e.target.value, 10)),
			}, state.accounts.map((a) => h('option', { value: String(a.id), selected: a.id === state.accountId }, a.displayName)))
		}
		if (state.accounts.length === 1) {
			return h('div', { class: 'wm-account-name', title: state.accounts[0].email }, state.accounts[0].email)
		}
		return null
	}

	function navFooter() {
		return h('div', { class: 'wm-nav-footer' },
			h('a', { href: '#', onclick: (e) => { e.preventDefault(); openAccountSettings() } }, '⚙️  Comptes et signature'))
	}

	function currentModule() {
		return state.module === 'mail' ? null : window.WebmailModules[state.module]
	}

	function selectModule(id) {
		if (id !== 'mail' && !window.WebmailModules[id]) {
			toast('Module indisponible', true)
			return
		}
		state.module = id
		root.dataset.module = id
		try { localStorage.setItem('webmail.module', id) } catch (e) { /* ignore */ }
		activateModule()
	}

	function activateModule() {
		const mod = currentModule()
		if (mod) {
			clear(listEl)
			clear(detailEl)
			mod.activate(core)
			return
		}
		state.selectedId = null
		renderNav()
		renderEmptyDetail()
		setView('list')
		if (state.folders.length === 0) loadFolders()
		loadMessages(true)
	}

	// ------------------------------------------------------------------
	// navigation (accounts + folders)
	// ------------------------------------------------------------------

	function renderNav() {
		clear(navEl)
		navEl.appendChild(moduleTabs())
		const mod = currentModule()
		if (mod) {
			mod.renderNav(navEl, core)
			return
		}
		navEl.appendChild(h('button', { class: 'wm-compose-btn primary', onclick: () => openCompose('new') }, '✉️  Nouveau message'))
		const sel = accountSelector()
		if (sel) navEl.appendChild(sel)

		const ul = h('ul', { class: 'wm-folders' })
		const byParent = {}
		state.folders.forEach((f) => { (byParent[f.parentId || ''] = byParent[f.parentId || ''] || []).push(f) })
		const top = (byParent[''] || []).slice().sort((a, b) => {
			const ra = a.role ? ROLE_ORDER.indexOf(a.role) : 99
			const rb = b.role ? ROLE_ORDER.indexOf(b.role) : 99
			return ra !== rb ? ra - rb : a.name.localeCompare(b.name, 'fr')
		})

		const addFolder = (f, depth) => {
			const active = f.id === state.folderId || f.role === state.folderId
			const li = h('li', {},
				h('a', {
					href: '#',
					class: 'wm-folder' + (active ? ' active' : '') + (f.unread ? ' has-unread' : ''),
					style: 'padding-inline-start:' + (12 + depth * 16) + 'px',
					onclick: (e) => { e.preventDefault(); selectFolder(f.id) },
					dataset: { folderId: f.id },
				},
				h('span', { class: 'wm-folder-icon', 'aria-hidden': 'true' }, ROLE_ICON[f.role] || '📁'),
				h('span', { class: 'wm-folder-name' }, f.name),
				f.unread && f.role !== 'sentitems' && f.role !== 'deleteditems' ? h('span', { class: 'wm-badge' }, String(f.unread)) : null),
			)
			ul.appendChild(li)
			const children = (byParent[f.id] || []).slice().sort((a, b) => a.name.localeCompare(b.name, 'fr'))
			children.forEach((c) => addFolder(c, depth + 1))
		}
		top.forEach((f) => addFolder(f, 0))
		navEl.appendChild(ul)
		navEl.appendChild(navFooter())
	}

	async function loadFolders() {
		try {
			const data = await api('/accounts/' + state.accountId + '/folders')
			state.folders = data.folders
			// switch from the "inbox" alias to the real id so the active state works
			const inbox = state.folders.find((f) => f.role === 'inbox')
			if (state.folderId === 'inbox' && inbox) state.folderId = inbox.id
			if (state.module !== 'mail') return
			renderNav()
			if (!state.loadingList && listEl) renderList(false) // folder name in the search placeholder
		} catch (e) {
			toast(e.message, true)
		}
	}

	async function selectAccount(id) {
		state.accountId = id
		state.folderId = 'inbox'
		state.folders = []
		state.query = ''
		try { localStorage.setItem('webmail.account', String(id)) } catch (e) { /* ignore */ }
		if (currentModule()) {
			activateModule()
			return
		}
		renderNav()
		renderEmptyDetail()
		await Promise.all([loadFolders(), loadMessages(true)])
	}

	function selectFolder(id) {
		state.folderId = id
		state.query = ''
		state.selectedId = null
		renderNav()
		renderEmptyDetail()
		setView('list')
		loadMessages(true)
	}

	// ------------------------------------------------------------------
	// message list
	// ------------------------------------------------------------------

	async function loadMessages(reset) {
		if (state.loadingList || state.module !== 'mail') return
		state.loadingList = true
		if (reset) {
			state.messages = []
			state.total = 0
			state.hasMore = false
			renderList(true)
		}
		try {
			const data = await api('/accounts/' + state.accountId + '/messages', {
				query: { folder: state.folderId, offset: reset ? 0 : state.messages.length, limit: PAGE_SIZE, q: state.query },
			})
			state.messages = reset ? data.items : state.messages.concat(data.items)
			state.total = data.total
			state.hasMore = data.hasMore
		} catch (e) {
			toast(e.message, true)
		} finally {
			state.loadingList = false
			if (state.module === 'mail') renderList(false)
		}
	}

	async function refresh() {
		if (!state.accountId || document.hidden || state.module !== 'mail') return
		loadFolders()
		if (state.messages.length <= PAGE_SIZE && !state.loadingList) {
			try {
				const data = await api('/accounts/' + state.accountId + '/messages', {
					query: { folder: state.folderId, offset: 0, limit: PAGE_SIZE, q: state.query },
				})
				state.messages = data.items
				state.total = data.total
				state.hasMore = data.hasMore
				renderList(false)
			} catch (e) { /* silent */ }
		}
	}

	function renderList(loading) {
		clear(listEl)
		const f = currentFolder()
		const searchInput = h('input', {
			type: 'search',
			class: 'wm-search',
			placeholder: 'Rechercher dans ' + (f ? f.name : 'le dossier'),
			value: state.query,
			'aria-label': 'Rechercher',
		})
		const form = h('form', {
			class: 'wm-list-header',
			onsubmit: (e) => { e.preventDefault(); state.query = searchInput.value.trim(); loadMessages(true) },
		},
		h('button', { type: 'button', class: 'wm-back wm-mobile-only', 'aria-label': 'Dossiers', onclick: () => setView('nav') }, '☰'),
		searchInput,
		h('button', { type: 'button', class: 'wm-icon-btn', title: 'Actualiser', 'aria-label': 'Actualiser', onclick: () => { loadFolders(); loadMessages(true) } }, '⟳'),
		)
		listEl.appendChild(form)

		if (state.query) {
			listEl.appendChild(h('div', { class: 'wm-search-info' },
				'Résultats pour « ' + state.query + ' » ',
				h('a', { href: '#', onclick: (e) => { e.preventDefault(); state.query = ''; loadMessages(true) } }, 'effacer')))
		}

		if (loading) {
			listEl.appendChild(h('div', { class: 'wm-center' }, h('span', { class: 'icon-loading' })))
			return
		}
		if (state.messages.length === 0) {
			listEl.appendChild(h('div', { class: 'wm-empty' }, state.query ? 'Aucun résultat' : 'Aucun message'))
			return
		}

		const showRecipients = folderRole() === 'sentitems' || folderRole() === 'drafts'
		const ul = h('ul', { class: 'wm-messages', role: 'list' })
		state.messages.forEach((m) => {
			const who = showRecipients ? ('À : ' + (m.displayTo || '')) : (personShort(m.from) || '(expéditeur inconnu)')
			const li = h('li', {
				class: 'wm-message' + (m.isRead ? '' : ' unread') + (m.id === state.selectedId ? ' selected' : ''),
				tabindex: '0',
				dataset: { id: m.id },
				onclick: () => openMessage(m.id),
				onkeydown: (e) => { if (e.key === 'Enter') openMessage(m.id) },
			},
			h('div', { class: 'wm-message-line1' },
				h('span', { class: 'wm-message-from' }, who),
				h('span', { class: 'wm-message-date' }, formatDate(m.date))),
			h('div', { class: 'wm-message-line2' },
				m.importance === 'High' ? h('span', { class: 'wm-important', title: 'Importance haute' }, '❗') : null,
				h('span', { class: 'wm-message-subject' }, m.subject || '(sans objet)'),
				m.hasAttachments ? h('span', { class: 'wm-attach-icon', title: 'Pièce jointe' }, '📎') : null,
				h('button', {
					class: 'wm-flag' + (m.flagged ? ' on' : ''),
					title: m.flagged ? 'Retirer le suivi' : 'Assurer un suivi',
					'aria-label': 'Suivi',
					onclick: (e) => { e.stopPropagation(); toggleFlag(m) },
				}, '⚑')),
			m.preview ? h('div', { class: 'wm-message-preview' }, m.preview) : null,
			)
			ul.appendChild(li)
		})
		listEl.appendChild(ul)

		if (state.hasMore) {
			listEl.appendChild(h('div', { class: 'wm-center' },
				h('button', { onclick: () => loadMessages(false) }, 'Charger plus (' + state.messages.length + ' / ' + state.total + ')')))
		}
	}

	function updateListItem(id, changes) {
		const m = state.messages.find((x) => x.id === id)
		if (m) Object.assign(m, changes)
		renderList(false)
	}

	async function toggleFlag(m) {
		try {
			await api('/accounts/' + state.accountId + '/flags', { method: 'POST', body: { itemIds: [m.id], flag: 'flagged', value: !m.flagged } })
			updateListItem(m.id, { flagged: !m.flagged })
			if (state.current && state.current.id === m.id) {
				state.current.flagged = !state.current.flagged
				renderMessage()
			}
		} catch (e) { toast(e.message, true) }
	}

	// ------------------------------------------------------------------
	// message detail
	// ------------------------------------------------------------------

	function renderEmptyDetail() {
		state.current = null
		clear(detailEl)
		detailEl.appendChild(h('div', { class: 'wm-empty wm-empty-detail' }, h('div', { class: 'wm-empty-icon' }, '✉️'), 'Sélectionnez un message'))
	}

	async function openMessage(id, remote) {
		state.selectedId = id
		renderList(false)
		setView('detail')
		clear(detailEl)
		detailEl.appendChild(h('div', { class: 'wm-center' }, h('span', { class: 'icon-loading' })))
		try {
			const msg = await api('/accounts/' + state.accountId + '/message', { query: { itemId: id, remote: remote ? 1 : 0 } })
			if (state.selectedId !== id) return
			msg.remoteAllowed = !!remote
			state.current = msg
			const wasUnread = state.messages.find((m) => m.id === id && !m.isRead)
			updateListItem(id, { isRead: true })
			if (wasUnread) {
				const f = currentFolder()
				if (f && f.unread > 0) { f.unread--; renderNav() }
			}
			renderMessage()
		} catch (e) {
			clear(detailEl)
			detailEl.appendChild(h('div', { class: 'wm-empty' }, e.message))
		}
	}

	function renderMessage() {
		const m = state.current
		if (!m) return
		clear(detailEl)
		const role = folderRole()

		const moveSelect = h('select', {
			class: 'wm-move',
			'aria-label': 'Déplacer vers',
			onchange: (e) => { if (e.target.value) moveMessages([m.id], e.target.value) },
		},
		h('option', { value: '' }, 'Déplacer vers…'),
		state.folders.filter((f) => f.id !== state.folderId).map((f) => h('option', { value: f.id }, f.name)))

		const toolbar = h('div', { class: 'wm-toolbar' },
			h('button', { class: 'wm-back wm-mobile-only', onclick: () => setView('list'), 'aria-label': 'Retour' }, '←'),
			h('button', { onclick: () => openCompose('reply', m) }, '↩ Répondre'),
			h('button', { onclick: () => openCompose('replyAll', m) }, '↩↩ Répondre à tous'),
			h('button', { onclick: () => openCompose('forward', m) }, '↪ Transférer'),
			h('button', { onclick: () => markUnread(m) }, 'Non lu'),
			h('button', { class: m.flagged ? 'wm-flag-btn on' : 'wm-flag-btn', onclick: () => toggleFlag(m) }, m.flagged ? '⚑ Suivi ✓' : '⚑ Suivi'),
			moveSelect,
			h('button', { class: 'wm-danger', onclick: () => deleteMessages([m.id], role === 'deleteditems') }, role === 'deleteditems' ? 'Supprimer définitivement' : '🗑 Supprimer'),
		)
		detailEl.appendChild(toolbar)

		const recipients = (label, list) => list && list.length
			? h('div', { class: 'wm-header-line' }, h('span', { class: 'wm-header-label' }, label), list.map(personLabel).join(', '))
			: null

		detailEl.appendChild(h('header', { class: 'wm-message-header' },
			h('h2', { class: 'wm-subject' }, m.subject || '(sans objet)'),
			h('div', { class: 'wm-header-line' }, h('span', { class: 'wm-header-label' }, 'De'), h('strong', {}, personLabel(m.from))),
			recipients('À', m.to),
			recipients('Cc', m.cc),
			recipients('Cci', m.bcc),
			h('div', { class: 'wm-header-line wm-muted' }, formatDate(m.date, true)),
		))

		if (m.meeting) {
			detailEl.appendChild(meetingBanner(m))
		}

		if (m.hasRemoteContent && !m.remoteAllowed) {
			detailEl.appendChild(h('div', { class: 'wm-remote-banner' },
				'Les images distantes ont été bloquées pour protéger votre vie privée. ',
				h('button', { onclick: () => openMessage(m.id, true) }, 'Afficher les images')))
		}

		if (m.attachments && m.attachments.length) {
			const list = h('ul', { class: 'wm-attachments' })
			m.attachments.forEach((a) => {
				const dl = OC.generateUrl(BASE + '/accounts/' + state.accountId + '/attachment') + '?attachmentId=' + encodeURIComponent(a.id)
				list.appendChild(h('li', { class: 'wm-attachment' },
					h('a', { href: dl, class: 'wm-attachment-name', title: 'Télécharger', download: a.name }, '📎 ' + a.name),
					h('span', { class: 'wm-muted' }, ' ' + formatSize(a.size) + ' '),
					h('button', { class: 'wm-small', onclick: () => saveAttachment(a) }, 'Enregistrer dans Fichiers')))
			})
			detailEl.appendChild(list)
		}

		const frame = h('iframe', {
			class: 'wm-body-frame',
			title: 'Contenu du message',
			// no allow-scripts: nothing in the mail can run. allow-same-origin
			// is needed only to measure the content height from here.
			sandbox: 'allow-same-origin allow-popups allow-popups-to-escape-sandbox',
			referrerpolicy: 'no-referrer',
		})
		frame.srcdoc = '<!DOCTYPE html><html><head><meta charset="utf-8"><base target="_blank">'
			+ '<style>html,body{margin:0;padding:0;background:#fff;color:#222;font-family:-apple-system,"Segoe UI",Roboto,Calibri,Arial,sans-serif;font-size:14px;line-height:1.45;overflow-wrap:anywhere}'
			+ 'body{padding:4px 2px}img{max-width:100%;height:auto}table{max-width:100%}pre{white-space:pre-wrap}blockquote{margin:0 0 0 8px;padding-left:8px;border-left:2px solid #ccc}</style>'
			+ '</head><body>' + m.body + '</body></html>'
		const resize = () => {
			try {
				const doc = frame.contentDocument
				if (doc && doc.documentElement) {
					frame.style.height = Math.max(200, doc.documentElement.scrollHeight + 20) + 'px'
				}
			} catch (e) { /* ignore */ }
		}
		frame.addEventListener('load', () => {
			resize()
			try {
				frame.contentDocument.querySelectorAll('img').forEach((img) => img.addEventListener('load', resize))
			} catch (e) { /* ignore */ }
			setTimeout(resize, 500)
		})
		detailEl.appendChild(h('div', { class: 'wm-body' }, frame))
	}

	function formatRange(start, end, allDay) {
		if (!start) return ''
		const s = new Date(start)
		const e = new Date(end || start)
		const day = (d) => d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
		const time = (d) => d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
		if (allDay) {
			const last = new Date(e.getTime() - 86400000)
			return last > s ? 'du ' + day(s) + ' au ' + day(last) : day(s) + ' (journée entière)'
		}
		if (s.toDateString() === e.toDateString()) return day(s) + ', ' + time(s) + ' – ' + time(e)
		return day(s) + ' ' + time(s) + ' – ' + day(e) + ' ' + time(e)
	}

	const RESPONSE_LABELS = { Accept: 'Vous avez accepté', Tentative: 'Vous avez répondu « provisoire »', Decline: 'Vous avez refusé', Organizer: 'Vous êtes l\'organisateur' }

	const DELEGATE_RESPONSE = { Accept: 'a accepté', Tentative: 'a répondu « provisoire »', Decline: 'a refusé' }

	function meetingBanner(m) {
		const info = m.meeting
		// copy of a colleague's invitation received as their delegate
		const principal = info.onBehalfOf ? (info.onBehalfOf.name || info.onBehalfOf.email) : null
		const responseLine = principal
			? (DELEGATE_RESPONSE[info.response] ? principal + ' ' + DELEGATE_RESPONSE[info.response] : null)
			: RESPONSE_LABELS[info.response]
		const box = h('div', { class: 'wm-meeting' + (info.cancelled ? ' cancelled' : '') + (principal ? ' delegate' : '') },
			h('div', { class: 'wm-meeting-title' }, info.cancelled ? '❌ Réunion annulée'
				: (principal ? '📅 Invitation reçue pour ' + principal + ' (vous êtes son délégué)' : '📅 Invitation à une réunion')),
			h('div', {}, (() => { const r = formatRange(info.start, info.end, info.allDay); return r.charAt(0).toUpperCase() + r.slice(1) })()),
			info.location ? h('div', { class: 'wm-muted' }, '📍 ' + info.location) : null,
			responseLine ? h('div', { class: 'wm-muted' }, responseLine) : null)
		if (!info.cancelled) {
			const answer = async (response, label) => {
				if (principal && !window.confirm('Répondre « ' + label + ' » au nom de ' + principal + ' ? L\'organisateur recevra la réponse de votre part, au nom de ' + principal + '.')) return
				try {
					await api('/accounts/' + state.accountId + '/calendar/respond', { method: 'POST', body: { eventId: m.id, response } })
					toast(principal ? 'Réponse envoyée au nom de ' + principal + ' : ' + label : 'Réponse envoyée : ' + label)
					// Exchange files the processed invitation in "Deleted Items"
					removeFromList([m.id])
					loadFolders()
				} catch (e) { toast(e.message, true) }
			}
			box.appendChild(h('div', { class: 'wm-meeting-actions' },
				principal ? h('span', { class: 'wm-muted' }, 'Répondre au nom de ' + principal + ' :') : null,
				h('button', { class: 'primary', onclick: () => answer('accept', 'accepté') }, '✓ Accepter'),
				h('button', { onclick: () => answer('tentative', 'provisoire') }, '? Provisoire'),
				h('button', { class: 'wm-danger', onclick: () => answer('decline', 'refusé') }, '✗ Refuser')))
		}
		return box
	}

	async function markUnread(m) {
		try {
			await api('/accounts/' + state.accountId + '/flags', { method: 'POST', body: { itemIds: [m.id], flag: 'read', value: false } })
			updateListItem(m.id, { isRead: false })
			const f = currentFolder()
			if (f) { f.unread++; renderNav() }
			state.selectedId = null
			renderEmptyDetail()
			setView('list')
		} catch (e) { toast(e.message, true) }
	}

	function removeFromList(ids) {
		state.messages = state.messages.filter((m) => !ids.includes(m.id))
		state.total = Math.max(0, state.total - ids.length)
		if (ids.includes(state.selectedId)) {
			state.selectedId = null
			renderEmptyDetail()
			setView('list')
		}
		renderList(false)
	}

	async function moveMessages(ids, target) {
		try {
			await api('/accounts/' + state.accountId + '/move', { method: 'POST', body: { itemIds: ids, target } })
			removeFromList(ids)
			toast('Message déplacé')
			loadFolders()
		} catch (e) { toast(e.message, true) }
	}

	async function deleteMessages(ids, permanent) {
		if (permanent && !window.confirm('Supprimer définitivement ce message ?')) return
		try {
			await api('/accounts/' + state.accountId + '/delete', { method: 'POST', body: { itemIds: ids, permanent: !!permanent } })
			removeFromList(ids)
			toast(permanent ? 'Message supprimé définitivement' : 'Message placé dans les éléments supprimés')
			loadFolders()
		} catch (e) { toast(e.message, true) }
	}

	function saveAttachment(a) {
		const doSave = async (dir) => {
			try {
				const res = await api('/accounts/' + state.accountId + '/attachment/save', { method: 'POST', body: { attachmentId: a.id, path: dir } })
				toast('Enregistré dans ' + res.path)
			} catch (e) { toast(e.message, true) }
		}
		if (OC.dialogs && typeof OC.dialogs.filepicker === 'function') {
			OC.dialogs.filepicker('Choisir le dossier de destination', (dir) => doSave(dir || '/'), false, 'httpd/unix-directory', true, OC.dialogs.FILEPICKER_TYPE_CHOOSE)
		} else {
			doSave('/Pièces jointes')
		}
	}

	// ------------------------------------------------------------------
	// compose
	// ------------------------------------------------------------------

	/**
	 * Address autocompletion (Exchange contacts + company directory) on a
	 * comma-separated recipients field.
	 */
	function attachAutocomplete(input) {
		const wrap = h('div', { class: 'wm-ac' })
		const menu = h('ul', { class: 'wm-ac-menu', role: 'listbox', hidden: true })
		let timer = null
		let items = []
		let active = -1
		let seq = 0

		const lastToken = () => {
			const v = input.value
			const i = Math.max(v.lastIndexOf(','), v.lastIndexOf(';'))
			return { head: i >= 0 ? v.slice(0, i + 1) + ' ' : '', token: v.slice(i + 1).trim() }
		}
		const close = () => { menu.hidden = true; active = -1 }
		const choose = (r) => {
			const { head } = lastToken()
			input.value = head + (r.name && r.name !== r.email ? r.name + ' <' + r.email + '>' : r.email) + ', '
			close()
			input.focus()
		}
		const render = () => {
			clear(menu)
			items.forEach((r, i) => menu.appendChild(h('li', {
				role: 'option',
				class: 'wm-ac-item' + (i === active ? ' active' : ''),
				onmousedown: (e) => { e.preventDefault(); choose(r) },
			}, h('span', { class: 'wm-ac-name' }, r.name || r.email), ' ',
			h('span', { class: 'wm-muted' }, r.email + (r.source === 'directory' ? ' · annuaire' : '')))))
			// a late answer must not reopen the menu once the field is left
			menu.hidden = items.length === 0 || document.activeElement !== input
		}
		input.setAttribute('autocomplete', 'off')
		input.addEventListener('input', () => {
			clearTimeout(timer)
			const { token } = lastToken()
			if (token.length < 2) { items = []; close(); return }
			timer = setTimeout(async () => {
				const mine = ++seq
				try {
					const data = await api('/accounts/' + state.accountId + '/contacts/resolve', { query: { q: token } })
					if (mine !== seq) return
					items = data.results
					active = items.length ? 0 : -1
					render()
				} catch (e) { /* autocompletion is best effort */ }
			}, 250)
		})
		input.addEventListener('keydown', (e) => {
			if (menu.hidden) return
			if (e.key === 'ArrowDown') { e.preventDefault(); active = (active + 1) % items.length; render() }
			else if (e.key === 'ArrowUp') { e.preventDefault(); active = (active - 1 + items.length) % items.length; render() }
			else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); choose(items[active]) }
			else if (e.key === 'Escape') close()
		})
		input.addEventListener('blur', () => setTimeout(close, 150))
		input.parentNode ? input.parentNode.replaceChild(wrap, input) : null
		wrap.append(input, menu)
		return wrap
	}

	function openCompose(mode, original, prefill) {
		if (!state.accountId) { openAccountSettings(); return }
		if (state.module !== 'mail') {
			state.module = 'mail'
			root.dataset.module = 'mail'
			renderNav()
			if (state.folders.length === 0) loadFolders()
			loadMessages(true)
		}
		setView('detail')
		clear(detailEl)
		const account = state.accounts.find((a) => a.id === state.accountId)
		const ncFiles = [] // paths from Nextcloud Files

		const titles = { new: 'Nouveau message', reply: 'Répondre', replyAll: 'Répondre à tous', forward: 'Transférer' }
		const isResponse = mode === 'reply' || mode === 'replyAll'

		const to = h('input', { type: 'text', name: 'to', placeholder: 'adresse@exemple.fr, …', autocomplete: 'email', multiple: true })
		const cc = h('input', { type: 'text', name: 'cc', placeholder: '' })
		const bcc = h('input', { type: 'text', name: 'bcc', placeholder: '' })
		const subject = h('input', { type: 'text', name: 'subject' })
		const body = h('textarea', { name: 'body', rows: '16' })
		const files = h('input', { type: 'file', multiple: true, class: 'wm-file-input', 'aria-label': 'Joindre des fichiers' })
		const attachList = h('ul', { class: 'wm-compose-attachments' })
		const importance = h('select', { name: 'importance', 'aria-label': 'Importance' },
			h('option', { value: 'Normal' }, 'Importance normale'),
			h('option', { value: 'High' }, 'Importance haute'),
			h('option', { value: 'Low' }, 'Importance faible'))

		if (original) {
			const prefix = mode === 'forward' ? 'TR : ' : 'RE : '
			subject.value = /^(re|tr|fw|fwd)\s?:/i.test(original.subject || '') ? original.subject : prefix + (original.subject || '')
			subject.disabled = true
		}
		if (account && account.signature) {
			body.value = '\n\n-- \n' + account.signature
		}
		if (prefill) {
			if (prefill.to) to.value = prefill.to
			if (prefill.subject) subject.value = prefill.subject
			if (prefill.body) body.value = prefill.body + body.value
		}

		const renderAttachList = () => {
			clear(attachList)
			Array.from(files.files).forEach((f) => attachList.appendChild(h('li', {}, '📎 ' + f.name + ' (' + formatSize(f.size) + ')')))
			ncFiles.forEach((p, i) => attachList.appendChild(h('li', {}, '☁️ ' + p + ' ',
				h('button', { type: 'button', class: 'wm-small', onclick: () => { ncFiles.splice(i, 1); renderAttachList() } }, 'retirer'))))
		}
		files.addEventListener('change', renderAttachList)

		const pickFromFiles = () => {
			if (!(OC.dialogs && OC.dialogs.filepicker)) { toast('Sélecteur de fichiers indisponible', true); return }
			OC.dialogs.filepicker('Joindre depuis Fichiers', (paths) => {
				(Array.isArray(paths) ? paths : [paths]).forEach((p) => { if (p && !ncFiles.includes(p)) ncFiles.push(p) })
				renderAttachList()
			}, true, null, true, OC.dialogs.FILEPICKER_TYPE_CHOOSE)
		}

		const sendBtn = h('button', { type: 'submit', class: 'primary' }, 'Envoyer')
		const form = h('form', {
			class: 'wm-compose',
			onsubmit: async (e) => {
				e.preventDefault()
				const fd = new FormData()
				fd.append('mode', mode)
				fd.append('to', to.value)
				fd.append('cc', cc.value)
				fd.append('bcc', bcc.value)
				fd.append('subject', subject.value)
				fd.append('body', body.value)
				fd.append('importance', importance.value)
				if (original) fd.append('referenceId', original.id)
				Array.from(files.files).forEach((f) => fd.append('files[]', f, f.name))
				ncFiles.forEach((p) => fd.append('filePaths[]', p))
				sendBtn.disabled = true
				sendBtn.textContent = 'Envoi…'
				try {
					await api('/accounts/' + state.accountId + '/send', { method: 'POST', body: fd })
					toast('Message envoyé')
					if (state.current) renderMessage(); else renderEmptyDetail()
					if (folderRole() === 'sentitems') setTimeout(() => loadMessages(true), 1500)
				} catch (err) {
					toast(err.message, true)
					sendBtn.disabled = false
					sendBtn.textContent = 'Envoyer'
				}
			},
		},
		h('div', { class: 'wm-compose-title' },
			h('button', { type: 'button', class: 'wm-back wm-mobile-only', onclick: () => setView('list'), 'aria-label': 'Retour' }, '←'),
			h('h2', {}, titles[mode]),
			h('span', { class: 'wm-muted' }, 'De : ' + (account ? account.email : ''))),
		isResponse
			? h('div', { class: 'wm-info' }, mode === 'reply'
				? 'Réponse à : ' + personLabel((original.replyTo && original.replyTo[0]) || original.from)
				: 'Réponse à : ' + [original.from].concat(original.to || [], original.cc || []).filter(Boolean).map(personShort).join(', '))
			: h('label', { class: 'wm-field' }, h('span', {}, 'À'), attachAutocomplete(to)),
		h('label', { class: 'wm-field' }, h('span', {}, isResponse ? 'Ajouter en Cc' : 'Cc'), attachAutocomplete(cc)),
		h('label', { class: 'wm-field' }, h('span', {}, 'Cci'), attachAutocomplete(bcc)),
		h('label', { class: 'wm-field' }, h('span', {}, 'Objet'), subject),
		body,
		original ? h('div', { class: 'wm-info wm-muted' }, mode === 'forward'
			? 'Le message d\'origine et ses pièces jointes seront ajoutés automatiquement sous votre texte.'
			: 'Le message d\'origine sera cité automatiquement sous votre réponse.') : null,
		attachList,
		h('div', { class: 'wm-compose-actions' },
			sendBtn,
			h('label', { class: 'wm-file-label button' }, '📎 Joindre', files),
			h('button', { type: 'button', onclick: pickFromFiles }, '☁️ Depuis Fichiers'),
			importance,
			h('button', { type: 'button', onclick: () => { if (state.current) renderMessage(); else renderEmptyDetail(); setView(state.current ? 'detail' : 'list') } }, 'Annuler')),
		)
		detailEl.appendChild(form)
		;(mode === 'new' || mode === 'forward' ? to : body).focus()
		if (isResponse) body.setSelectionRange(0, 0)
	}

	// ------------------------------------------------------------------
	// accounts
	// ------------------------------------------------------------------

	function openAccountSettings() {
		setView('detail')
		clear(detailEl)
		const wrap = h('div', { class: 'wm-settings' },
			h('button', { class: 'wm-back wm-mobile-only', onclick: () => setView('list'), 'aria-label': 'Retour' }, '←'),
			h('h2', {}, 'Comptes Exchange'))

		state.accounts.forEach((a) => {
			const name = h('input', { type: 'text', value: a.displayName === a.email ? '' : a.displayName, placeholder: 'Nom affiché (facultatif)' })
			const sig = h('textarea', { rows: '4', placeholder: 'Signature (texte)' })
			sig.value = a.signature || ''
			const pwd = h('input', { type: 'password', placeholder: 'Nouveau mot de passe (si changé)', autocomplete: 'new-password' })
			const save = h('button', { class: 'primary' }, 'Enregistrer')
			save.addEventListener('click', async () => {
				save.disabled = true
				try {
					const updated = await api('/accounts/' + a.id, { method: 'PUT', body: { displayName: name.value, signature: sig.value, password: pwd.value || null } })
					Object.assign(a, updated)
					pwd.value = ''
					toast('Compte mis à jour')
					renderNav()
				} catch (e) { toast(e.message, true) } finally { save.disabled = false }
			})
			const del = h('button', { class: 'wm-danger' }, 'Retirer ce compte')
			del.addEventListener('click', async () => {
				if (!window.confirm('Retirer le compte ' + a.email + ' du webmail ? (aucun e-mail n\'est supprimé)')) return
				try {
					await api('/accounts/' + a.id, { method: 'DELETE' })
					state.accounts = state.accounts.filter((x) => x.id !== a.id)
					if (state.accountId === a.id) {
						state.accountId = state.accounts.length ? state.accounts[0].id : null
						if (state.accountId) selectAccount(state.accountId)
					}
					renderNav()
					openAccountSettings()
				} catch (e) { toast(e.message, true) }
			})
			wrap.appendChild(h('fieldset', { class: 'wm-account-card' },
				h('legend', {}, a.email),
				h('label', { class: 'wm-field' }, h('span', {}, 'Nom'), name),
				h('label', { class: 'wm-field' }, h('span', {}, 'Mot de passe'), pwd),
				h('label', { class: 'wm-field wm-field-top' }, h('span', {}, 'Signature'), sig),
				h('div', { class: 'wm-compose-actions' }, save,
					h('button', {
						type: 'button',
						class: 'wm-delegates-btn',
						onclick: () => (window.WebmailDelegates ? window.WebmailDelegates.open(core, a) : toast('Module indisponible', true)),
					}, '👥 Délégués'),
					del)))
		})

		wrap.appendChild(buildAddAccountForm())
		detailEl.appendChild(wrap)
	}

	function buildAddAccountForm(firstRun) {
		const email = h('input', { type: 'email', required: true, placeholder: 'prenom.nom@' + (state.domains[0] || 'exemple.fr'), autocomplete: 'email' })
		const pwd = h('input', { type: 'password', required: true, autocomplete: 'current-password' })
		const name = h('input', { type: 'text', placeholder: 'Facultatif' })
		const btn = h('button', { type: 'submit', class: 'primary' }, 'Connecter')
		const err = h('div', { class: 'wm-form-error', role: 'alert' })

		const domainsHint = state.domains.length
			? h('p', { class: 'wm-muted' }, 'Domaines pris en charge : ' + state.domains.join(', '))
			: h('p', { class: 'wm-warning' }, 'Aucun serveur Exchange n\'est encore configuré. ',
				state.isAdmin
					? h('a', { href: OC.generateUrl('/settings/admin/webmail') }, 'Configurer les serveurs (administration)')
					: 'Contactez votre administrateur.')

		return h('form', {
			class: 'wm-account-card wm-add-account',
			onsubmit: async (e) => {
				e.preventDefault()
				err.textContent = ''
				btn.disabled = true
				btn.textContent = 'Connexion à Exchange…'
				try {
					const acc = await api('/accounts', { method: 'POST', body: { email: email.value, password: pwd.value, displayName: name.value } })
					state.accounts.push(acc)
					toast('Compte ajouté')
					if (!navEl || !root.contains(navEl)) {
						buildLayout()
						clearInterval(refreshTimer)
						refreshTimer = setInterval(refresh, REFRESH_MS)
					}
					await selectAccount(acc.id)
					renderEmptyDetail()
					setView('list')
				} catch (ex) {
					err.textContent = ex.message
				} finally {
					btn.disabled = false
					btn.textContent = 'Connecter'
				}
			},
		},
		h('h3', {}, firstRun ? 'Connectez votre boîte Exchange' : 'Ajouter un compte'),
		domainsHint,
		h('label', { class: 'wm-field' }, h('span', {}, 'Adresse e-mail'), email),
		h('label', { class: 'wm-field' }, h('span', {}, 'Mot de passe'), pwd),
		h('label', { class: 'wm-field' }, h('span', {}, 'Nom affiché'), name),
		err,
		h('div', { class: 'wm-compose-actions' }, btn))
	}

	function renderFirstRun() {
		clear(root)
		root.dataset.view = 'setup'
		root.appendChild(h('div', { class: 'wm-first-run' },
			h('div', { class: 'wm-empty-icon' }, '✉️'),
			buildAddAccountForm(true)))
	}

	// ------------------------------------------------------------------
	// API shared with calendar.js / contacts.js
	// ------------------------------------------------------------------

	const core = {
		state,
		TZ,
		api,
		h,
		clear,
		toast,
		setView,
		formatRange,
		personLabel,
		personShort,
		attachAutocomplete,
		openCompose,
		openAccountSettings,
		renderNav,
		navFooter,
		accountSelector,
		get navEl() { return navEl },
		get listEl() { return listEl },
		get detailEl() { return detailEl },
		get accountId() { return state.accountId },
	}

	// ------------------------------------------------------------------
	// boot
	// ------------------------------------------------------------------

	async function init() {
		root = document.getElementById('webmail-app')
		if (!root) return
		try { state.domains = JSON.parse(root.dataset.domains || '[]') } catch (e) { state.domains = [] }
		state.isAdmin = root.dataset.isAdmin === '1'

		try {
			state.accounts = await api('/accounts')
		} catch (e) {
			clear(root)
			root.appendChild(h('div', { class: 'wm-empty' }, e.message))
			return
		}

		if (state.accounts.length === 0) {
			renderFirstRun()
			return
		}

		let saved = null
		try {
			saved = parseInt(localStorage.getItem('webmail.account') || '', 10)
			const mod = localStorage.getItem('webmail.module')
			if (mod === 'calendar' || mod === 'contacts') state.module = mod
		} catch (e) { /* ignore */ }
		// calendar.js / contacts.js may be loaded after this file
		if (state.module !== 'mail' && !window.WebmailModules[state.module]) {
			await new Promise((resolve) => (document.readyState === 'complete' ? resolve() : window.addEventListener('load', resolve, { once: true })))
			if (!window.WebmailModules[state.module]) state.module = 'mail'
		}
		buildLayout()
		const first = state.accounts.find((a) => a.id === saved) || state.accounts[0]
		await selectAccount(first.id)

		clearInterval(refreshTimer)
		refreshTimer = setInterval(refresh, REFRESH_MS)
		document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh() })
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init)
	} else {
		init()
	}
})()
