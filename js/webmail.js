/* Webmail Exchange for Nextcloud – main UI (vanilla JS, no build step) */
(function () {
	'use strict'

	const BASE = '/apps/webmail/api'
	const PAGE_SIZE = 50
	const REFRESH_MS = 120000
	const ROLE_ORDER = ['inbox', 'drafts', 'sentitems', 'junkemail', 'deleteditems', 'outbox']
	const ROLE_ICON = { inbox: '📥', drafts: '📝', sentitems: '📤', junkemail: '⚠️', deleteditems: '🗑️', outbox: '📮' }

	const state = {
		accounts: [],
		accountId: null,
		folders: [],
		folderId: 'inbox',
		messages: [],
		total: 0,
		hasMore: false,
		query: '',
		// search filters; all: every folder instead of the current one
		filters: { unread: false, attachments: false, flagged: false, since: '', all: false },
		showFilters: false,
		selectedId: null,
		checked: new Set(), // messages ticked for a grouped action
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
		{ id: 'tasks', label: 'Tâches', icon: '✅' },
	]
	// calendar.js and contacts.js register themselves here
	window.WebmailModules = window.WebmailModules || {}

	let root, navEl, listEl, detailEl, refreshTimer

	// ------------------------------------------------------------------
	// caches: what was already seen is shown at once, then refreshed
	// ------------------------------------------------------------------

	/** "account|folder" -> { messages, total, hasMore, scrollTop } (lists without search) */
	const listCache = new Map()
	/** "account|item id" -> message (without remote images), most recent last */
	const msgCache = new Map()
	const MSG_CACHE_MAX = 40
	/** "account|item id" -> pending fetch (prefetch, not marked as read) */
	const msgPending = new Map()
	let listSeq = 0
	let listObserver = null

	const listKey = () => state.accountId + '|' + state.folderId
	const NO_FILTERS = { unread: false, attachments: false, flagged: false, since: '', all: false }
	const filtersOn = () => ['unread', 'attachments', 'flagged', 'since'].filter((k) => state.filters[k]).length
	/** a search or a filter is active: the list is not the folder's content */
	const isSearching = () => !!state.query || filtersOn() > 0

	function resetSearch() {
		state.query = ''
		state.filters = Object.assign({}, NO_FILTERS)
		state.showFilters = false
	}

	/** Query parameters of the list request (folder, search words, filters). */
	function listQuery(offset, limit) {
		const f = state.filters
		const since = f.since ? new Date(Date.now() - parseInt(f.since, 10) * 86400000) : null
		return {
			folder: state.folderId,
			offset,
			limit,
			q: state.query,
			unread: f.unread ? 1 : undefined,
			attachments: f.attachments ? 1 : undefined,
			flagged: f.flagged ? 1 : undefined,
			since: since ? since.getFullYear() + '-' + String(since.getMonth() + 1).padStart(2, '0') + '-' + String(since.getDate()).padStart(2, '0') : undefined,
			scope: f.all && isSearching() ? 'all' : undefined,
		}
	}
	const msgKey = (id) => state.accountId + '|' + id

	function cacheMessage(msg) {
		const key = msgKey(msg.id)
		msgCache.delete(key)
		msgCache.set(key, msg)
		while (msgCache.size > MSG_CACHE_MAX) msgCache.delete(msgCache.keys().next().value)
	}

	function saveListCache() {
		if (isSearching() || !state.accountId) return
		listCache.set(listKey(), { messages: state.messages.slice(), total: state.total, hasMore: state.hasMore, scrollTop: listEl ? listEl.scrollTop : 0 })
	}

	function isMobile() {
		return window.matchMedia('(max-width: 800px)').matches
	}

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

	// shared mailboxes (contact@…) of the account: their folders, apart from the account's own
	const shared = { accountId: null, list: [] } // [{ email, name, folders, open }]

	function sharedFolders() {
		return shared.accountId === state.accountId ? shared.list.flatMap((m) => m.folders || []) : []
	}

	/** The shared mailbox shown, or null for the account's own mailbox. */
	function currentMailbox() {
		if (shared.accountId !== state.accountId) return null
		return shared.list.find((m) => (m.folders || []).some((f) => f.id === state.folderId)) || null
	}

	/** Where a message of the folder shown can be moved: a folder of the same mailbox. */
	function moveTargets() {
		const mb = currentMailbox()
		return (mb ? mb.folders : state.folders).filter((f) => f.id !== state.folderId)
	}

	function currentFolder() {
		return state.folders.find((f) => f.id === state.folderId || f.role === state.folderId)
			|| sharedFolders().find((f) => f.id === state.folderId)
	}

	function folderRole() {
		const f = currentFolder()
		return f ? f.role : (ROLE_ORDER.includes(state.folderId) ? state.folderId : null)
	}

	let popping = false
	let ignorePop = false

	/**
	 * list | detail | nav (on phones only one is visible). Leaving the list
	 * adds a browser history entry, so the phone's Back button comes back
	 * to it instead of leaving the app.
	 */
	function setView(v) {
		const prev = state.view
		state.view = v
		root.dataset.view = v
		if (!isMobile() || v === prev) return
		try {
			const ours = history.state && history.state.webmail
			if (v !== 'list' && prev === 'list') history.pushState({ webmail: v }, '')
			else if (v !== 'list' && ours) history.replaceState({ webmail: v }, '')
			else if (v === 'list' && ours && !popping) { ignorePop = true; history.back() }
		} catch (e) { /* history is a comfort */ }
	}

	window.addEventListener('popstate', () => {
		if (ignorePop) { ignorePop = false; return }
		if (!root || state.view === 'list') return
		popping = true
		setView('list')
		popping = false
	})

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
			h('a', { href: '#', onclick: (e) => { e.preventDefault(); openAccountSettings() } }, '⚙️  Comptes et signature'),
			h('a', { href: '#', class: 'wm-shortcuts-link wm-desktop-only', onclick: (e) => { e.preventDefault(); showShortcuts() } }, '⌨️  Raccourcis clavier'),
			'Notification' in window
				? h('a', { href: '#', class: 'wm-notify-toggle', onclick: (e) => { e.preventDefault(); toggleNotifications() } },
					notificationsOn() ? '🔔  Notifications activées' : '🔕  Activer les notifications')
				: null)
	}

	// ------------------------------------------------------------------
	// new messages: notifications and unread count in the tab title
	// ------------------------------------------------------------------

	const NOTIFY_MS = 60000
	/** account id -> date of the newest message already announced */
	const notifySince = {}
	const baseTitle = document.title
	let notifyTimer = null

	function notificationsOn() {
		try { return localStorage.getItem('webmail.notify') === 'on' && window.Notification.permission === 'granted' } catch (e) { return false }
	}

	async function toggleNotifications() {
		if (notificationsOn()) {
			try { localStorage.setItem('webmail.notify', 'off') } catch (e) { /* ignore */ }
			toast('Notifications désactivées')
		} else {
			const permission = window.Notification.permission === 'granted' ? 'granted' : await window.Notification.requestPermission()
			if (permission !== 'granted') {
				toast('Le navigateur refuse les notifications pour ce site : autorisez-les dans les réglages du site (icône à gauche de l\'adresse).', true)
				return
			}
			try { localStorage.setItem('webmail.notify', 'on') } catch (e) { /* ignore */ }
			toast('Vous serez prévenu des nouveaux messages tant que Nextcloud est ouvert dans un onglet')
		}
		renderNav()
	}

	function setUnreadTitle(n) {
		document.title = (n > 0 ? '(' + n + ') ' : '') + baseTitle
	}

	/**
	 * Ask each account for the unread messages received since the last check.
	 * Runs every minute, also when the tab is in the background.
	 */
	let checking = null
	let checkAgain = false
	const announced = new Set()
	/**
	 * One check at a time (timer, tab shown again...): no message announced
	 * twice; a check asked for meanwhile runs right after.
	 */
	function checkNewMessages() {
		if (checking) {
			checkAgain = true
			return checking
		}
		checking = (async () => {
			do {
				checkAgain = false
				await runNewMessagesCheck()
			} while (checkAgain)
		})().finally(() => { checking = null })
		return checking
	}

	async function runNewMessagesCheck() {
		for (const account of state.accounts) {
			let r
			try {
				r = await api('/accounts/' + account.id + '/new', { query: { since: notifySince[account.id] || '' } })
			} catch (e) { continue }
			const first = !notifySince[account.id]
			notifySince[account.id] = r.latest
			if (account.id === state.accountId) {
				setUnreadTitle(r.unread)
				const inbox = state.folders.find((f) => f.role === 'inbox')
				if (inbox && inbox.unread !== r.unread && state.module === 'mail') {
					inbox.unread = r.unread
					renderNav()
				}
			}
			const fresh = r.items.filter((m) => !announced.has(m.id))
			fresh.forEach((m) => announced.add(m.id))
			if (!first && fresh.length) announce(account, fresh)
		}
	}

	function announce(account, items) {
		const many = state.accounts.length > 1 ? ' (' + account.email + ')' : ''
		const sender = (m) => (m.from && (m.from.name || m.from.email)) || 'Nouveau message'
		const inFront = !document.hidden && document.hasFocus()
		if (account.id === state.accountId && state.module === 'mail' && folderRole() === 'inbox' && !isSearching()) refresh()
		if (inFront || !notificationsOn()) {
			if (inFront) {
				toast(items.length > 1
					? items.length + ' nouveaux messages' + many
					: '✉️ Nouveau message de ' + sender(items[0]) + many + ' : ' + (items[0].subject || '(sans objet)'))
			}
			return
		}
		const open = (id) => async () => {
			window.focus()
			if (state.accountId !== account.id) await selectAccount(account.id)
			if (state.module !== 'mail') selectModule('mail')
			const inbox = state.folders.find((f) => f.role === 'inbox')
			if (inbox && state.folderId !== inbox.id) selectFolder(inbox.id)
			if (id) openMessage(id)
		}
		const icon = OC.imagePath('webmail', 'app-dark')
		if (items.length > 3) {
			const n = new window.Notification(items.length + ' nouveaux messages' + many, { body: items.slice(0, 3).map((m) => sender(m) + ' : ' + m.subject).join('\n'), icon, tag: 'webmail-' + account.id })
			n.onclick = open(null)
			return
		}
		items.forEach((m) => {
			const n = new window.Notification(sender(m) + many, { body: m.subject || '(sans objet)', icon, tag: 'webmail-' + m.id })
			n.onclick = open(m.id)
		})
	}

	// ------------------------------------------------------------------
	// keyboard shortcuts (mail)
	// ------------------------------------------------------------------

	const SHORTCUTS = [
		['j  ou  ↓', 'Message suivant'],
		['k  ou  ↑', 'Message précédent'],
		['Entrée', 'Ouvrir le message sélectionné'],
		['r', 'Répondre'],
		['a', 'Répondre à tous'],
		['f', 'Transférer'],
		['n', 'Nouveau message'],
		['Suppr', 'Supprimer (Maj+Suppr : définitivement)'],
		['u', 'Marquer comme non lu'],
		['x', 'Sélectionner le message (Ctrl+clic, Maj+clic dans la liste)'],
		['s', 'Assurer un suivi / le retirer'],
		['/', 'Rechercher dans le dossier'],
		['Échap', 'Revenir à la liste'],
		['?', 'Afficher cette aide'],
	]

	function showShortcuts() {
		if (document.querySelector('.wm-overlay')) return
		const overlay = h('div', { class: 'wm-overlay' })
		const close = () => { overlay.remove(); document.removeEventListener('keydown', onKey, true) }
		const onKey = (e) => { if (e.key === 'Escape' || e.key === '?') { e.preventDefault(); e.stopPropagation(); close() } }
		document.addEventListener('keydown', onKey, true)
		overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) close() })
		overlay.appendChild(h('div', { class: 'wm-modal wm-shortcuts', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Raccourcis clavier' },
			h('div', { class: 'wm-modal-header' }, h('h2', {}, 'Raccourcis clavier du courrier'),
				h('button', { class: 'wm-modal-close', 'aria-label': 'Fermer', onclick: close }, '✕')),
			h('table', {}, h('tbody', {}, SHORTCUTS.map(([k, label]) => h('tr', {}, h('td', {}, h('kbd', {}, k)), h('td', {}, label)))))))
		document.body.appendChild(overlay)
	}

	function onShortcut(e) {
		if (state.module !== 'mail' || !root || e.ctrlKey || e.metaKey || e.altKey || e.defaultPrevented) return
		const t = e.target
		// (a message's tick box is not a field one types in)
		if (t && t.closest && t.closest('input:not(.wm-check), textarea, select, [contenteditable="true"]')) return
		if (document.querySelector('.wm-overlay') || detailEl.querySelector('.wm-compose')) return
		const m = state.current
		const role = folderRole()
		const actions = {
			j: () => openRelative(1),
			ArrowDown: () => openRelative(1),
			k: () => openRelative(-1),
			ArrowUp: () => openRelative(-1),
			n: () => openCompose('new'),
			'/': () => { const s = listEl.querySelector('.wm-search'); if (s) { setView('list'); s.focus() } },
			'?': () => showShortcuts(),
			x: () => { if (state.selectedId) toggleChecked(state.selectedId) },
			Escape: () => { if (state.checked.size) clearChecked(); else if (state.view !== 'list') setView('list') },
		}
		if (m) {
			Object.assign(actions, {
				r: () => openCompose('reply', m),
				a: () => openCompose('replyAll', m),
				f: () => openCompose('forward', m),
				u: () => markUnread(m),
				s: () => toggleFlag(m),
				Delete: () => deleteMessages([m.id], e.shiftKey || role === 'deleteditems'),
				'#': () => deleteMessages([m.id], role === 'deleteditems'),
			})
		}
		const action = actions[e.key]
		if (!action) return
		e.preventDefault()
		action()
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
		const away = oofBanner()
		if (away) navEl.appendChild(away)
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
			const link = h('a', {
					href: '#',
					class: 'wm-folder' + (active ? ' active' : '') + (f.unread ? ' has-unread' : ''),
					style: 'padding-inline-start:' + (12 + depth * 16) + 'px',
					onclick: (e) => { e.preventDefault(); selectFolder(f.id) },
					dataset: { folderId: f.id },
				},
				h('span', { class: 'wm-folder-icon', 'aria-hidden': 'true' }, ROLE_ICON[f.role] || '📁'),
				h('span', { class: 'wm-folder-name' }, f.name),
				f.unread && f.role !== 'sentitems' && f.role !== 'deleteditems' ? h('span', { class: 'wm-badge' }, String(f.unread)) : null)
			folderDropTarget(link, f)
			ul.appendChild(h('li', { class: 'wm-folder-row' }, link,
				h('button', {
					type: 'button',
					class: 'wm-folder-menu-btn',
					title: 'Actions sur le dossier',
					'aria-label': 'Actions sur le dossier ' + f.name,
					'aria-haspopup': 'menu',
					onclick: (e) => { e.stopPropagation(); openFolderMenu(e.currentTarget, f) },
				}, '⋯')))
			const children = (byParent[f.id] || []).slice().sort((a, b) => a.name.localeCompare(b.name, 'fr'))
			children.forEach((c) => addFolder(c, depth + 1))
		}
		top.forEach((f) => addFolder(f, 0))
		navEl.appendChild(ul)
		navEl.appendChild(h('a', { href: '#', class: 'wm-new-folder', onclick: (e) => { e.preventDefault(); createFolder(null) } }, '＋ Nouveau dossier'))
		if (shared.accountId === state.accountId) shared.list.forEach((mb) => navEl.appendChild(sharedMailboxNav(mb)))
		const usage = usageGauge()
		if (usage) navEl.appendChild(usage)
		navEl.appendChild(navFooter())
	}

	/** A shared mailbox in the folder pane: its name, its folders (inbox first). */
	function sharedMailboxNav(mb) {
		const box = h('div', { class: 'wm-shared-mailbox', dataset: { mailbox: mb.email } })
		const toggle = h('button', {
			type: 'button',
			class: 'wm-shared-mailbox-title',
			'aria-expanded': String(mb.open),
			title: 'Boîte partagée ' + mb.email,
			onclick: () => {
				mb.open = !mb.open
				try { localStorage.setItem('webmail.shared.' + mb.email, mb.open ? '1' : '0') } catch (e) { /* ignore */ }
				if (mb.open && !mb.folders) loadSharedFolders(mb)
				renderNav()
			},
		}, (mb.open ? '▾ ' : '▸ ') + '📮 ' + mb.name)
		box.appendChild(toggle)
		if (!mb.open) return box
		if (mb.error) {
			box.appendChild(h('div', { class: 'wm-muted wm-shared-error' }, mb.error))
			return box
		}
		if (!mb.folders) {
			box.appendChild(h('div', { class: 'wm-center' }, h('span', { class: 'icon-loading-small' })))
			return box
		}
		const ul = h('ul', { class: 'wm-folders' })
		const byParent = {}
		mb.folders.forEach((f) => { (byParent[f.parentId || ''] = byParent[f.parentId || ''] || []).push(f) })
		const add = (f, depth) => {
			const active = f.id === state.folderId
			ul.appendChild(h('li', { class: 'wm-folder-row' }, h('a', {
				href: '#',
				class: 'wm-folder' + (active ? ' active' : '') + (f.unread ? ' has-unread' : ''),
				style: 'padding-inline-start:' + (12 + depth * 16) + 'px',
				onclick: (e) => { e.preventDefault(); selectFolder(f.id) },
				dataset: { folderId: f.id },
			},
			h('span', { class: 'wm-folder-icon', 'aria-hidden': 'true' }, ROLE_ICON[f.role] || '📁'),
			h('span', { class: 'wm-folder-name' }, f.name),
			f.unread && f.role !== 'sentitems' && f.role !== 'deleteditems' ? h('span', { class: 'wm-badge' }, String(f.unread)) : null)))
			;(byParent[f.id] || []).slice().sort((a, b) => a.name.localeCompare(b.name, 'fr')).forEach((c) => add(c, depth + 1))
		}
		;(byParent[''] || []).slice().sort((a, b) => {
			const ra = a.role ? ROLE_ORDER.indexOf(a.role) : 99
			const rb = b.role ? ROLE_ORDER.indexOf(b.role) : 99
			return ra !== rb ? ra - rb : a.name.localeCompare(b.name, 'fr')
		}).forEach((f) => add(f, 0))
		box.appendChild(ul)
		return box
	}

	async function loadShared(accountId) {
		try {
			const r = await api('/accounts/' + accountId + '/mailboxes')
			if (accountId !== state.accountId) return
			shared.accountId = accountId
			shared.list = r.mailboxes.map((m) => {
				let open = true
				try { open = localStorage.getItem('webmail.shared.' + m.email) !== '0' } catch (e) { /* ignore */ }
				return Object.assign({ folders: null, open, error: null }, m)
			})
			shared.list.filter((m) => m.open).forEach(loadSharedFolders)
			if (state.module === 'mail') renderNav()
		} catch (e) { /* no shared mailbox */ }
	}

	async function loadSharedFolders(mb) {
		try {
			mb.folders = (await api('/accounts/' + state.accountId + '/folders', { query: { mailbox: mb.email } })).folders
			mb.error = null
		} catch (e) {
			mb.error = e.message
		}
		if (state.module === 'mail') renderNav()
	}

	// ------------------------------------------------------------------
	// mailbox size
	// ------------------------------------------------------------------

	function formatBytes(n) {
		if (n >= 1024 ** 3) return (n / 1024 ** 3).toLocaleString('fr-FR', { maximumFractionDigits: n >= 10 * 1024 ** 3 ? 0 : 1 }) + ' Go'
		if (n >= 1024 ** 2) return Math.round(n / 1024 ** 2) + ' Mo'
		return Math.max(1, Math.round(n / 1024)) + ' Ko'
	}

	/** Space used by the mailbox, against its limit when Exchange gives it. */
	function usageGauge() {
		const u = state.usage
		if (!u || typeof u.used !== 'number') return null
		if (!u.limit) {
			return h('div', { class: 'wm-quota', title: 'Espace utilisé par la boîte aux lettres' }, h('span', { class: 'wm-muted' }, formatBytes(u.used) + ' utilisés'))
		}
		const pct = Math.min(100, Math.round((u.used / u.limit) * 100))
		const level = pct >= 90 ? 'full' : (pct >= 75 ? 'high' : 'ok')
		return h('div', { class: 'wm-quota wm-quota-' + level, title: 'Espace utilisé par la boîte aux lettres' },
			h('div', { class: 'wm-quota-bar', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(pct), 'aria-label': 'Espace utilisé' },
				h('div', { class: 'wm-quota-fill', style: 'width:' + pct + '%' })),
			h('span', { class: 'wm-quota-text' }, formatBytes(u.used) + ' sur ' + formatBytes(u.limit) + ' (' + pct + ' %)'))
	}

	const warnedFull = new Set()
	function warnIfFull() {
		const u = state.usage
		if (!u || !u.limit || u.used / u.limit < 0.9 || warnedFull.has(state.accountId)) return
		warnedFull.add(state.accountId)
		toast('Votre boîte aux lettres est presque pleine (' + Math.round((u.used / u.limit) * 100) + ' %) : videz les éléments supprimés ou archivez des messages, sinon l\'envoi sera bloqué.', true)
	}

	async function loadFolders() {
		try {
			const data = await api('/accounts/' + state.accountId + '/folders')
			state.folders = data.folders
			state.usage = data.usage || null
			warnIfFull()
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
		resetSearch()
		try { localStorage.setItem('webmail.account', String(id)) } catch (e) { /* ignore */ }
		if (currentModule()) {
			activateModule()
			return
		}
		renderNav()
		renderEmptyDetail()
		loadOof(id)
		loadShared(id)
		loadCategories(id)
		await Promise.all([loadFolders(), loadMessages(true)])
	}

	// ------------------------------------------------------------------
	// automatic replies (out of office)
	// ------------------------------------------------------------------

	/** account id -> automatic reply settings */
	const oofState = {}

	async function loadOof(accountId) {
		try {
			oofState[accountId] = await api('/accounts/' + accountId + '/oof')
			if (accountId === state.accountId && state.module === 'mail') renderNav()
		} catch (e) { /* not shown when Exchange does not answer */ }
	}

	const shortDate = (iso) => new Date(iso).toLocaleString('fr-FR', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' })

	/** Reminder in the folder pane while automatic replies are on. */
	function oofBanner() {
		const o = oofState[state.accountId]
		if (!o || !o.active) return null
		const account = state.accounts.find((a) => a.id === state.accountId)
		return h('div', { class: 'wm-oof-banner', role: 'status' },
			h('div', {}, '🌴 Réponse automatique activée' + (o.state === 'Scheduled' && o.end ? ' jusqu\'au ' + shortDate(o.end) : '')),
			h('div', {},
				h('a', { href: '#', onclick: (e) => { e.preventDefault(); openOof(account) } }, 'Modifier'), ' · ',
				h('a', {
					href: '#',
					onclick: async (e) => {
						e.preventDefault()
						try {
							oofState[account.id] = await api('/accounts/' + account.id + '/oof', { method: 'POST', body: { state: 'Disabled', audience: o.audience, internal: o.internal, external: o.external } })
							toast('Réponse automatique désactivée')
							renderNav()
						} catch (err) { toast(err.message, true) }
					},
				}, 'Désactiver')))
	}

	/** Same settings as Outlook's "Automatic replies". */
	async function openOof(account) {
		const overlay = h('div', { class: 'wm-overlay' })
		const onKey = (e) => { if (e.key === 'Escape') close() }
		const close = () => { overlay.remove(); document.removeEventListener('keydown', onKey) }
		document.addEventListener('keydown', onKey)
		overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) close() })
		const body = h('div', { class: 'wm-event-form' }, h('div', { class: 'wm-center' }, h('span', { class: 'icon-loading' })))
		overlay.appendChild(h('div', { class: 'wm-modal', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Réponse automatique' },
			h('div', { class: 'wm-modal-header' }, h('h2', {}, 'Réponse automatique – ' + account.email),
				h('button', { class: 'wm-modal-close', 'aria-label': 'Fermer', onclick: close }, '✕')),
			body))
		document.body.appendChild(overlay)
		let o
		try {
			o = await api('/accounts/' + account.id + '/oof')
		} catch (e) {
			clear(body)
			body.appendChild(h('div', { class: 'wm-form-error' }, e.message))
			return
		}
		const local = (iso, fallbackDays) => {
			const d = iso ? new Date(iso) : new Date(Date.now() + fallbackDays * 86400000)
			if (!iso) d.setHours(fallbackDays ? 18 : 8, 0, 0, 0)
			const p = (n) => String(n).padStart(2, '0')
			return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes())
		}
		const radio = (value, label) => h('label', { class: 'wm-radio' },
			h('input', { type: 'radio', name: 'oofState', value, checked: o.state === value, onchange: sync }), ' ' + label)
		// Exchange keeps an old period when none is set: propose today → in a week instead
		const current = o.state === 'Scheduled' && o.end && new Date(o.end) > new Date()
		const start = h('input', { type: 'datetime-local', name: 'oofStart', value: local(current ? o.start : null, 0) })
		const end = h('input', { type: 'datetime-local', name: 'oofEnd', value: local(current ? o.end : null, 7) })
		const internal = h('textarea', { name: 'oofInternal', rows: '5', placeholder: 'Bonjour, je suis absent jusqu\'au … Pour toute urgence, contactez …' })
		internal.value = o.internal || ''
		const audience = h('select', { name: 'oofAudience' },
			[['None', 'Ne pas répondre aux personnes extérieures'], ['Known', 'Répondre seulement à mes contacts extérieurs'], ['All', 'Répondre à toutes les personnes extérieures']]
				.map(([v, l]) => h('option', { value: v, selected: o.audience === v }, l)))
		const differs = h('input', { type: 'checkbox', name: 'oofDiffers', checked: !!o.external && o.external !== o.internal, onchange: sync })
		const external = h('textarea', { name: 'oofExternal', rows: '4', placeholder: 'Message pour les personnes extérieures' })
		external.value = o.external && o.external !== o.internal ? o.external : ''
		const period = h('div', { class: 'wm-oof-period' }, h('label', {}, 'Du ', start), h('label', {}, ' au ', end))
		const externalBox = h('div', {}, h('label', { class: 'wm-radio' }, differs, ' Message différent pour l\'extérieur'), external)
		const details = h('div', {}, h('label', { class: 'wm-field wm-field-top' }, h('span', {}, 'Message'), internal),
			h('label', { class: 'wm-field' }, h('span', {}, 'Extérieur'), audience), externalBox)
		function sync() {
			const st = overlay.querySelector('input[name=oofState]:checked').value
			period.hidden = st !== 'Scheduled'
			details.hidden = st === 'Disabled'
			externalBox.hidden = audience.value === 'None'
			external.hidden = !differs.checked
		}
		audience.addEventListener('change', sync)
		const err = h('div', { class: 'wm-form-error', role: 'alert' })
		const save = h('button', { type: 'submit', class: 'primary' }, 'Enregistrer')
		clear(body)
		body.appendChild(h('form', {
			class: 'wm-event-form',
			onsubmit: async (e) => {
				e.preventDefault()
				err.textContent = ''
				const st = overlay.querySelector('input[name=oofState]:checked').value
				save.disabled = true
				try {
					oofState[account.id] = await api('/accounts/' + account.id + '/oof', {
						method: 'POST',
						body: {
							state: st,
							audience: audience.value,
							start: st === 'Scheduled' ? new Date(start.value).toISOString() : '',
							end: st === 'Scheduled' ? new Date(end.value).toISOString() : '',
							internal: internal.value,
							external: differs.checked ? external.value : '',
						},
					})
					close()
					const r = oofState[account.id]
					toast(r.state === 'Disabled' ? 'Réponse automatique désactivée'
						: r.state === 'Enabled' ? 'Réponse automatique activée'
							: 'Réponse automatique programmée du ' + shortDate(r.start) + ' au ' + shortDate(r.end))
					if (account.id === state.accountId) renderNav()
				} catch (ex) {
					err.textContent = ex.message
					save.disabled = false
				}
			},
		},
		h('div', { class: 'wm-oof-states' },
			radio('Disabled', 'Ne pas envoyer de réponse automatique'),
			radio('Enabled', 'Envoyer une réponse automatique'),
			radio('Scheduled', 'Envoyer une réponse automatique pendant une période')),
		period, details, err,
		h('div', { class: 'wm-compose-actions' }, save, h('button', { type: 'button', onclick: close }, 'Annuler'))))
		sync()
	}

	function openRules(account, prefill) {
		if (!window.WebmailRules) { toast('Module indisponible', true); return }
		window.WebmailRules.open(core, account, prefill)
	}

	/** A new rule for the messages of this sender, to be completed. */
	function ruleFromMessage(m) {
		const account = state.accounts.find((a) => a.id === state.accountId)
		const from = m.from && m.from.email && !m.from.email.startsWith('/') ? m.from.email : ''
		openRules(account, {
			name: 'Messages de ' + ((m.from && (m.from.name || m.from.email)) || 'cet expéditeur'),
			conditions: from ? { from: [from] } : { subject: [m.subject || ''] },
			actions: {},
		})
	}

	function selectFolder(id) {
		saveListCache()
		state.folderId = id
		resetSearch()
		state.checked.clear()
		state.selectedId = null
		renderNav()
		renderEmptyDetail()
		setView('list')
		loadMessages(true)
	}

	// ------------------------------------------------------------------
	// message list
	// ------------------------------------------------------------------

	/**
	 * reset: (re)load the folder from the top. A folder already seen is shown
	 * at once from the cache and refreshed in the background. A newer call
	 * (another folder clicked meanwhile) wins over an older one.
	 */
	async function loadMessages(reset) {
		if (state.module !== 'mail') return
		if (!reset && (state.loadingList || !state.hasMore)) return
		const seq = ++listSeq
		let limit = PAGE_SIZE
		state.loadingList = true
		if (reset) {
			const cached = !isSearching() && listCache.get(listKey())
			if (cached) {
				state.messages = cached.messages.slice()
				state.total = cached.total
				state.hasMore = cached.hasMore
				limit = Math.min(100, Math.max(PAGE_SIZE, cached.messages.length))
				renderList(false, cached.scrollTop)
			} else {
				state.messages = []
				state.total = 0
				state.hasMore = false
				renderList(true)
			}
		}
		try {
			const data = await api('/accounts/' + state.accountId + '/messages', {
				query: listQuery(reset ? 0 : state.messages.length, limit),
			})
			// a newer load (another folder, a search) replaced this one; the folder id
			// itself may change meanwhile ("inbox" becomes its real id)
			if (seq !== listSeq) return
			state.messages = reset ? data.items : state.messages.concat(data.items)
			state.total = data.total
			state.hasMore = data.hasMore
		} catch (e) {
			if (seq === listSeq) toast(e.message, true)
		} finally {
			if (seq === listSeq) {
				state.loadingList = false
				if (state.module === 'mail') {
					renderList(false)
					saveListCache()
				}
			}
		}
	}

	async function refresh() {
		if (!state.accountId || document.hidden || state.module !== 'mail') return
		loadFolders()
		if (state.messages.length <= 100 && !state.loadingList) {
			const seq = listSeq
			try {
				const data = await api('/accounts/' + state.accountId + '/messages', {
					query: listQuery(0, Math.max(PAGE_SIZE, state.messages.length)),
				})
				if (seq !== listSeq || state.module !== 'mail') return
				state.messages = data.items
				state.total = data.total
				state.hasMore = data.hasMore
				renderList(false)
				saveListCache()
			} catch (e) { /* silent */ }
		}
	}

	/**
	 * @param {boolean} loading show a spinner instead of the messages
	 * @param {number} [scrollTop] scroll position to restore (default: keep the current one)
	 */
	function renderList(loading, scrollTop) {
		const keepScroll = scrollTop !== undefined ? scrollTop : listEl.scrollTop
		// a refresh must not disturb someone typing a search
		const typing = document.activeElement && document.activeElement.classList.contains('wm-search') && listEl.contains(document.activeElement)
			? document.activeElement : null
		if (listObserver) { listObserver.disconnect(); listObserver = null }
		clear(listEl)
		const f = currentFolder()
		const searchInput = h('input', {
			type: 'search',
			class: 'wm-search',
			placeholder: state.filters.all ? 'Rechercher dans tous les dossiers' : 'Rechercher dans ' + (f ? f.name : 'le dossier'),
			value: typing ? typing.value : state.query,
			'aria-label': 'Rechercher',
		})
		const form = h('form', {
			class: 'wm-list-header',
			onsubmit: (e) => { e.preventDefault(); state.query = searchInput.value.trim(); loadMessages(true) },
		},
		h('button', { type: 'button', class: 'wm-back wm-mobile-only', 'aria-label': 'Dossiers', onclick: () => setView('nav') }, '☰'),
		searchInput,
		h('button', {
			type: 'button',
			class: 'wm-icon-btn wm-filter-toggle' + (state.showFilters || filtersOn() ? ' active' : ''),
			title: 'Filtres',
			'aria-label': 'Filtres',
			'aria-expanded': state.showFilters ? 'true' : 'false',
			onclick: () => { state.showFilters = !state.showFilters; renderList(state.loadingList && !state.messages.length) },
		}, '⏷' + (filtersOn() ? ' ' + filtersOn() : '')),
		h('button', { type: 'button', class: 'wm-icon-btn', title: 'Actualiser', 'aria-label': 'Actualiser', onclick: () => { loadFolders(); loadMessages(true) } }, '⟳'),
		)
		listEl.appendChild(form)
		if (state.showFilters || filtersOn()) listEl.appendChild(filterBar(searchInput))
		bulkBarEl = h('div', { class: 'wm-bulk-slot' })
		listEl.appendChild(bulkBarEl)
		renderBulkBar()
		if (typing) {
			const caret = typing.selectionStart
			searchInput.focus()
			try { searchInput.setSelectionRange(caret, caret) } catch (e) { /* ignore */ }
		}

		if (isSearching()) {
			const where = state.filters.all ? 'dans tous les dossiers' : 'dans ' + (f ? f.name : 'le dossier')
			listEl.appendChild(h('div', { class: 'wm-search-info' },
				(/^category:"[^"]*"$/.test(state.query) ? 'Catégorie « ' + state.query.slice(10, -1) + ' » '
					: state.query ? 'Résultats pour « ' + state.query + ' » ' : 'Messages filtrés ') + where
				+ (state.loadingList ? '' : ' (' + state.total + ') '),
				h('a', { href: '#', onclick: (e) => { e.preventDefault(); resetSearch(); loadMessages(true) } }, 'effacer')))
		}

		if (loading) {
			listEl.appendChild(h('div', { class: 'wm-center' }, h('span', { class: 'icon-loading' })))
			return
		}
		if (state.messages.length === 0) {
			listEl.appendChild(h('div', { class: 'wm-empty' }, isSearching() ? 'Aucun résultat' : 'Aucun message'))
			return
		}

		const showRecipients = folderRole() === 'sentitems' || folderRole() === 'drafts'
		const ul = h('ul', { class: 'wm-messages', role: 'list' })
		state.messages.forEach((m) => {
			const who = showRecipients ? ('À : ' + (m.displayTo || '')) : (personShort(m.from) || '(expéditeur inconnu)')
			const li = h('li', {
				class: 'wm-message' + (m.isRead ? '' : ' unread') + (m.id === state.selectedId ? ' selected' : '') + (state.checked.has(m.id) ? ' checked' : ''),
				tabindex: '0',
				draggable: 'true',
				dataset: { id: m.id },
				onclick: (e) => {
					// Ctrl/Cmd+click: tick; Shift+click: tick a range; click: open
					if (e.ctrlKey || e.metaKey) toggleChecked(m.id)
					else if (e.shiftKey) checkRange(m.id)
					else openMessage(m.id)
				},
				ondragstart: (e) => {
					const ids = state.checked.has(m.id) ? [...state.checked] : [m.id]
					e.dataTransfer.setData('application/x-webmail-ids', JSON.stringify(ids))
					e.dataTransfer.effectAllowed = 'move'
					// the image shown under the pointer, kept until the drag ends
					dragGhost = h('div', { class: 'wm-drag-ghost' }, ids.length > 1 ? ids.length + ' messages' : (m.subject || '(sans objet)'))
					document.body.appendChild(dragGhost)
					e.dataTransfer.setDragImage(dragGhost, 10, 10)
					root.classList.add('wm-dragging')
				},
				ondragend: () => {
					root.classList.remove('wm-dragging')
					if (dragGhost) { dragGhost.remove(); dragGhost = null }
				},
				onkeydown: (e) => { if (e.key === 'Enter') openMessage(m.id) },
				// hovering a message for a moment loads it in advance
				onmouseenter: () => { clearTimeout(hoverTimer); hoverTimer = setTimeout(() => prefetchMessage(m.id), 150) },
				onmouseleave: () => clearTimeout(hoverTimer),
			},
			h('input', {
				type: 'checkbox',
				class: 'wm-check',
				checked: state.checked.has(m.id),
				'aria-label': 'Sélectionner « ' + (m.subject || '(sans objet)') + ' »',
				onclick: (e) => { e.stopPropagation(); if (e.shiftKey) { e.preventDefault(); checkRange(m.id) } else toggleChecked(m.id) },
			}),
			h('div', { class: 'wm-message-line1' },
				h('span', { class: 'wm-message-from' }, who),
				m.folderId && state.filters.all ? h('span', { class: 'wm-message-folder' }, folderName(m.folderId)) : null,
				h('span', { class: 'wm-message-date' }, formatDate(m.date))),
			h('div', { class: 'wm-message-line2' },
				m.importance === 'High' ? h('span', { class: 'wm-important', title: 'Importance haute' }, '❗') : null,
				h('span', { class: 'wm-message-subject' }, m.subject || '(sans objet)'),
				categoryPills(m.categories),
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
			// the next page loads by itself when the end of the list comes into view
			const more = h('div', { class: 'wm-center wm-list-more' },
				h('button', { onclick: () => loadMessages(false) }, 'Charger plus (' + state.messages.length + ' / ' + state.total + ')'))
			listEl.appendChild(more)
			if ('IntersectionObserver' in window) {
				listObserver = new IntersectionObserver((entries) => {
					if (entries.some((e) => e.isIntersecting)) loadMessages(false)
				}, { root: listEl, rootMargin: '400px' })
				listObserver.observe(more)
			}
		}
		listEl.scrollTop = keepScroll
	}

	// ------------------------------------------------------------------
	// several messages at once: ticks, grouped actions, drag and drop
	// ------------------------------------------------------------------

	let bulkBarEl = null
	let dragGhost = null
	let checkAnchor = null

	function toggleChecked(id) {
		if (state.checked.has(id)) state.checked.delete(id); else state.checked.add(id)
		checkAnchor = id
		refreshChecks()
	}

	/** Shift+click: everything between the last ticked message and this one. */
	function checkRange(id) {
		const ids = state.messages.map((m) => m.id)
		const a = ids.indexOf(checkAnchor !== null ? checkAnchor : (state.selectedId || id))
		const b = ids.indexOf(id)
		if (a < 0 || b < 0) { toggleChecked(id); return }
		ids.slice(Math.min(a, b), Math.max(a, b) + 1).forEach((x) => state.checked.add(x))
		checkAnchor = id
		refreshChecks()
	}

	function clearChecked() {
		state.checked.clear()
		checkAnchor = null
		refreshChecks()
	}

	/** Ticks and the action bar, without redrawing the list. */
	function refreshChecks() {
		listEl.querySelectorAll('.wm-message').forEach((li) => {
			const on = state.checked.has(li.dataset.id)
			li.classList.toggle('checked', on)
			const box = li.querySelector('.wm-check')
			if (box) box.checked = on
		})
		listEl.classList.toggle('wm-has-checked', state.checked.size > 0)
		renderBulkBar()
	}

	function renderBulkBar() {
		if (!bulkBarEl) return
		clear(bulkBarEl)
		// ticks of messages no longer listed are dropped
		const listed = new Set(state.messages.map((m) => m.id))
		;[...state.checked].forEach((id) => { if (!listed.has(id)) state.checked.delete(id) })
		listEl.classList.toggle('wm-has-checked', state.checked.size > 0)
		if (!state.checked.size) return
		const ids = () => [...state.checked]
		const role = folderRole()
		const move = h('select', {
			class: 'wm-move',
			name: 'bulkMove',
			'aria-label': 'Déplacer les messages sélectionnés vers',
			onchange: (e) => { if (e.target.value) moveMessages(ids(), e.target.value) },
		}, h('option', { value: '' }, 'Déplacer vers…'),
		moveTargets().map((f) => h('option', { value: f.id }, f.name)))
		const allTicked = state.checked.size === state.messages.length
		bulkBarEl.appendChild(h('div', { class: 'wm-bulk', role: 'toolbar', 'aria-label': 'Messages sélectionnés' },
			h('strong', {}, state.checked.size + ' sélectionné' + (state.checked.size > 1 ? 's' : '')),
			h('button', {
				type: 'button',
				class: 'wm-small',
				onclick: () => {
					if (allTicked) state.checked.clear(); else state.messages.forEach((m) => state.checked.add(m.id))
					refreshChecks()
				},
			}, allTicked ? 'Tout désélectionner' : 'Tout sélectionner'),
			h('button', { type: 'button', class: 'wm-small', onclick: () => setReadMany(ids(), true) }, 'Lu'),
			h('button', { type: 'button', class: 'wm-small', onclick: () => setReadMany(ids(), false) }, 'Non lu'),
			h('button', { type: 'button', class: 'wm-small', onclick: () => setFlagMany(ids()) }, '⚑ Suivi'),
			move,
			h('button', { type: 'button', class: 'wm-small wm-danger', onclick: () => deleteMessages(ids(), role === 'deleteditems') },
				role === 'deleteditems' ? 'Supprimer définitivement' : '🗑 Supprimer'),
			junkButton(null, role, ids),
			h('button', {
				type: 'button',
				class: 'wm-small wm-cat-btn',
				onclick: (e) => categoryMenu(e.currentTarget, () => state.messages.filter((x) => state.checked.has(x.id))),
			}, '🏷 Catégoriser'),
			h('button', { type: 'button', class: 'wm-small wm-icon-btn', 'aria-label': 'Annuler la sélection', title: 'Annuler la sélection (Échap)', onclick: clearChecked }, '✕')))
	}

	// ------------------------------------------------------------------
	// Outlook categories: names and colours of the mailbox, those of messages
	// ------------------------------------------------------------------

	const categories = { accountId: null, list: [], colors: [], colorNames: [] }

	async function loadCategories(accountId) {
		try {
			const r = await api('/accounts/' + accountId + '/categories')
			if (accountId !== state.accountId) return
			Object.assign(categories, { accountId, list: r.categories, colors: r.colors, colorNames: r.colorNames })
			// the pills already shown get their colours
			listEl.querySelectorAll('.wm-cat').forEach((el) => paintCategory(el, el.dataset.name))
			detailEl.querySelectorAll('.wm-cat').forEach((el) => paintCategory(el, el.dataset.name))
		} catch (e) { /* no colours: grey pills */ }
	}

	function categoryColor(name) {
		const c = categories.list.find((x) => x.name.toLowerCase() === String(name).toLowerCase())
		return c ? c.color : null
	}

	function paintCategory(el, name) {
		const color = categoryColor(name)
		el.style.setProperty('--cat', color || 'var(--color-background-darker)')
		el.classList.toggle('dark', !!color && isDark(color))
	}

	function isDark(hex) {
		const n = parseInt(hex.slice(1), 16)
		return ((n >> 16) * 299 + ((n >> 8) & 255) * 587 + (n & 255) * 114) / 1000 < 140
	}

	/** Coloured pills; in the message header, a click shows every message of that category. */
	function categoryPills(names, clickable) {
		if (!names || !names.length) return null
		return h('span', { class: 'wm-cats' }, names.map((n) => {
			const pill = h(clickable ? 'button' : 'span', {
				class: 'wm-cat',
				title: clickable ? 'Tous les messages de la catégorie « ' + n + ' »' : 'Catégorie ' + n,
				dataset: { name: n },
				onclick: clickable ? () => searchCategory(n) : null,
			}, n)
			if (clickable) pill.type = 'button'
			paintCategory(pill, n)
			return pill
		}))
	}

	function searchCategory(name) {
		state.query = 'category:"' + name.replace(/"/g, '') + '"'
		state.filters.all = true
		setView('list')
		loadMessages(true)
	}

	/** The categories of these messages become: each one had them, with or without `name`. */
	async function assignCategory(messages, name, on) {
		const groups = new Map()
		messages.forEach((m) => {
			const cur = (m.categories || []).filter((c) => c.toLowerCase() !== name.toLowerCase())
			const next = on ? cur.concat(name) : cur
			const key = JSON.stringify(next)
			if (!groups.has(key)) groups.set(key, { next, items: [] })
			groups.get(key).items.push(m)
		})
		const before = messages.map((m) => [m, m.categories || []])
		const apply = (m, cats) => {
			m.categories = cats
			updateListItem(m.id, { categories: cats })
			if (state.current && state.current.id === m.id) {
				state.current.categories = cats
				const box = detailEl.querySelector('.wm-header-cats')
				if (box) { clear(box); const p = categoryPills(cats, true); if (p) box.appendChild(p) }
			}
		}
		groups.forEach((g) => g.items.forEach((m) => apply(m, g.next)))
		try {
			for (const g of groups.values()) {
				await api('/accounts/' + state.accountId + '/categories/assign', { method: 'POST', body: { itemIds: g.items.map((m) => m.id), categories: g.next } })
			}
		} catch (e) {
			before.forEach(([m, cats]) => apply(m, cats))
			toast(e.message, true)
		}
	}

	/** The menu of the 🏷 button: tick the categories, or make a new one. */
	function categoryMenu(anchor, getMessages) {
		document.querySelectorAll('.wm-cat-menu').forEach((x) => x.remove())
		const menu = h('div', { class: 'wm-cat-menu', role: 'dialog', 'aria-label': 'Catégories' })
		const close = () => { menu.remove(); document.removeEventListener('mousedown', outside, true); document.removeEventListener('keydown', esc, true) }
		const outside = (e) => { if (!menu.contains(e.target) && e.target !== anchor) close() }
		const esc = (e) => { if (e.key === 'Escape') { e.stopPropagation(); close() } }
		document.addEventListener('mousedown', outside, true)
		document.addEventListener('keydown', esc, true)
		function fill() {
			clear(menu)
			const messages = getMessages()
			if (!messages.length) { close(); return }
			const known = categories.list.map((c) => c.name)
			messages.forEach((m) => (m.categories || []).forEach((c) => { if (!known.some((k) => k.toLowerCase() === c.toLowerCase())) known.push(c) }))
			const list = h('div', { class: 'wm-cat-list' })
			known.forEach((name) => {
				const has = messages.filter((m) => (m.categories || []).some((c) => c.toLowerCase() === name.toLowerCase())).length
				const box = h('input', { type: 'checkbox', checked: has === messages.length, onchange: () => assignCategory(messages, name, box.checked) })
				box.indeterminate = has > 0 && has < messages.length
				const dot = h('span', { class: 'wm-cat-dot' })
				paintCategory(dot, name)
				list.appendChild(h('label', { class: 'wm-cat-choice' }, box, dot, ' ' + name))
			})
			if (!known.length) list.appendChild(h('p', { class: 'wm-muted' }, 'Aucune catégorie pour l\'instant'))
			const name = h('input', { type: 'text', name: 'newCategory', placeholder: 'Nouvelle catégorie', maxlength: '255' })
			const color = h('select', { name: 'newCategoryColor', 'aria-label': 'Couleur' },
				categories.colorNames.map((l, i) => h('option', { value: String(i), selected: i === 7 }, l)))
			const err = h('div', { class: 'wm-form-error' })
			menu.append(h('div', { class: 'wm-cat-title' }, 'Catégories'), list,
				h('form', {
					class: 'wm-cat-new',
					onsubmit: async (e) => {
						e.preventDefault()
						const n = name.value.trim()
						if (!n) return
						err.textContent = ''
						try {
							const r = await api('/accounts/' + state.accountId + '/categories', { method: 'POST', body: { name: n, color: parseInt(color.value, 10) } })
							categories.list = r.categories
							await assignCategory(getMessages(), n, true)
							fill()
						} catch (ex) { err.textContent = ex.message }
					},
				}, name, color, h('button', { type: 'submit', class: 'primary' }, 'Créer'), err))
		}
		fill()
		document.body.appendChild(menu)
		const r = anchor.getBoundingClientRect()
		menu.style.top = Math.min(r.bottom + 4, window.innerHeight - menu.offsetHeight - 8) + 'px'
		menu.style.left = Math.max(8, Math.min(r.left, window.innerWidth - menu.offsetWidth - 8)) + 'px'
		const first = menu.querySelector('input')
		if (first) first.focus()
	}

	/** Read / unread for several messages, at once on screen. */
	async function setReadMany(ids, value) {
		const changed = state.messages.filter((m) => ids.includes(m.id) && m.isRead !== value)
		changed.forEach((m) => updateListItem(m.id, { isRead: value }))
		const f = currentFolder()
		if (f) { f.unread = Math.max(0, f.unread + (value ? -changed.length : changed.length)); renderNav() }
		clearChecked()
		try {
			await api('/accounts/' + state.accountId + '/flags', { method: 'POST', body: { itemIds: ids, flag: 'read', value } })
			toast(ids.length + ' message' + (ids.length > 1 ? 's marqués' : ' marqué') + (value ? ' comme lu' : ' comme non lu') + (ids.length > 1 ? 's' : ''))
		} catch (e) {
			changed.forEach((m) => updateListItem(m.id, { isRead: !value }))
			toast(e.message, true)
		}
		loadFolders()
	}

	/** Follow-up flag: on for all, or off if all already have it. */
	async function setFlagMany(ids) {
		const items = state.messages.filter((m) => ids.includes(m.id))
		const value = !items.every((m) => m.flagged)
		const before = items.map((m) => [m.id, m.flagged])
		items.forEach((m) => updateListItem(m.id, { flagged: value }))
		clearChecked()
		try {
			await api('/accounts/' + state.accountId + '/flags', { method: 'POST', body: { itemIds: ids, flag: 'flagged', value } })
		} catch (e) {
			before.forEach(([id, v]) => updateListItem(id, { flagged: v }))
			toast(e.message, true)
		}
	}

	/** Folders accept messages dropped on them. */
	function folderDropTarget(el, folder) {
		el.addEventListener('dragover', (e) => {
			if (folder.id === state.folderId || !e.dataTransfer.types.includes('application/x-webmail-ids')) return
			e.preventDefault()
			e.dataTransfer.dropEffect = 'move'
			el.classList.add('wm-drop-target')
		})
		el.addEventListener('dragleave', () => el.classList.remove('wm-drop-target'))
		el.addEventListener('drop', (e) => {
			el.classList.remove('wm-drop-target')
			root.classList.remove('wm-dragging')
			let ids = []
			try { ids = JSON.parse(e.dataTransfer.getData('application/x-webmail-ids')) } catch (err) { return }
			if (!Array.isArray(ids) || !ids.length || folder.id === state.folderId) return
			e.preventDefault()
			ids.forEach((id) => state.checked.delete(id))
			moveMessages(ids, folder.id, folder.name)
		})
	}

	// ------------------------------------------------------------------
	// managing folders: create, rename, move, delete
	// ------------------------------------------------------------------

	const FIXED_ROLES = ['inbox', 'drafts', 'sentitems', 'deleteditems', 'junkemail', 'outbox']

	function openFolderMenu(anchor, f) {
		document.querySelectorAll('.wm-folder-menu').forEach((m) => m.remove())
		const fixed = FIXED_ROLES.includes(f.role)
		const item = (label, action, danger) => h('button', {
			type: 'button',
			role: 'menuitem',
			class: 'wm-folder-menu-item' + (danger ? ' wm-danger' : ''),
			onclick: () => { close(); action() },
		}, label)
		const menu = h('div', { class: 'wm-folder-menu', role: 'menu', 'aria-label': 'Dossier ' + f.name },
			item('＋ Nouveau sous-dossier', () => createFolder(f)),
			fixed ? null : item('✏️ Renommer', () => renameFolder(f)),
			fixed ? null : item('↪ Déplacer…', () => moveFolder(f)),
			fixed ? null : item('🗑 Supprimer', () => deleteFolder(f), true))
		const close = () => { menu.remove(); document.removeEventListener('mousedown', outside, true); document.removeEventListener('keydown', onKey, true) }
		const outside = (e) => { if (!menu.contains(e.target)) close() }
		const onKey = (e) => { if (e.key === 'Escape') { e.stopPropagation(); close() } }
		document.addEventListener('mousedown', outside, true)
		document.addEventListener('keydown', onKey, true)
		const r = anchor.getBoundingClientRect()
		menu.style.top = Math.min(r.bottom + 2, window.innerHeight - 180) + 'px'
		menu.style.left = Math.max(8, r.right - 210) + 'px'
		document.body.appendChild(menu)
		const first = menu.querySelector('button')
		if (first) first.focus()
	}

	/**
	 * Small dialog: a name to type, or a choice in a list.
	 * @return {Promise<string|null>} null when cancelled
	 */
	function askFolder({ title, label, value = '', choices = null, confirmLabel }) {
		return new Promise((resolve) => {
			const overlay = h('div', { class: 'wm-overlay' })
			const done = (v) => { overlay.remove(); document.removeEventListener('keydown', onKey); resolve(v) }
			const onKey = (e) => { if (e.key === 'Escape') done(null) }
			document.addEventListener('keydown', onKey)
			overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) done(null) })
			const field = choices
				? h('select', { name: 'folderChoice' }, choices.map(([v, l]) => h('option', { value: v }, l)))
				: h('input', { type: 'text', name: 'folderName', value, maxlength: '255', required: true })
			overlay.appendChild(h('div', { class: 'wm-modal wm-modal-small', role: 'dialog', 'aria-modal': 'true', 'aria-label': title },
				h('div', { class: 'wm-modal-header' }, h('h2', {}, title), h('button', { class: 'wm-modal-close', 'aria-label': 'Fermer', onclick: () => done(null) }, '✕')),
				h('form', {
					class: 'wm-event-form',
					onsubmit: (e) => { e.preventDefault(); const v = field.value.trim ? field.value.trim() : field.value; if (choices || v) done(v) },
				},
				h('label', { class: 'wm-field' }, h('span', {}, label), field),
				h('div', { class: 'wm-compose-actions' }, h('button', { type: 'submit', class: 'primary' }, confirmLabel), h('button', { type: 'button', onclick: () => done(null) }, 'Annuler')))))
			document.body.appendChild(overlay)
			field.focus()
			if (field.select) field.select()
		})
	}

	async function folderAction(path, body, message) {
		try {
			const r = await api('/accounts/' + state.accountId + '/folders' + path, { method: 'POST', body })
			toast(message)
			await loadFolders()
			return r
		} catch (e) {
			toast(e.message, true)
			return null
		}
	}

	async function createFolder(parent) {
		const name = await askFolder({ title: parent ? 'Nouveau dossier dans « ' + parent.name + ' »' : 'Nouveau dossier', label: 'Nom', confirmLabel: 'Créer' })
		if (name) await folderAction('', { name, parentId: parent ? parent.id : '' }, 'Dossier « ' + name + ' » créé')
	}

	async function renameFolder(f) {
		const name = await askFolder({ title: 'Renommer « ' + f.name + ' »', label: 'Nouveau nom', value: f.name, confirmLabel: 'Renommer' })
		if (name && name !== f.name) await folderAction('/rename', { folderId: f.id, name }, 'Dossier renommé en « ' + name + ' »')
	}

	async function moveFolder(f) {
		// not into itself nor into one of its own sub-folders
		const inside = new Set([f.id])
		let grew = true
		while (grew) {
			grew = false
			state.folders.forEach((x) => { if (x.parentId && inside.has(x.parentId) && !inside.has(x.id)) { inside.add(x.id); grew = true } })
		}
		const choices = [['', '(en haut de la boîte aux lettres)']].concat(state.folders.filter((x) => !inside.has(x.id) && x.id !== f.parentId).map((x) => [x.id, x.name]))
		const target = await askFolder({ title: 'Déplacer « ' + f.name + ' »', label: 'Dans', choices, confirmLabel: 'Déplacer' })
		if (target === null) return
		const where = target ? '« ' + folderName(target) + ' »' : 'en haut de la boîte aux lettres'
		await folderAction('/move', { folderId: f.id, parentId: target }, 'Dossier « ' + f.name + ' » déplacé ' + (target ? 'dans ' : '') + where)
	}

	async function deleteFolder(f) {
		if (!window.confirm('Supprimer le dossier « ' + f.name + ' » et tout son contenu ? Il sera placé dans « Éléments supprimés ».')) return
		const wasOpen = state.folderId === f.id
		if (await folderAction('/delete', { folderId: f.id }, 'Dossier « ' + f.name + ' » placé dans les éléments supprimés') && wasOpen) {
			const inbox = state.folders.find((x) => x.role === 'inbox')
			if (inbox) selectFolder(inbox.id)
		}
	}

	function folderName(id) {
		const f = state.folders.find((x) => x.id === id) || sharedFolders().find((x) => x.id === id)
		return f ? f.name : ''
	}

	/** Filters under the search field: unread, attachments, flagged, period, all folders. */
	function filterBar(searchInput) {
		const f = state.filters
		const apply = () => {
			// keep the words typed but not yet searched
			state.query = searchInput.value.trim()
			loadMessages(true)
		}
		const chip = (key, label) => h('button', {
			type: 'button',
			class: 'wm-chip' + (f[key] ? ' on' : ''),
			'aria-pressed': f[key] ? 'true' : 'false',
			dataset: { filter: key },
			onclick: () => { f[key] = !f[key]; apply() },
		}, label)
		const period = h('select', { class: 'wm-chip-select', name: 'since', 'aria-label': 'Période', onchange: (e) => { f.since = e.target.value; apply() } },
			[['', 'Toute période'], ['7', '7 derniers jours'], ['30', '30 derniers jours'], ['365', '12 derniers mois']]
				.map(([v, l]) => h('option', { value: v, selected: f.since === v }, l)))
		const all = h('label', { class: 'wm-chip-check' },
			h('input', { type: 'checkbox', name: 'allFolders', checked: f.all, onchange: (e) => { f.all = e.target.checked; if (isSearching() || searchInput.value.trim()) apply() } }),
			' Tous les dossiers')
		return h('div', { class: 'wm-filters', role: 'group', 'aria-label': 'Filtres' },
			chip('unread', 'Non lus'), chip('attachments', '📎 Pièces jointes'), chip('flagged', '⚑ Suivis'), period, all)
	}

	function listItemEl(id) {
		return [...listEl.querySelectorAll('.wm-message')].find((li) => li.dataset.id === id) || null
	}

	/** Update one message of the list in place (no full redraw: no flicker, scroll kept). */
	function updateListItem(id, changes) {
		const m = state.messages.find((x) => x.id === id)
		if (!m) return
		Object.assign(m, changes)
		const li = listItemEl(id)
		if (!li) return
		li.classList.toggle('unread', !m.isRead)
		if ('categories' in changes) {
			const old = li.querySelector('.wm-cats')
			const pills = categoryPills(m.categories)
			if (old) old.replaceWith(pills || h('span', { class: 'wm-cats' }))
			else if (pills) li.querySelector('.wm-message-subject').after(pills)
		}
		const flag = li.querySelector('.wm-flag')
		if (flag) {
			flag.classList.toggle('on', !!m.flagged)
			flag.title = m.flagged ? 'Retirer le suivi' : 'Assurer un suivi'
		}
		saveListCache()
	}

	function markSelected(id) {
		listEl.querySelectorAll('.wm-message.selected').forEach((li) => li.classList.remove('selected'))
		const li = id ? listItemEl(id) : null
		if (li) {
			li.classList.add('selected')
			li.scrollIntoView({ block: 'nearest' })
		}
	}

	/** Applied at once, undone if Exchange refuses. */
	async function toggleFlag(m) {
		const value = !m.flagged
		const apply = (v) => {
			updateListItem(m.id, { flagged: v })
			const cached = msgCache.get(msgKey(m.id))
			if (cached) cached.flagged = v
			if (state.current && state.current.id === m.id) {
				state.current.flagged = v
				const btn = detailEl.querySelector('.wm-flag-btn')
				if (btn) { btn.classList.toggle('on', v); btn.textContent = v ? '⚑ Suivi ✓' : '⚑ Suivi' }
			}
		}
		apply(value)
		try {
			await api('/accounts/' + state.accountId + '/flags', { method: 'POST', body: { itemIds: [m.id], flag: 'flagged', value } })
		} catch (e) {
			apply(!value)
			toast(e.message, true)
		}
	}

	// ------------------------------------------------------------------
	// message detail
	// ------------------------------------------------------------------

	function renderEmptyDetail() {
		state.current = null
		clear(detailEl)
		detailEl.appendChild(h('div', { class: 'wm-empty wm-empty-detail' }, h('div', { class: 'wm-empty-icon' }, '✉️'), 'Sélectionnez un message'))
	}

	let hoverTimer = null

	/** Load a message in advance, without marking it as read. */
	function prefetchMessage(id) {
		const key = msgKey(id)
		if (msgCache.has(key)) return Promise.resolve(msgCache.get(key))
		if (!msgPending.has(key)) {
			const p = api('/accounts/' + state.accountId + '/message', { query: { itemId: id, remote: 0, markRead: 0 } })
				.then((msg) => { cacheMessage(msg); return msg })
				.finally(() => msgPending.delete(key))
			p.catch(() => { /* prefetch is best effort */ })
			msgPending.set(key, p)
		}
		return msgPending.get(key)
	}

	/** Load the next and previous messages while this one is read. */
	function prefetchAround(id) {
		const i = state.messages.findIndex((m) => m.id === id)
		if (i < 0) return
		const ids = [state.messages[i + 1], state.messages[i - 1]].filter(Boolean).map((m) => m.id)
		setTimeout(() => ids.reduce((p, next) => p.then(() => (state.selectedId === id ? prefetchMessage(next) : null)).catch(() => null), Promise.resolve()), 300)
	}

	function markReadLocally(id) {
		const item = state.messages.find((m) => m.id === id)
		if (item && !item.isRead) {
			const f = currentFolder()
			if (f && f.unread > 0) { f.unread--; renderNav() }
		}
		updateListItem(id, { isRead: true })
	}

	async function openMessage(id, remote) {
		state.selectedId = id
		markSelected(id)
		setView('detail')
		const key = msgKey(id)
		let msg = !remote ? msgCache.get(key) : null
		let fromCache = !!msg
		if (!msg && !remote && msgPending.has(key)) {
			try { msg = await msgPending.get(key); fromCache = true } catch (e) { msg = null }
			if (state.selectedId !== id) return
		}
		if (!msg) {
			clear(detailEl)
			detailEl.appendChild(h('div', { class: 'wm-center' }, h('span', { class: 'icon-loading' })))
			try {
				msg = await api('/accounts/' + state.accountId + '/message', { query: { itemId: id, remote: remote ? 1 : 0 } })
				if (!remote) cacheMessage(msg)
			} catch (e) {
				if (state.selectedId !== id) return
				clear(detailEl)
				detailEl.appendChild(h('div', { class: 'wm-empty' }, e.message))
				return
			}
			if (state.selectedId !== id) return
		}
		if (fromCache && !msg.isRead) {
			// loaded in advance: mark it as read now that it is opened
			api('/accounts/' + state.accountId + '/flags', { method: 'POST', body: { itemIds: [id], flag: 'read', value: true } })
				.catch((e) => toast(e.message, true))
		}
		if (state.module !== 'mail') return
		const own = state.folders.find((f) => f.id === msg.folderId)
		if ((own ? own.role : folderRole()) === 'drafts') {
			// a draft opens in the editor, to be finished and sent
			state.current = null
			openCompose('draft', msg)
			return
		}
		msg.isRead = true
		state.current = Object.assign({}, msg, { remoteAllowed: !!remote })
		markReadLocally(id)
		renderMessage()
		detailEl.scrollTop = 0
		if (!remote) prefetchAround(id)
	}

	/** Open the next (+1) or previous (-1) message of the list. */
	function openRelative(step) {
		if (!state.messages.length) return
		const i = state.messages.findIndex((m) => m.id === state.selectedId)
		const j = i < 0 ? 0 : i + step
		if (j >= state.messages.length) {
			if (state.hasMore) loadMessages(false)
			return
		}
		if (j >= 0) openMessage(state.messages[j].id)
	}

	function renderMessage() {
		const m = state.current
		// loaded after a switch to the calendar or the contacts: not shown over them
		if (!m || state.module !== 'mail') return
		clear(detailEl)
		const own = state.folders.find((f) => f.id === m.folderId)
		const role = own ? own.role : folderRole()

		const moveSelect = h('select', {
			class: 'wm-move',
			'aria-label': 'Déplacer vers',
			onchange: (e) => { if (e.target.value) moveMessages([m.id], e.target.value) },
		},
		h('option', { value: '' }, 'Déplacer vers…'),
		moveTargets().map((f) => h('option', { value: f.id }, f.name)))

		const toolbar = h('div', { class: 'wm-toolbar' },
			h('button', { class: 'wm-back wm-mobile-only', onclick: () => setView('list'), 'aria-label': 'Retour' }, '←'),
			h('button', { onclick: () => openCompose('reply', m) }, '↩ Répondre'),
			h('button', { onclick: () => openCompose('replyAll', m) }, '↩↩ Répondre à tous'),
			h('button', { onclick: () => openCompose('forward', m) }, '↪ Transférer'),
			h('button', { onclick: () => markUnread(m) }, 'Non lu'),
			h('button', { class: m.flagged ? 'wm-flag-btn on' : 'wm-flag-btn', onclick: () => toggleFlag(m) }, m.flagged ? '⚑ Suivi ✓' : '⚑ Suivi'),
			moveSelect,
			h('button', { class: 'wm-danger', onclick: () => deleteMessages([m.id], role === 'deleteditems') }, role === 'deleteditems' ? 'Supprimer définitivement' : '🗑 Supprimer'),
			junkButton([m.id], role),
			h('button', { class: 'wm-cat-btn', title: 'Catégoriser', 'aria-label': 'Catégoriser', onclick: (e) => categoryMenu(e.currentTarget, () => [m]) }, '🏷'),
			h('button', { class: 'wm-print-btn', title: 'Imprimer', 'aria-label': 'Imprimer', onclick: () => printMessage(m) }, '🖨'),
			h('button', { class: 'wm-rule-from-msg', title: 'Créer une règle pour les messages de cet expéditeur', onclick: () => ruleFromMessage(m) }, '📋 Règle…'),
		)
		detailEl.appendChild(toolbar)

		const recipients = (label, list) => list && list.length
			? h('div', { class: 'wm-header-line' }, h('span', { class: 'wm-header-label' }, label), list.map(personLabel).join(', '))
			: null

		detailEl.appendChild(h('header', { class: 'wm-message-header' },
			h('h2', { class: 'wm-subject' }, m.subject || '(sans objet)'),
			h('div', { class: 'wm-header-cats' }, categoryPills(m.categories, true)),
			h('div', { class: 'wm-header-line' }, h('span', { class: 'wm-header-label' }, 'De'), h('strong', {}, personLabel(m.from))),
			recipients('À', m.to),
			recipients('Cc', m.cc),
			recipients('Cci', m.bcc),
			h('div', { class: 'wm-header-line wm-muted' }, formatDate(m.date, true)),
		))

		// the other messages of the conversation (the replies sent included)
		const thread = h('section', { class: 'wm-thread', hidden: true, 'aria-label': 'Conversation' })
		detailEl.appendChild(thread)
		if (m.conversationId) loadThread(m, thread)

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
			const previewable = m.attachments.filter((a) => previewKind(a))
			m.attachments.forEach((a) => {
				const kind = previewKind(a)
				const open = kind ? () => openPreview(previewable, previewable.indexOf(a)) : null
				list.appendChild(h('li', { class: 'wm-attachment' + (kind === 'image' ? ' wm-attachment-image' : '') },
					kind === 'image'
						? h('button', { type: 'button', class: 'wm-attachment-thumb', title: 'Aperçu de ' + a.name, onclick: open },
							h('img', { src: attachmentUrl(a, true), alt: '', loading: 'lazy' }))
						: null,
					h('a', { href: attachmentUrl(a), class: 'wm-attachment-name', title: 'Télécharger', download: a.name }, '📎 ' + a.name),
					h('span', { class: 'wm-muted' }, ' ' + formatSize(a.size) + ' '),
					kind ? h('button', { class: 'wm-small wm-preview-btn', onclick: open }, '👁 Aperçu') : null,
					h('button', { class: 'wm-small', onclick: () => saveAttachment(a) }, 'Enregistrer dans Fichiers')))
			})
			detailEl.appendChild(list)
			if (m.attachments.filter((a) => !a.isItem).length > 1) {
				detailEl.appendChild(h('div', { class: 'wm-attachments-all' },
					h('button', { class: 'wm-small', onclick: () => saveAllAttachments(m.attachments.filter((a) => !a.isItem)) }, '💾 Tout enregistrer dans Fichiers')))
			}
		}

		detailEl.appendChild(bodyFrame(m.body))
	}

	const threadCache = new Map()

	/**
	 * The conversation of a message: every message of it, oldest first, the
	 * one read marked; another one opens in place (its text below it).
	 */
	async function loadThread(m, box) {
		let items = threadCache.get(m.conversationId)
		if (!items) {
			try {
				items = (await api('/accounts/' + state.accountId + '/conversation', { query: { conversationId: m.conversationId } })).items
			} catch (e) { return } // no conversation: the message alone, as before
			threadCache.set(m.conversationId, items)
			setTimeout(() => threadCache.delete(m.conversationId), 60000)
		}
		if (!box.isConnected || !state.current || state.current.id !== m.id || items.length < 2) return
		const others = items.filter((x) => x.id !== m.id).length
		const list = h('ol', { class: 'wm-thread-list' })
		const toggle = h('button', {
			type: 'button',
			class: 'wm-thread-toggle',
			'aria-expanded': 'false',
			onclick: () => {
				const open = list.hidden
				list.hidden = !open
				toggle.setAttribute('aria-expanded', String(open))
				toggle.textContent = (open ? '▾ ' : '▸ ') + 'Conversation : ' + items.length + ' messages'
				try { localStorage.setItem('webmail.threadOpen', open ? '1' : '0') } catch (e) { /* ignore */ }
			},
		}, '▸ Conversation : ' + items.length + ' messages')
		items.forEach((x) => {
			const own = state.folders.find((f) => f.id === x.folderId)
			const isThis = x.id === m.id
			const body = h('div', { class: 'wm-thread-body', hidden: true })
			const row = h('li', { class: 'wm-thread-item' + (isThis ? ' current' : '') + (x.isRead ? '' : ' unread'), dataset: { id: x.id } },
				h('button', {
					type: 'button',
					class: 'wm-thread-head',
					disabled: isThis,
					'aria-expanded': 'false',
					onclick: async (e) => {
						const btn = e.currentTarget
						if (!body.hidden) { body.hidden = true; btn.setAttribute('aria-expanded', 'false'); return }
						body.hidden = false
						btn.setAttribute('aria-expanded', 'true')
						if (body.dataset.loaded) return
						body.appendChild(h('div', { class: 'wm-center' }, h('span', { class: 'icon-loading-small' })))
						try {
							const full = await api('/accounts/' + state.accountId + '/message', { query: { itemId: x.id } })
							x.isRead = true
							row.classList.remove('unread')
							markReadLocally(x.id)
							clear(body)
							body.dataset.loaded = '1'
							body.append(
								h('div', { class: 'wm-thread-actions' },
									h('button', { type: 'button', class: 'wm-small', onclick: () => openMessage(x.id) }, 'Ouvrir ce message'),
									h('button', { type: 'button', class: 'wm-small', onclick: () => openCompose('reply', full) }, '↩ Répondre')),
								bodyFrame(full.body))
						} catch (err) {
							clear(body)
							body.appendChild(h('div', { class: 'wm-form-error' }, err.message))
						}
					},
				},
				h('span', { class: 'wm-thread-from' }, personShort(x.from) || x.displayTo || '(expéditeur inconnu)'),
				(() => {
					const tags = [own && own.id !== m.folderId ? own.name : null, isThis ? 'ce message' : null].filter(Boolean)
					return tags.length ? h('span', { class: 'wm-thread-folder' }, tags.join(' · ')) : h('span')
				})(),
				h('span', { class: 'wm-thread-date' }, formatDate(x.date)),
				h('span', { class: 'wm-thread-preview' }, x.preview || '')),
				body)
			list.appendChild(row)
		})
		let open = false
		try { open = localStorage.getItem('webmail.threadOpen') === '1' } catch (e) { /* ignore */ }
		list.hidden = !open
		toggle.setAttribute('aria-expanded', String(open))
		toggle.textContent = (open ? '▾ ' : '▸ ') + 'Conversation : ' + items.length + ' messages'
		toggle.title = others + ' autre' + (others > 1 ? 's' : '') + ' message' + (others > 1 ? 's' : '') + ' de cette conversation, réponses envoyées comprises'
		clear(box)
		box.append(toggle, list)
		box.hidden = false
	}

	/** The (already cleaned) HTML of a message in a sandboxed frame, as tall as its content. */
	function bodyFrame(html) {
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
			+ '</head><body>' + html + '</body></html>'
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
		return h('div', { class: 'wm-body' }, frame)
	}

	/** Print a message: its headers and its (already cleaned) content, in a page of its own. */
	function printMessage(m) {
		const w = window.open('', '_blank')
		if (!w) { toast('Le navigateur a bloqué la fenêtre d\'impression', true); return }
		const line = (label, value) => value ? '<tr><th>' + escapeHtml(label) + '</th><td>' + escapeHtml(value) + '</td></tr>' : ''
		const people = (list) => (list || []).map(personLabel).join(', ')
		w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + escapeHtml(m.subject || 'Message') + '</title>'
			+ '<style>body{font-family:Calibri,Arial,sans-serif;font-size:11pt;margin:24px;color:#000}h1{font-size:16pt;margin:0 0 8px}'
			+ 'table{border-collapse:collapse;margin-bottom:12px}th{text-align:left;padding:2px 12px 2px 0;color:#555;font-weight:normal;vertical-align:top}'
			+ 'hr{border:0;border-top:1px solid #999;margin:12px 0}img{max-width:100%}</style></head><body>'
			+ '<h1>' + escapeHtml(m.subject || '(sans objet)') + '</h1><table>'
			+ line('De', personLabel(m.from)) + line('À', people(m.to)) + line('Cc', people(m.cc)) + line('Date', formatDate(m.date, true))
			+ line('Pièces jointes', (m.attachments || []).map((a) => a.name).join(', '))
			+ '</table><hr>' + (m.body || '') + '</body></html>')
		w.document.close()
		w.focus()
		setTimeout(() => w.print(), 300)
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
		updateListItem(m.id, { isRead: false })
		const cached = msgCache.get(msgKey(m.id))
		if (cached) cached.isRead = false
		const f = currentFolder()
		if (f) { f.unread++; renderNav() }
		state.selectedId = null
		markSelected(null)
		renderEmptyDetail()
		setView('list')
		try {
			await api('/accounts/' + state.accountId + '/flags', { method: 'POST', body: { itemIds: [m.id], flag: 'read', value: false } })
		} catch (e) {
			updateListItem(m.id, { isRead: true })
			if (f && f.unread > 0) { f.unread--; renderNav() }
			toast(e.message, true)
		}
	}

	/**
	 * Take messages out of the list at once. When the open message goes, the
	 * next one opens (like Outlook) on a large screen.
	 * @return {function} undo
	 */
	function removeFromList(ids) {
		const before = { messages: state.messages.slice(), total: state.total, selectedId: state.selectedId }
		const i = state.messages.findIndex((m) => m.id === state.selectedId)
		state.messages = state.messages.filter((m) => !ids.includes(m.id))
		state.total = Math.max(0, state.total - ids.length)
		ids.forEach((id) => msgCache.delete(msgKey(id)))
		renderList(false)
		saveListCache()
		if (ids.includes(state.selectedId)) {
			const next = !isMobile() && i >= 0 ? (state.messages[i] || state.messages[i - 1]) : null
			if (next) {
				openMessage(next.id)
			} else {
				state.selectedId = null
				renderEmptyDetail()
				setView('list')
			}
		}
		return () => {
			state.messages = before.messages
			state.total = before.total
			renderList(false)
			saveListCache()
			if (before.selectedId && ids.includes(before.selectedId)) openMessage(before.selectedId)
		}
	}

	/**
	 * « Courrier indésirable » (blocks the sender), or in that folder « Pas
	 * indésirable » (trusts the sender, back to the inbox), like Outlook.
	 */
	function junkButton(ids, role, getIds) {
		if (role === 'sentitems' || role === 'drafts' || role === 'deleteditems') return null
		const notJunk = role === 'junkemail'
		return h('button', {
			type: 'button',
			class: 'wm-junk-btn' + (getIds ? ' wm-small' : ''),
			title: notJunk ? 'Remettre dans la boîte de réception et faire confiance à l\'expéditeur' : 'Déplacer dans « Courrier indésirable » et bloquer l\'expéditeur',
			onclick: () => markJunk(getIds ? getIds() : ids, !notJunk),
		}, notJunk ? '✔ Pas indésirable' : '⛔ Indésirable')
	}

	async function markJunk(ids, junk) {
		ids.forEach((id) => state.checked.delete(id))
		const undo = removeFromList(ids)
		try {
			await api('/accounts/' + state.accountId + '/junk', { method: 'POST', body: { itemIds: ids, junk } })
			const n = ids.length > 1 ? ids.length + ' messages' : 'Message'
			toast(junk
				? n + ' dans « Courrier indésirable » ; ' + (ids.length > 1 ? 'leurs expéditeurs sont bloqués' : 'l\'expéditeur est bloqué')
				: n + ' remis dans la boîte de réception ; ' + (ids.length > 1 ? 'leurs expéditeurs sont' : 'l\'expéditeur est') + ' considéré' + (ids.length > 1 ? 's' : '') + ' comme fiable' + (ids.length > 1 ? 's' : ''))
			state.folders.filter((f) => f.role === (junk ? 'junkemail' : 'inbox')).forEach((f) => listCache.delete(state.accountId + '|' + f.id))
			loadFolders()
		} catch (e) {
			undo()
			toast(e.message, true)
		}
	}

	async function moveMessages(ids, target, targetName) {
		const undo = removeFromList(ids)
		try {
			await api('/accounts/' + state.accountId + '/move', { method: 'POST', body: { itemIds: ids, target } })
			const name = targetName || folderName(target)
			toast((ids.length > 1 ? ids.length + ' messages déplacés' : 'Message déplacé') + (name ? ' vers « ' + name + ' »' : ''))
			listCache.delete(state.accountId + '|' + target)
			loadFolders()
		} catch (e) {
			undo()
			toast(e.message, true)
		}
	}

	async function deleteMessages(ids, permanent) {
		if (permanent && !window.confirm(ids.length > 1 ? 'Supprimer définitivement ces ' + ids.length + ' messages ?' : 'Supprimer définitivement ce message ?')) return
		ids.forEach((id) => state.checked.delete(id))
		const undo = removeFromList(ids)
		try {
			await api('/accounts/' + state.accountId + '/delete', { method: 'POST', body: { itemIds: ids, permanent: !!permanent } })
			const n = ids.length > 1 ? ids.length + ' messages ' : 'Message '
			toast(permanent ? n + (ids.length > 1 ? 'supprimés définitivement' : 'supprimé définitivement')
				: n + (ids.length > 1 ? 'placés' : 'placé') + ' dans les éléments supprimés')
			const trash = state.folders.find((f) => f.role === 'deleteditems')
			if (trash) listCache.delete(state.accountId + '|' + trash.id)
			loadFolders()
		} catch (e) {
			undo()
			toast(e.message, true)
		}
	}

	function attachmentUrl(a, inline) {
		return OC.generateUrl(BASE + '/accounts/' + state.accountId + '/attachment') + '?attachmentId=' + encodeURIComponent(a.id) + (inline ? '&inline=1' : '')
	}

	const PREVIEW_TEXT_MAX = 1024 * 1024
	/** image | pdf | text, or null when the browser cannot show it safely */
	function previewKind(a) {
		if (a.isItem) return null
		const type = (a.contentType || '').toLowerCase()
		const ext = (a.name || '').toLowerCase().split('.').pop()
		if (['image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp', 'image/bmp'].includes(type)) return 'image'
		if (type === 'application/pdf' || ext === 'pdf') return 'pdf'
		if ((type.startsWith('text/plain') || type === 'text/csv' || ['txt', 'csv', 'log'].includes(ext)) && a.size <= PREVIEW_TEXT_MAX) return 'text'
		return null
	}

	/**
	 * Full-screen preview of the attachments that can be shown (images, PDF,
	 * plain text), with ← / → to go from one to the next.
	 */
	function openPreview(items, index) {
		let i = index
		const overlay = h('div', { class: 'wm-overlay wm-preview' })
		const title = h('h2', { class: 'wm-preview-title' })
		const counter = h('span', { class: 'wm-muted' })
		const body = h('div', { class: 'wm-preview-body' })
		const download = h('a', { class: 'button', download: '' }, '⬇ Télécharger')
		const save = h('button', { type: 'button', onclick: () => saveAttachment(items[i]) }, 'Enregistrer dans Fichiers')
		const prev = h('button', { type: 'button', class: 'wm-preview-nav', 'aria-label': 'Pièce jointe précédente', onclick: () => go(-1) }, '‹')
		const next = h('button', { type: 'button', class: 'wm-preview-nav', 'aria-label': 'Pièce jointe suivante', onclick: () => go(1) }, '›')
		const close = () => { overlay.remove(); document.removeEventListener('keydown', onKey, true) }
		const onKey = (e) => {
			if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close() } else if (e.key === 'ArrowLeft') { e.preventDefault(); e.stopPropagation(); go(-1) } else if (e.key === 'ArrowRight') { e.preventDefault(); e.stopPropagation(); go(1) }
		}
		const go = (step) => {
			if (items.length < 2) return
			i = (i + step + items.length) % items.length
			show()
		}
		let seq = 0
		const show = async () => {
			const a = items[i]
			const mine = ++seq
			title.textContent = a.name
			counter.textContent = items.length > 1 ? (i + 1) + ' / ' + items.length : ''
			download.href = attachmentUrl(a)
			download.setAttribute('download', a.name)
			prev.hidden = next.hidden = items.length < 2
			clear(body)
			const kind = previewKind(a)
			if (kind === 'image') {
				body.appendChild(h('img', { class: 'wm-preview-image', src: attachmentUrl(a, true), alt: a.name }))
			} else if (kind === 'pdf') {
				body.appendChild(h('iframe', { class: 'wm-preview-pdf', src: attachmentUrl(a, true), title: a.name }))
			} else {
				body.appendChild(h('div', { class: 'wm-center' }, h('span', { class: 'icon-loading' })))
				try {
					const res = await fetch(attachmentUrl(a), { headers: { requesttoken: OC.requestToken } })
					if (!res.ok) throw new Error('Erreur ' + res.status)
					const text = await res.text()
					if (mine !== seq) return
					clear(body)
					// shown as text only: nothing in the file is interpreted
					body.appendChild(h('pre', { class: 'wm-preview-text' }, text))
				} catch (e) {
					if (mine !== seq) return
					clear(body)
					body.appendChild(h('div', { class: 'wm-empty' }, 'Aperçu impossible : ' + e.message))
				}
			}
		}
		overlay.addEventListener('mousedown', (e) => { if (e.target === overlay || e.target === body) close() })
		document.addEventListener('keydown', onKey, true)
		overlay.appendChild(h('div', { class: 'wm-preview-frame', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Aperçu de la pièce jointe' },
			h('div', { class: 'wm-preview-header' }, h('div', { class: 'wm-preview-names' }, title, counter),
				download, save, h('button', { type: 'button', class: 'wm-modal-close', 'aria-label': 'Fermer', onclick: close }, '✕')),
			h('div', { class: 'wm-preview-stage' }, prev, body, next)))
		document.body.appendChild(overlay)
		show()
	}

	/** Save several attachments in one Files folder chosen once. */
	function saveAllAttachments(list) {
		const doSave = async (dir) => {
			let done = 0
			let lastPath = ''
			for (const a of list) {
				try {
					const res = await api('/accounts/' + state.accountId + '/attachment/save', { method: 'POST', body: { attachmentId: a.id, path: dir } })
					done++
					lastPath = res.path
				} catch (e) { toast(a.name + ' : ' + e.message, true) }
			}
			if (done) toast(done + ' pièce' + (done > 1 ? 's jointes enregistrées' : ' jointe enregistrée') + ' dans ' + lastPath.replace(/\/[^/]*$/, '/'))
		}
		if (OC.dialogs && typeof OC.dialogs.filepicker === 'function') {
			OC.dialogs.filepicker('Choisir le dossier de destination', (dir) => doSave(dir || '/'), false, 'httpd/unix-directory', true, OC.dialogs.FILEPICKER_TYPE_CHOOSE)
		} else {
			doSave('/Pièces jointes')
		}
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

	/** Largest side of an image put in a message, in pixels (a photo from a phone is reduced). */
	const IMAGE_MAX_SIDE = 1600
	const IMAGE_MAX_BYTES = 5 * 1024 * 1024

	/**
	 * An image file as a data: URL for the editor. Photos larger than
	 * IMAGE_MAX_SIDE are reduced (JPEG); screenshots stay PNG when they are
	 * light enough; GIFs are kept as they are (animation).
	 */
	function imageDataUrl(file) {
		const read = (blob) => new Promise((resolve, reject) => {
			const r = new FileReader()
			r.onload = () => resolve(r.result)
			r.onerror = () => reject(new Error('Image illisible : ' + file.name))
			r.readAsDataURL(blob)
		})
		const tooBig = () => new Error('Image trop lourde (' + formatSize(file.size) + ') : joignez-la plutôt en pièce jointe')
		if (file.type === 'image/gif') {
			if (file.size > IMAGE_MAX_BYTES) return Promise.reject(tooBig())
			return read(file)
		}
		return read(file).then((url) => new Promise((resolve, reject) => {
			const img = new Image()
			img.onerror = () => reject(new Error('Image illisible : ' + file.name))
			img.onload = () => {
				const scale = Math.min(1, IMAGE_MAX_SIDE / Math.max(img.naturalWidth, img.naturalHeight))
				if (scale === 1 && file.size <= 1024 * 1024) { resolve(url); return }
				const canvas = document.createElement('canvas')
				canvas.width = Math.max(1, Math.round(img.naturalWidth * scale))
				canvas.height = Math.max(1, Math.round(img.naturalHeight * scale))
				const ctx = canvas.getContext('2d')
				// JPEG has no transparency: white behind
				ctx.fillStyle = '#fff'
				ctx.fillRect(0, 0, canvas.width, canvas.height)
				ctx.drawImage(img, 0, 0, canvas.width, canvas.height)
				let out = file.type === 'image/png' ? canvas.toDataURL('image/png') : ''
				if (!out || out.length * 0.75 > 1024 * 1024) out = canvas.toDataURL('image/jpeg', 0.85)
				if (out.length * 0.75 > IMAGE_MAX_BYTES) { reject(tooBig()); return }
				resolve(out)
			}
			img.src = url
		}))
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

		const titles = { new: 'Nouveau message', reply: 'Répondre', replyAll: 'Répondre à tous', forward: 'Transférer', draft: 'Brouillon' }
		// sender: the account, or one of its shared mailboxes (by default the one the original message is in)
		const mailboxes = shared.accountId === state.accountId ? shared.list : []
		const fromMailbox = original && mailboxes.find((mb) => (mb.folders || []).some((f) => f.id === original.folderId))
		const defaultFrom = (fromMailbox || (mode === 'new' && currentMailbox()) || null)
		const fromSelect = mailboxes.length ? h('select', { name: 'from', class: 'wm-from', 'aria-label': 'De' },
			h('option', { value: account ? account.email : '' }, account ? account.email : ''),
			mailboxes.map((mb) => h('option', { value: mb.email, selected: defaultFrom === mb }, mb.name !== mb.email ? mb.name + ' <' + mb.email + '>' : mb.email))) : null
		const fromField = fromSelect
			? h('label', { class: 'wm-muted wm-from-label' }, 'De : ', fromSelect)
			: h('span', { class: 'wm-muted' }, 'De : ' + (account ? account.email : ''))
		const isResponse = mode === 'reply' || mode === 'replyAll'
		// a draft opened from « Brouillons »: original is the draft itself
		const isDraft = mode === 'draft'
		let draftId = isDraft ? original.id : null

		const to = h('input', { type: 'text', name: 'to', placeholder: 'adresse@exemple.fr, …', autocomplete: 'email', multiple: true })
		const cc = h('input', { type: 'text', name: 'cc', placeholder: '' })
		const bcc = h('input', { type: 'text', name: 'bcc', placeholder: '' })
		const subject = h('input', { type: 'text', name: 'subject' })
		const body = h('div', {
			class: 'wm-editor',
			contenteditable: 'true',
			role: 'textbox',
			'aria-multiline': 'true',
			'aria-label': 'Message',
			spellcheck: 'true',
		})
		const files = h('input', { type: 'file', multiple: true, class: 'wm-file-input', 'aria-label': 'Joindre des fichiers' })
		const attachList = h('ul', { class: 'wm-compose-attachments' })
		const importance = h('select', { name: 'importance', 'aria-label': 'Importance' },
			h('option', { value: 'Normal' }, 'Importance normale'),
			h('option', { value: 'High' }, 'Importance haute'),
			h('option', { value: 'Low' }, 'Importance faible'))

		const textHtml = (t) => escapeHtml(t).replace(/\n/g, '<br>')
		const addresses = (list) => (list || []).map((p) => (p.name && p.name !== p.email ? p.name + ' <' + p.email + '>' : p.email)).join(', ')
		if (isDraft) {
			to.value = addresses(original.to)
			cc.value = addresses(original.cc)
			bcc.value = addresses(original.bcc)
			subject.value = original.subject || ''
			importance.value = original.importance || 'Normal'
			body.innerHTML = original.body || ''
		} else {
			if (original) {
				const prefix = mode === 'forward' ? 'TR : ' : 'RE : '
				subject.value = /^(re|tr|fw|fwd)\s?:/i.test(original.subject || '') ? original.subject : prefix + (original.subject || '')
				subject.disabled = true
			}
			body.innerHTML = '<div><br></div>' + (account && account.signature ? '<div><br></div><div>-- <br>' + textHtml(account.signature) + '</div>' : '')
			if (prefill) {
				if (prefill.to) to.value = prefill.to
				if (prefill.subject) subject.value = prefill.subject
				if (prefill.body) body.innerHTML = '<div>' + textHtml(prefill.body) + '</div>' + body.innerHTML
			}
		}

		// ---- formatting toolbar (bold, italic, underline, lists, link, quote, clear)
		const exec = (cmd, value) => { body.focus(); document.execCommand(cmd, false, value) }
		const tool = (label, title, action) => h('button', {
			type: 'button',
			class: 'wm-editor-btn',
			title,
			'aria-label': title,
			onmousedown: (e) => e.preventDefault(), // keep the selection in the text
			onclick: action,
		}, label)
		let savedImageRange = null
		const imagePicker = h('input', { type: 'file', accept: 'image/png,image/jpeg,image/gif,image/webp', multiple: true, hidden: true, name: 'inlineImages', 'aria-label': 'Insérer une image' })
		const editorBar = h('div', { class: 'wm-editor-bar', role: 'toolbar', 'aria-label': 'Mise en forme' },
			tool('G', 'Gras (Ctrl+B)', () => exec('bold')),
			tool('I', 'Italique (Ctrl+I)', () => exec('italic')),
			tool('S', 'Souligné (Ctrl+U)', () => exec('underline')),
			tool('•', 'Liste à puces', () => exec('insertUnorderedList')),
			tool('1.', 'Liste numérotée', () => exec('insertOrderedList')),
			tool('🔗', 'Insérer un lien', () => {
				const sel = window.getSelection()
				const range = sel && sel.rangeCount ? sel.getRangeAt(0) : null
				const url = (window.prompt('Adresse du lien (https://…)', 'https://') || '').trim()
				if (!/^(https?:\/\/|mailto:)\S+$/i.test(url)) return
				body.focus()
				if (range) { sel.removeAllRanges(); sel.addRange(range) }
				if (range && !range.collapsed) exec('createLink', url)
				else exec('insertHTML', '<a href="' + escapeHtml(url) + '">' + escapeHtml(url) + '</a>')
			}),
			tool('❝', 'Citation', () => exec('formatBlock', 'blockquote')),
			tool('🖼', 'Insérer une image dans le texte (ou coller une capture d\'écran)', () => {
				const sel = window.getSelection()
				savedImageRange = sel && sel.rangeCount && body.contains(sel.anchorNode) ? sel.getRangeAt(0) : null
				imagePicker.click()
			}),
			tool('⌫', 'Effacer la mise en forme', () => { exec('removeFormat'); exec('unlink') }),
			imagePicker)
		body.addEventListener('keydown', (e) => {
			if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); form.requestSubmit() }
		})

		// ---- images in the text: pasted (screenshot), dropped, or chosen; sent as inline images (cid:)
		async function insertImages(list) {
			const images = Array.from(list).filter((f) => /^image\/(png|jpeg|gif|webp)$/.test(f.type))
			for (const f of images) {
				try {
					const url = await imageDataUrl(f)
					body.focus()
					if (savedImageRange) { const sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(savedImageRange); savedImageRange = null }
					exec('insertHTML', '<img src="' + url + '" alt="' + escapeHtml(f.name && f.name !== 'image.png' ? f.name : 'image') + '" style="max-width:100%">')
				} catch (e) {
					toast(e.message, true)
				}
			}
			return images.length
		}
		body.addEventListener('paste', (e) => {
			const items = Array.from((e.clipboardData && e.clipboardData.files) || [])
			// a copied file or screenshot (the HTML of a copied web page keeps its own images)
			if (items.some((f) => f.type.startsWith('image/')) && !(e.clipboardData.types || []).includes('text/html')) {
				e.preventDefault()
				insertImages(items)
			}
		})
		body.addEventListener('dragover', (e) => {
			if (e.dataTransfer && Array.from(e.dataTransfer.types || []).includes('Files')) e.preventDefault()
		})
		body.addEventListener('drop', (e) => {
			const list = Array.from((e.dataTransfer && e.dataTransfer.files) || [])
			if (!list.some((f) => f.type.startsWith('image/'))) return
			e.preventDefault()
			e.stopPropagation()
			// where the image was dropped
			const range = document.caretRangeFromPoint ? document.caretRangeFromPoint(e.clientX, e.clientY) : null
			if (range) { const sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(range) }
			insertImages(list)
			// the other files are attached as usual
			const others = list.filter((f) => !/^image\/(png|jpeg|gif|webp)$/.test(f.type))
			if (others.length) {
				const dt = new DataTransfer()
				Array.from(files.files).concat(others).forEach((f) => dt.items.add(f))
				files.files = dt.files
				renderAttachList()
			}
		})
		imagePicker.addEventListener('change', () => { insertImages(imagePicker.files); imagePicker.value = '' })

		// ---- Nextcloud share links instead of heavy attachments
		// where the user was typing, to put the links there
		let lastRange = null
		const trackCaret = () => {
			if (!body.isConnected) { document.removeEventListener('selectionchange', trackCaret); return }
			const sel = window.getSelection()
			if (sel && sel.rangeCount && body.contains(sel.getRangeAt(0).commonAncestorContainer)) lastRange = sel.getRangeAt(0).cloneRange()
		}
		document.addEventListener('selectionchange', trackCaret)
		const insertLinks = (links) => {
			const html = links.map((l) => '<div>' + (l.isFolder ? '📁 ' : '📎 ') + '<a href="' + escapeHtml(l.url) + '">' + escapeHtml(l.name) + '</a>'
				+ (l.isFolder ? '' : ' (' + formatSize(l.size) + ')')
				+ (l.expires ? ' — lien valable jusqu\'au ' + new Date(l.expires + 'T12:00:00').toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' }) : '')
				+ '</div>').join('')
			const tmp = document.createElement('div')
			tmp.innerHTML = html
			const nodes = [...tmp.childNodes]
			if (lastRange && body.contains(lastRange.commonAncestorContainer)) {
				// after the block where the caret was
				let block = lastRange.endContainer
				while (block.parentNode && block.parentNode !== body) block = block.parentNode
				if (block === body) body.prepend(...nodes)
				else block.after(...nodes)
			} else {
				// before the signature, else at the end of the text
				const sig = [...body.children].find((d) => /^--\s/.test(d.textContent))
				const anchor = sig && sig.previousElementSibling && !sig.previousElementSibling.textContent.trim() ? sig.previousElementSibling : sig
				if (anchor) anchor.before(...nodes)
				else body.append(...nodes)
			}
			changed()
		}
		const shareLinks = async (paths) => {
			const links = []
			for (const path of paths) {
				try {
					links.push(await api('/share-link', { method: 'POST', body: { path } }))
				} catch (e) {
					toast(e.message, true)
				}
			}
			if (links.length) {
				insertLinks(links)
				toast(links.length > 1 ? links.length + ' liens ajoutés au message' : 'Lien ajouté au message')
			}
			return links.length === paths.length
		}
		const pickLinks = () => {
			if (!(OC.dialogs && OC.dialogs.filepicker)) { toast('Sélecteur de fichiers indisponible', true); return }
			OC.dialogs.filepicker('Envoyer un lien Nextcloud', (paths) => {
				shareLinks((Array.isArray(paths) ? paths : [paths]).filter(Boolean))
			}, true, null, true, OC.dialogs.FILEPICKER_TYPE_CHOOSE)
		}
		// a file of the computer: put in Files (« Pièces jointes envoyées »), then its link
		const LINK_FOLDER = 'Pièces jointes envoyées'
		const uploadAsLink = async (file, button) => {
			button.disabled = true
			button.textContent = 'Envoi dans Fichiers…'
			try {
				const base = OC.linkToRemote('dav') + '/files/' + encodeURIComponent(OC.getCurrentUser().uid) + '/' + encodeURIComponent(LINK_FOLDER)
				const headers = { requesttoken: OC.requestToken }
				await fetch(base, { method: 'MKCOL', headers }) // 405 when it exists already
				const name = file.name.replace(/[\\/]/g, '_')
				const put = await fetch(base + '/' + encodeURIComponent(name), { method: 'PUT', headers, body: file })
				if (!put.ok) throw new Error('Dépôt dans Fichiers impossible (' + put.status + ')')
				if (await shareLinks(['/' + LINK_FOLDER + '/' + name])) {
					const dt = new DataTransfer()
					Array.from(files.files).filter((f) => f !== file).forEach((f) => dt.items.add(f))
					files.files = dt.files
					renderAttachList()
				}
			} catch (e) {
				toast(e.message, true)
				button.disabled = false
				button.textContent = '🔗 Envoyer en lien'
			}
		}
		// Exchange (OVH) refuses messages over about 25 MB once encoded
		const HEAVY = 15 * 1024 * 1024

		const renderAttachList = () => {
			clear(attachList)
			const local = Array.from(files.files)
			const total = local.reduce((n, f) => n + f.size, 0)
			local.forEach((f) => {
				const toLink = h('button', { type: 'button', class: 'wm-small wm-to-link', onclick: (e) => uploadAsLink(f, e.currentTarget) }, '🔗 Envoyer en lien')
				attachList.appendChild(h('li', { class: f.size >= HEAVY ? 'wm-heavy' : '' }, '📎 ' + f.name + ' (' + formatSize(f.size) + ') ', toLink))
			})
			ncFiles.forEach((p, i) => attachList.appendChild(h('li', {}, '☁️ ' + p + ' ',
				h('button', { type: 'button', class: 'wm-small wm-to-link', onclick: async () => { if (await shareLinks([p])) { ncFiles.splice(ncFiles.indexOf(p), 1); renderAttachList() } } }, '🔗 en lien'),
				' ',
				h('button', { type: 'button', class: 'wm-small', onclick: () => { ncFiles.splice(i, 1); renderAttachList() } }, 'retirer'))))
			if (total >= HEAVY) {
				attachList.appendChild(h('li', { class: 'wm-warning wm-heavy-warning' },
					'Pièces jointes lourdes (' + formatSize(total) + ') : Exchange peut refuser le message. Préférez « 🔗 Envoyer en lien » : le fichier est déposé dans vos Fichiers et le destinataire reçoit un lien de téléchargement.'))
			}
		}
		files.addEventListener('change', renderAttachList)

		const pickFromFiles = () => {
			if (!(OC.dialogs && OC.dialogs.filepicker)) { toast('Sélecteur de fichiers indisponible', true); return }
			OC.dialogs.filepicker('Joindre depuis Fichiers', (paths) => {
				(Array.isArray(paths) ? paths : [paths]).forEach((p) => { if (p && !ncFiles.includes(p)) ncFiles.push(p) })
				renderAttachList()
			}, true, null, true, OC.dialogs.FILEPICKER_TYPE_CHOOSE)
		}

		// ---- drafts: saved a few seconds after the last change, and when leaving
		const draftStatus = h('span', { class: 'wm-draft-status wm-muted', 'aria-live': 'polite' })
		const deleteDraftBtn = h('button', { type: 'button', class: 'wm-danger', hidden: !draftId, onclick: () => discardDraft() }, '🗑 Supprimer le brouillon')
		const DRAFT_DELAY = 3000
		let draftTimer = null
		let dirty = false
		let closed = false
		let saving = null
		const fields = () => ({
			mode,
			to: to.value,
			cc: cc.value,
			bcc: bcc.value,
			subject: subject.value,
			bodyHtml: body.innerHTML,
			importance: importance.value,
			referenceId: original && !isDraft ? original.id : '',
			draftId: draftId || '',
			from: fromSelect ? fromSelect.value : '',
		})
		const saveDraft = () => {
			clearTimeout(draftTimer)
			if (!dirty) return saving || Promise.resolve()
			dirty = false
			const run = async () => {
				draftStatus.textContent = 'Enregistrement du brouillon…'
				try {
					const r = await api('/accounts/' + state.accountId + '/draft', { method: 'POST', body: fields() })
					draftId = r.draftId
					deleteDraftBtn.hidden = false
					draftStatus.textContent = 'Brouillon enregistré à ' + new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
					const drafts = state.folders.find((f) => f.role === 'drafts')
					if (drafts) listCache.delete(state.accountId + '|' + drafts.id)
				} catch (err) {
					dirty = true
					draftStatus.textContent = 'Brouillon non enregistré : ' + err.message
				}
			}
			// one save at a time, in order
			saving = (saving || Promise.resolve()).then(run).finally(() => { saving = null })
			return saving
		}
		const changed = () => {
			if (closed) return
			dirty = true
			clearTimeout(draftTimer)
			draftTimer = setTimeout(saveDraft, DRAFT_DELAY)
		}
		;[to, cc, bcc, subject, body].forEach((el) => el.addEventListener('input', changed))
		importance.addEventListener('change', changed)
		const leave = () => {
			closed = true
			clearTimeout(draftTimer)
			if (folderRole() === 'drafts') loadMessages(true)
			if (state.current && !isDraft) renderMessage(); else renderEmptyDetail()
			setView(state.current && !isDraft ? 'detail' : 'list')
		}
		const cancel = async () => {
			if (dirty || saving) {
				await saveDraft()
				if (draftId && !dirty) toast('Brouillon enregistré dans « Brouillons »')
			}
			leave()
		}
		const discardDraft = async () => {
			if (!window.confirm('Supprimer ce brouillon ?')) return
			closed = true
			clearTimeout(draftTimer)
			await saving
			try {
				if (draftId) await api('/accounts/' + state.accountId + '/draft/delete', { method: 'POST', body: { draftId } })
				toast('Brouillon supprimé')
				if (isDraft) { state.selectedId = null; removeFromList([draftId]) }
			} catch (err) { toast(err.message, true) }
			leave()
		}

		// send options: read receipt, later delivery
		const receipt = h('input', { type: 'checkbox', name: 'readReceipt' })
		const later = h('input', { type: 'checkbox', name: 'sendLater' })
		const laterAt = h('input', { type: 'datetime-local', name: 'sendAt', hidden: true })
		later.addEventListener('change', () => {
			laterAt.hidden = !later.checked
			if (later.checked && !laterAt.value) {
				const d = new Date(Date.now() + 86400000)
				d.setHours(8, 0, 0, 0)
				const p = (n) => String(n).padStart(2, '0')
				laterAt.value = d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T08:00'
			}
			sendBtn.textContent = later.checked ? 'Programmer l\'envoi' : 'Envoyer'
		})
		const sendOptions = h('div', { class: 'wm-send-options' },
			h('label', { class: 'wm-radio' }, receipt, ' Demander un accusé de lecture'),
			h('label', { class: 'wm-radio' }, later, ' Envoyer plus tard'), laterAt)
		const sendBtn = h('button', { type: 'submit', class: 'primary', title: 'Envoyer (Ctrl+Entrée)' }, 'Envoyer')
		const form = h('form', {
			class: 'wm-compose',
			onsubmit: async (e) => {
				e.preventDefault()
				// a save in progress must end first: the draft it creates is deleted after sending
				clearTimeout(draftTimer)
				await saving
				const fd = new FormData()
				Object.entries(fields()).forEach(([k, v]) => fd.append(k, v))
				if (receipt.checked) fd.append('readReceipt', '1')
				if (later.checked) {
					const at = new Date(laterAt.value)
					if (!laterAt.value || isNaN(at) || at <= new Date()) { toast('Choisissez une date et une heure d\'envoi à venir', true); return }
					fd.append('sendAt', at.toISOString())
				}
				Array.from(files.files).forEach((f) => fd.append('files[]', f, f.name))
				ncFiles.forEach((p) => fd.append('filePaths[]', p))
				sendBtn.disabled = true
				sendBtn.textContent = 'Envoi…'
				closed = true
				try {
					const sent = await api('/accounts/' + state.accountId + '/send', { method: 'POST', body: fd })
					threadCache.clear() // the reply now belongs to its conversation
					toast(sent && sent.sendAt
						? 'Envoi programmé le ' + new Date(sent.sendAt).toLocaleString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' }) + ' (dans « Boîte d\'envoi » d\'ici là)'
						: 'Message envoyé')
					if (isDraft) { state.selectedId = null; removeFromList([draftId]) }
					// meanwhile another message may have been opened or started: leave it
					if (form.isConnected) { if (state.current && !isDraft) renderMessage(); else renderEmptyDetail() }
					if (folderRole() === 'sentitems') setTimeout(() => loadMessages(true), 1500)
				} catch (err) {
					closed = false
					toast(err.message, true)
					sendBtn.disabled = false
					sendBtn.textContent = 'Envoyer'
				}
			},
		},
		h('div', { class: 'wm-compose-title' },
			h('button', { type: 'button', class: 'wm-back wm-mobile-only', onclick: cancel, 'aria-label': 'Retour' }, '←'),
			h('h2', {}, titles[mode]),
			fromField,
			draftStatus),
		isResponse
			? h('div', { class: 'wm-info' }, mode === 'reply'
				? 'Réponse à : ' + personLabel((original.replyTo && original.replyTo[0]) || original.from)
				: 'Réponse à : ' + [original.from].concat(original.to || [], original.cc || []).filter(Boolean).map(personShort).join(', '))
			: h('label', { class: 'wm-field' }, h('span', {}, 'À'), attachAutocomplete(to)),
		h('label', { class: 'wm-field' }, h('span', {}, isResponse ? 'Ajouter en Cc' : 'Cc'), attachAutocomplete(cc)),
		h('label', { class: 'wm-field' }, h('span', {}, 'Cci'), attachAutocomplete(bcc)),
		h('label', { class: 'wm-field' }, h('span', {}, 'Objet'), subject),
		h('div', { class: 'wm-editor-wrap' }, editorBar, body),
		isDraft && original.attachments && original.attachments.length
			? h('div', { class: 'wm-info wm-muted' }, 'Déjà joint : ' + original.attachments.map((a) => a.name).join(', '))
			: null,
		original && !isDraft ? h('div', { class: 'wm-info wm-muted' }, mode === 'forward'
			? 'Le message d\'origine et ses pièces jointes seront ajoutés automatiquement sous votre texte.'
			: 'Le message d\'origine sera cité automatiquement sous votre réponse.') : null,
		attachList,
		sendOptions,
		h('div', { class: 'wm-compose-actions' },
			sendBtn,
			h('label', { class: 'wm-file-label button' }, '📎 Joindre', files),
			h('button', { type: 'button', onclick: pickFromFiles }, '☁️ Depuis Fichiers'),
			h('button', { type: 'button', class: 'wm-link-btn', onclick: pickLinks, title: 'Un lien de téléchargement Nextcloud dans le message, au lieu d\'une pièce jointe' }, '🔗 Lien Nextcloud'),
			importance,
			h('button', { type: 'button', onclick: cancel, title: 'Fermer (le brouillon est gardé)' }, 'Annuler'),
			deleteDraftBtn),
		)
		detailEl.appendChild(form)
		if (mode === 'new' || mode === 'forward' || (isDraft && !to.value)) {
			to.focus()
		} else {
			// caret at the start of the text, above the signature / quote
			body.focus()
			const range = document.createRange()
			range.setStart(body, 0)
			range.collapse(true)
			const sel = window.getSelection()
			sel.removeAllRanges()
			sel.addRange(range)
		}
	}

	function escapeHtml(t) {
		return String(t).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]))
	}

	// ------------------------------------------------------------------
	// accounts
	// ------------------------------------------------------------------

	/** The shared mailboxes of an account, in its settings: add by address, remove. */
	function sharedMailboxesEditor(a) {
		const box = h('div', { class: 'wm-shared-editor' }, h('span', { class: 'wm-shared-editor-title' }, '📮 Boîtes partagées'))
		const list = h('ul', { class: 'wm-shared-list' })
		const email = h('input', { type: 'email', name: 'sharedMailbox', placeholder: 'contact@exemple.fr' })
		const err = h('div', { class: 'wm-form-error' })
		const add = h('button', { type: 'button', class: 'wm-small' }, 'Ajouter')
		const show = (mailboxes) => {
			clear(list)
			mailboxes.forEach((m) => list.appendChild(h('li', {}, m.name !== m.email ? m.name + ' <' + m.email + '>' : m.email, ' ',
				h('button', {
					type: 'button',
					class: 'wm-small wm-icon-btn',
					'aria-label': 'Retirer ' + m.email,
					title: 'Retirer cette boîte du webmail (rien n\'est supprimé dans Exchange)',
					onclick: async () => {
						try {
							show((await api('/accounts/' + a.id + '/mailboxes/delete', { method: 'POST', body: { email: m.email } })).mailboxes)
							if (a.id === state.accountId) loadShared(a.id)
						} catch (e) { toast(e.message, true) }
					},
				}, '✕'))))
			if (!mailboxes.length) list.appendChild(h('li', { class: 'wm-muted' }, 'Aucune : ajoutez une boîte commune (contact@, accueil@…) dont l\'administrateur Exchange vous a donné l\'accès.'))
		}
		add.addEventListener('click', async () => {
			err.textContent = ''
			if (!email.value.trim()) return
			add.disabled = true
			try {
				show((await api('/accounts/' + a.id + '/mailboxes', { method: 'POST', body: { email: email.value.trim() } })).mailboxes)
				toast('Boîte partagée ajoutée : ses dossiers sont sous les vôtres')
				email.value = ''
				if (a.id === state.accountId) loadShared(a.id)
			} catch (e) {
				err.textContent = e.message
			} finally {
				add.disabled = false
			}
		})
		email.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); add.click() } })
		api('/accounts/' + a.id + '/mailboxes').then((r) => show(r.mailboxes), () => show([]))
		box.append(list, h('div', { class: 'wm-inline' }, email, add), err)
		return box
	}

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
			// saved at once: Nextcloud's notifications (the bell, the phone app) for each new message
			const bell = h('input', {
				type: 'checkbox',
				name: 'bell',
				checked: a.bell !== false,
				onchange: async () => {
					try {
						Object.assign(a, await api('/accounts/' + a.id, { method: 'PUT', body: { bell: bell.checked } }))
						toast(bell.checked ? 'Les nouveaux messages seront signalés dans Nextcloud' : 'Les nouveaux messages ne seront plus signalés dans Nextcloud')
					} catch (e) {
						bell.checked = !bell.checked
						toast(e.message, true)
					}
				},
			})
			// saved at once: the calendar reminders as Nextcloud notifications
			const remind = h('input', {
				type: 'checkbox',
				name: 'reminders',
				checked: a.reminders !== false,
				onchange: async () => {
					try {
						Object.assign(a, await api('/accounts/' + a.id, { method: 'PUT', body: { reminders: remind.checked } }))
						toast(remind.checked ? 'Les rappels des rendez-vous arriveront dans Nextcloud' : 'Les rappels des rendez-vous n\'arriveront plus dans Nextcloud')
					} catch (e) {
						remind.checked = !remind.checked
						toast(e.message, true)
					}
				},
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
				sharedMailboxesEditor(a),
				h('label', { class: 'wm-radio wm-bell' }, bell, ' 🔔 Signaler chaque nouveau message dans les notifications Nextcloud, même webmail fermé (et sur le téléphone avec l\'application Nextcloud)'),
				h('label', { class: 'wm-radio wm-bell' }, remind, ' ⏰ Rappels des rendez-vous de l\'agenda dans les notifications Nextcloud (au moment choisi pour chaque rendez-vous : 15 minutes avant…)'),
				h('div', { class: 'wm-compose-actions' }, save,
					h('button', { type: 'button', class: 'wm-oof-btn', onclick: () => openOof(a) }, '🌴 Réponse automatique'),
					h('button', { type: 'button', class: 'wm-rules-btn', onclick: () => openRules(a) }, '📋 Règles'),
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
					if (!navEl || !root.contains(navEl)) buildLayout()
					await selectAccount(acc.id)
					startBackground()
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
			if (mod === 'calendar' || mod === 'contacts' || mod === 'tasks') state.module = mod
		} catch (e) { /* ignore */ }
		// links from the Nextcloud dashboard: ?account=…&message=… or ?module=calendar
		const link = new URLSearchParams(window.location.search)
		const linkAccount = parseInt(link.get('account') || '', 10)
		if (state.accounts.some((a) => a.id === linkAccount)) saved = linkAccount
		const linkModule = link.get('module')
		if (['calendar', 'contacts', 'tasks', 'mail'].includes(linkModule)) state.module = linkModule
		else if (link.get('message')) state.module = 'mail'
		const linkMessage = link.get('message')
		const linkEvent = link.get('event')
		if (link.toString()) {
			try { history.replaceState(history.state, '', window.location.pathname) } catch (e) { /* ignore */ }
		}
		// calendar.js / contacts.js may be loaded after this file
		if (state.module !== 'mail' && !window.WebmailModules[state.module]) {
			await new Promise((resolve) => (document.readyState === 'complete' ? resolve() : window.addEventListener('load', resolve, { once: true })))
			if (!window.WebmailModules[state.module]) state.module = 'mail'
		}
		buildLayout()
		const first = state.accounts.find((a) => a.id === saved) || state.accounts[0]
		await selectAccount(first.id)
		if (linkMessage && state.module === 'mail') openMessage(linkMessage)
		if (linkEvent && state.module === 'calendar' && window.WebmailModules.calendar.openLinked) window.WebmailModules.calendar.openLinked(core, linkEvent)
		startBackground()
	}

	let backgroundStarted = false
	/**
	 * Refresh, new message checks and keyboard shortcuts: once there is an
	 * account (at start, or after the first account is added).
	 */
	function startBackground() {
		if (backgroundStarted) return
		backgroundStarted = true
		refreshTimer = setInterval(refresh, REFRESH_MS)
		document.addEventListener('visibilitychange', () => { if (!document.hidden) { refresh(); checkNewMessages() } })
		// a hidden tab without browser notifications only keeps the count in its title up to date: every 5 minutes
		let lastCheck = 0
		notifyTimer = setInterval(() => {
			if (document.hidden && !notificationsOn() && Date.now() - lastCheck < 5 * NOTIFY_MS) return
			lastCheck = Date.now()
			checkNewMessages()
		}, NOTIFY_MS)
		lastCheck = Date.now()
		checkNewMessages()
		document.addEventListener('keydown', onShortcut)
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init)
	} else {
		init()
	}
})()
