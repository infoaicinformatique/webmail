/* Webmail Exchange for Nextcloud – main UI (vanilla JS, no build step) */
(function () {
	'use strict'

	/** Translated text (Nextcloud l10n: l10n/<language>.js); the French text is the key. */
	const _ = (s, vars) => (window.t ? window.t('webmail', s, vars, undefined, { escape: false }) : s)
	const locale = () => ((window.OC && OC.getLanguage && OC.getLanguage()) || 'fr').replace('_', '-')

	const BASE = '/apps/webmail/api'
	const PAGE_SIZE = 50
	/** first rows of a folder never seen: shown sooner, the rest of the page follows at once */
	const FIRST_PAGE = 25
	/** unread messages at the top of the inbox loaded in advance while the browser is idle */
	const IDLE_PREFETCH = 5
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
		// messages put aside (« Reporter »): id in « Reportés » → when it comes back (UTC)
		snoozes: new Map(),
		showFilters: false,
		// the list grouped by conversation (one row each), remembered
		grouped: (() => { try { return localStorage.getItem('webmail.grouped') === '1' } catch (e) { return false } })(),
		/** « Trier par »: « date-desc » (the default), « from-asc », « subject-asc », « size-desc »… */
		sort: (() => { try { return localStorage.getItem('webmail.sort') || 'date-desc' } catch (e) { return 'date-desc' } })(),
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
	const MODULES = () => [
		{ id: 'mail', label: _('Courrier'), icon: '✉️' },
		{ id: 'calendar', label: _('Agenda'), icon: '📅' },
		{ id: 'contacts', label: _('Contacts'), icon: '👤' },
		{ id: 'tasks', label: _('Tâches'), icon: '✅' },
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
	const MSG_CACHE_MAX = 100
	/** "account|item id" -> pending fetch (prefetch, not marked as read) */
	const msgPending = new Map()
	let listSeq = 0
	let listObserver = null

	const listKey = () => state.accountId + '|' + state.folderId + (grouped() ? '|conversations' : (state.sort !== 'date-desc' ? '|' + state.sort : ''))
	/** The orders offered: value, label. */
	const SORTS = [
		['date-desc', () => _('Plus récents')],
		['date-asc', () => _('Plus anciens')],
		['from-asc', () => _('Expéditeur (A → Z)')],
		['from-desc', () => _('Expéditeur (Z → A)')],
		['subject-asc', () => _('Objet (A → Z)')],
		['size-desc', () => _('Plus volumineux')],
	]
	const sortField = () => (SORTS.some(([v]) => v === state.sort) ? state.sort : 'date-desc').split('-')
	/** The list shows conversations (not while searching: the results are messages). */
	const grouped = () => state.grouped && !isSearching()
	const listEndpoint = () => '/accounts/' + state.accountId + (grouped() ? '/conversations' : '/messages')
	const NO_FILTERS = { unread: false, attachments: false, flagged: false, since: '', all: false }
	const filtersOn = () => ['unread', 'attachments', 'flagged', 'since'].filter((k) => state.filters[k]).length
	/** a search or a filter is active: the list is not the folder's content */
	const isSearching = () => !!state.query || filtersOn() > 0

	/**
	 * Changes made here to messages of the list (read, flag, categories…, or taken out of it), with
	 * their time: a list asked for before them, answered after, must not undo them on screen.
	 * id -> { changes, at }
	 */
	const localEdits = new Map()
	const LOCAL_EDIT_TTL = 120000
	function noteEdit(id, changes) {
		const e = localEdits.get(id)
		localEdits.set(id, { changes: Object.assign(e ? e.changes : {}, changes), at: Date.now() })
	}
	/** The list items of an answer to a request sent at $sentAt, with the changes made since. */
	function withLocalEdits(items, sentAt) {
		const now = Date.now()
		localEdits.forEach((e, id) => { if (now - e.at > LOCAL_EDIT_TTL) localEdits.delete(id) })
		const after = (m) => { const e = localEdits.get(m.id); return e && e.at >= sentAt ? e.changes : null }
		return items.filter((m) => { const c = after(m); return !(c && c.gone) }).map((m) => {
			const c = after(m)
			if (!c) return m
			const { gone, ...rest } = c
			return Object.assign(m, rest)
		})
	}

	function resetSearch() {
		state.query = ''
		state.filters = Object.assign({}, NO_FILTERS)
		state.showFilters = false
	}

	/**
	 * The period a date belongs to, as a heading of the list: « Aujourd'hui », « Hier »,
	 * « Cette semaine », « La semaine dernière », « Ce mois-ci », then the month (« Septembre 2026 »).
	 */
	function dayPeriods() {
		const now = new Date()
		const day = (n) => new Date(now.getFullYear(), now.getMonth(), now.getDate() + n).getTime()
		const today = day(0)
		const weekStart = day(-((now.getDay() + 6) % 7)) // Monday
		const lastWeekStart = weekStart - 7 * 86400000
		const monthStart = new Date(now.getFullYear(), now.getMonth(), 1).getTime()
		return (iso) => {
			const d = new Date(iso)
			if (!iso || isNaN(d)) return null
			const t = d.getTime()
			if (t >= today) return _('Aujourd\'hui')
			if (t >= day(-1)) return _('Hier')
			if (t >= weekStart) return _('Cette semaine')
			if (t >= lastWeekStart) return _('La semaine dernière')
			if (t >= monthStart) return _('Ce mois-ci')
			const label = d.toLocaleDateString(locale(), { month: 'long', year: 'numeric' })
			return label.charAt(0).toUpperCase() + label.slice(1)
		}
	}

	/** Search while typing: the pause (ms) and the fewest characters before Exchange is asked. */
	const SEARCH_DELAY = 350
	const SEARCH_MIN = 2
	let searchTimer = null

	/** The searches made recently (per account, in this browser): offered again under the field. */
	const RECENT_SEARCHES = 8
	const searchKey = () => 'webmail.searches.' + state.accountId
	function recentSearches() {
		try {
			const v = JSON.parse(localStorage.getItem(searchKey()) || '[]')
			return Array.isArray(v) ? v.filter((x) => typeof x === 'string') : []
		} catch (e) { return [] }
	}
	function rememberSearch(q) {
		q = (q || '').trim()
		// (a category chosen from its menu is not a search typed)
		if (q.length < SEARCH_MIN || /^category:"/.test(q)) return
		const list = [q].concat(recentSearches().filter((x) => x.toLowerCase() !== q.toLowerCase())).slice(0, RECENT_SEARCHES)
		try { localStorage.setItem(searchKey(), JSON.stringify(list)) } catch (e) { /* not kept */ }
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
			sort: state.sort !== 'date-desc' && sortField()[0] !== 'date' ? sortField()[0] : undefined,
			order: state.sort === 'date-desc' ? undefined : sortField()[1],
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
		const key = listKey()
		listCache.delete(key) // most recent last
		listCache.set(key, { messages: state.messages.slice(), total: state.total, hasMore: state.hasMore, scrollTop: listEl ? listEl.scrollTop : 0 })
		persistTab()
	}

	// ------------------------------------------------------------------
	// tab memory: the lists seen (their summaries: sender, subject, date,
	// preview; never the content of the messages) and the folders, kept for
	// this tab only (sessionStorage, emptied when the tab is closed). After a
	// reload, the mailbox shows at once, then is refreshed from Exchange.
	// ------------------------------------------------------------------

	const TAB_KEY = 'webmail.tab.v1'
	const TAB_LISTS_MAX = 6 // folders kept
	const TAB_LIST_ITEMS = 100 // rows kept per folder
	/** account id -> { folders, usage } */
	const folderCache = new Map()
	let tabTimer = null
	const tabUser = () => (window.OC && OC.getCurrentUser && OC.getCurrentUser() && OC.getCurrentUser().uid) || ''

	function restoreTab() {
		try {
			const data = JSON.parse(sessionStorage.getItem(TAB_KEY) || 'null')
			if (!data) return
			// another Nextcloud user in this tab: nothing of the previous one is shown
			if (data.user !== tabUser()) { sessionStorage.removeItem(TAB_KEY); return }
			;(data.lists || []).forEach(([key, list]) => listCache.set(key, list))
			Object.entries(data.folders || {}).forEach(([id, f]) => folderCache.set(id, f))
		} catch (e) { /* storage blocked or damaged: start from Exchange */ }
	}

	/** A removed account: nothing of it is kept, in memory or in the tab. */
	function forgetAccount(id) {
		const prefix = id + '|'
		;[...listCache.keys()].filter((k) => k.startsWith(prefix)).forEach((k) => listCache.delete(k))
		;[...msgCache.keys()].filter((k) => k.startsWith(prefix)).forEach((k) => msgCache.delete(k))
		folderCache.delete(String(id))
		writeTab()
	}

	function persistTab() {
		clearTimeout(tabTimer)
		tabTimer = setTimeout(writeTab, 500)
	}

	function writeTab() {
		clearTimeout(tabTimer)
		try {
			const lists = [...listCache.entries()].slice(-TAB_LISTS_MAX).map(([key, list]) => [key, {
				messages: list.messages.slice(0, TAB_LIST_ITEMS),
				total: list.total,
				hasMore: list.hasMore || list.messages.length > TAB_LIST_ITEMS,
				scrollTop: list.scrollTop,
			}])
			sessionStorage.setItem(TAB_KEY, JSON.stringify({ user: tabUser(), lists, folders: Object.fromEntries(folderCache) }))
		} catch (e) { /* storage full or blocked: memory only */ }
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
		let res
		try {
			res = await fetch(full, init)
		} catch (e) {
			// no network (or Nextcloud unreachable): said once, in the banner, not in every toast
			if (e && e.name === 'AbortError') throw e
			setOffline(true)
			throw new Error(_('Pas de connexion'))
		}
		// a proxy in front of Nextcloud answering for it: Nextcloud is unreachable
		if (res.status === 502 || res.status === 503 || res.status === 504) {
			setOffline(true)
			throw new Error(_('Nextcloud ne répond pas (erreur {status})', { status: res.status }))
		}
		setOffline(false)
		let data = null
		try { data = await res.json() } catch (e) { /* empty */ }
		if (!res.ok) {
			throw new Error((data && data.message) || _('Erreur {status}', { status: res.status }))
		}
		return data
	}

	// ------------------------------------------------------------------
	// offline: a banner, what is already loaded stays readable, and a new try
	// every few seconds (at once when the browser says the network is back)
	// ------------------------------------------------------------------

	const RETRY_DELAYS = [5, 10, 20, 30, 60]
	const offline = { on: false, tries: 0, timer: null, tick: null, at: 0, banner: null }

	function setOffline(on) {
		if (on === offline.on) {
			if (on && !offline.timer) scheduleRetry()
			return
		}
		offline.on = on
		if (on) {
			offline.tries = 0
			scheduleRetry()
		} else {
			clearTimeout(offline.timer)
			clearInterval(offline.tick)
			offline.timer = null
			toast(_('Connexion rétablie'))
			// what changed meanwhile
			if (state.accountId && state.module === 'mail') { loadFolders(); loadMessages(true) }
		}
		renderOffline()
	}

	function scheduleRetry() {
		clearTimeout(offline.timer)
		clearInterval(offline.tick)
		const delay = RETRY_DELAYS[Math.min(offline.tries, RETRY_DELAYS.length - 1)]
		offline.at = Date.now() + delay * 1000
		offline.timer = setTimeout(retryNow, delay * 1000)
		offline.tick = setInterval(renderOffline, 1000)
		renderOffline()
	}

	/** A light request: its answer says the connection is back (api() then leaves offline mode). */
	async function retryNow() {
		clearTimeout(offline.timer)
		offline.timer = null
		offline.tries++
		try {
			await api('/accounts')
		} catch (e) {
			if (offline.on) scheduleRetry()
		}
	}

	function renderOffline() {
		if (!root) return
		if (!offline.on) {
			if (offline.banner) { offline.banner.remove(); offline.banner = null }
			root.classList.remove('wm-is-offline')
			return
		}
		root.classList.add('wm-is-offline')
		const secs = Math.max(0, Math.ceil((offline.at - Date.now()) / 1000))
		const text = _('Hors connexion : les messages déjà chargés restent lisibles.') + ' ' + (offline.timer ? _('Nouvel essai dans {secs} s.', { secs }) : _('Nouvel essai…'))
		if (!offline.banner) {
			offline.banner = h('div', { class: 'wm-offline', role: 'status', 'aria-live': 'polite' },
				h('span', { class: 'wm-offline-text' }),
				h('button', { type: 'button', class: 'wm-small', onclick: () => { clearInterval(offline.tick); retryNow(); renderOffline() } }, _('Réessayer')))
			root.prepend(offline.banner)
		}
		offline.banner.querySelector('.wm-offline-text').textContent = '⚠ ' + text
	}
	window.addEventListener('offline', () => setOffline(true))
	window.addEventListener('online', () => { if (offline.on) retryNow() })

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

	/**
	 * A short message at the bottom; with an action (« Annuler »), a button that stays a little longer
	 * (and while the pointer is on it).
	 */
	function toast(message, isError, action) {
		let box = document.getElementById('wm-toasts')
		if (!box) {
			box = h('div', { id: 'wm-toasts', 'aria-live': 'polite' })
			document.body.appendChild(box)
		}
		const t = h('div', { class: 'wm-toast' + (isError ? ' wm-toast-error' : '') + (action ? ' wm-toast-undo' : ''), role: 'status' }, message)
		box.appendChild(t)
		while (box.children.length > 3) box.firstChild.remove()
		let timers = []
		const leave = () => {
			timers.forEach(clearTimeout)
			timers = [setTimeout(() => t.classList.add('wm-toast-hide'), action ? 8000 : 4000), setTimeout(() => t.remove(), action ? 8600 : 4600)]
		}
		if (action) {
			t.appendChild(h('button', {
				type: 'button',
				class: 'wm-toast-action',
				onclick: () => { t.remove(); action.run() },
			}, action.label))
			t.addEventListener('mouseenter', () => { timers.forEach(clearTimeout); t.classList.remove('wm-toast-hide') })
			t.addEventListener('mouseleave', leave)
		}
		leave()
	}

	// ------------------------------------------------------------------
	// undo send: a few seconds to change one's mind (this browser's setting)
	// ------------------------------------------------------------------

	// ------------------------------------------------------------------
	// reply templates (of the Nextcloud user, for all accounts)
	// ------------------------------------------------------------------

	let templatesLoad = null
	function loadTemplates(force) {
		if (force || !templatesLoad) {
			templatesLoad = api('/templates').then((r) => r.templates).catch((e) => { templatesLoad = null; throw e })
		}
		return templatesLoad
	}
	async function saveTemplate(body) {
		const r = await api('/templates', { method: 'POST', body })
		templatesLoad = Promise.resolve(r.templates)
		return r.templates
	}
	async function deleteTemplate(id) {
		const r = await api('/templates/delete', { method: 'POST', body: { id } })
		templatesLoad = Promise.resolve(r.templates)
		return r.templates
	}

	/**
	 * Does the text written (not the signature nor a quotation) speak of an attachment?
	 * In French and in English, whatever the language of the page.
	 */
	const ATTACHMENT_WORDS = /\b(ci[-\s]?joint(e|s|es)?|pi[eè]ces?[-\s]jointes?|en\s+p\.?\s?j\.?|joint(e|s|es)?\s+(à|a)\s+(ce|cet|mon|notre)\s+(message|mail|e-?mail|courriel)|attached|attachments?|enclosed)\b/i
	function mentionsAttachment(editor) {
		const copy = editor.cloneNode(true)
		copy.querySelectorAll('.wm-signature, blockquote').forEach((n) => n.remove())
		return ATTACHMENT_WORDS.test(copy.textContent || '')
	}

	const UNDO_DELAYS = [0, 5, 10, 20, 30]
	function undoDelay() {
		let v = null
		try { v = localStorage.getItem('webmail.undoSend') } catch (e) { /* default */ }
		const n = v === null ? 5 : parseInt(v, 10)
		return UNDO_DELAYS.includes(n) ? n : 5
	}
	let pendingSends = 0
	window.addEventListener('beforeunload', (e) => { if (pendingSends) { e.preventDefault(); e.returnValue = '' } })

	/** « Sending in N s — Undo »: resolves true to send, false when undone. */
	function undoCountdown(seconds) {
		let box = document.getElementById('wm-toasts')
		if (!box) {
			box = h('div', { id: 'wm-toasts', 'aria-live': 'polite' })
			document.body.appendChild(box)
		}
		return new Promise((resolve) => {
			let left = seconds
			const label = h('span', {}, _('Envoi dans {seconds} s', { seconds: left }))
			const finish = (send) => { clearInterval(timer); t.remove(); pendingSends--; resolve(send) }
			const undo = h('button', { type: 'button', class: 'wm-toast-action', onclick: () => finish(false) }, _('Annuler l\'envoi'))
			const now = h('button', { type: 'button', class: 'wm-toast-action', onclick: () => finish(true) }, _('Envoyer maintenant'))
			const t = h('div', { class: 'wm-toast wm-toast-undo', role: 'status' }, label, undo, now)
			pendingSends++
			box.appendChild(t)
			const timer = setInterval(() => {
				left--
				if (left <= 0) finish(true)
				else label.textContent = _('Envoi dans {seconds} s', { seconds: left })
			}, 1000)
		})
	}

	function formatDate(iso, long) {
		if (!iso) return ''
		const d = new Date(iso)
		const now = new Date()
		if (long) {
			return d.toLocaleString(locale(), { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })
		}
		if (d.toDateString() === now.toDateString()) {
			return d.toLocaleTimeString(locale(), { hour: '2-digit', minute: '2-digit' })
		}
		if (d.getFullYear() === now.getFullYear()) {
			return d.toLocaleDateString(locale(), { day: 'numeric', month: 'short' })
		}
		return d.toLocaleDateString(locale(), { day: '2-digit', month: '2-digit', year: 'numeric' })
	}

	function formatSize(bytes) {
		if (!bytes) return ''
		if (bytes < 1024) return _('{size} o', { size: bytes })
		if (bytes < 1024 * 1024) return _('{size} Ko', { size: Math.round(bytes / 1024) })
		return _('{size} Mo', { size: (bytes / 1024 / 1024).toLocaleString(locale(), { minimumFractionDigits: 1, maximumFractionDigits: 1, useGrouping: false }) })
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
		navEl = h('nav', { class: 'wm-nav', 'aria-label': _('Dossiers') })
		listEl = h('section', { class: 'wm-list', 'aria-label': _('Messages') })
		detailEl = h('section', { class: 'wm-detail', 'aria-label': _('Lecture') })
		root.append(navEl, listEl, detailEl)
		root.dataset.module = state.module
		setView('list')
	}

	function moduleTabs() {
		return h('div', { class: 'wm-tabs', role: 'tablist' }, MODULES().map((m) => h('button', {
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
				'aria-label': _('Compte'),
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
			h('a', { href: '#', onclick: (e) => { e.preventDefault(); openAccountSettings() } }, '⚙️  ' + _('Comptes et signature')),
			h('a', { href: '#', class: 'wm-palette-link', onclick: (e) => { e.preventDefault(); openPalette() } }, '🔎  ' + _('Commandes'), h('kbd', { class: 'wm-desktop-only' }, /Mac|iPhone|iPad/.test(navigator.platform || '') ? '⌘K' : 'Ctrl+K')),
			h('a', { href: '#', class: 'wm-shortcuts-link wm-desktop-only', onclick: (e) => { e.preventDefault(); showShortcuts() } }, '⌨️  ' + _('Raccourcis clavier')),
			'Notification' in window
				? h('a', { href: '#', class: 'wm-notify-toggle', onclick: (e) => { e.preventDefault(); toggleNotifications() } },
					notificationsOn() ? '🔔  ' + _('Notifications activées') : '🔕  ' + _('Activer les notifications'))
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
			toast(_('Notifications désactivées'))
		} else {
			const permission = window.Notification.permission === 'granted' ? 'granted' : await window.Notification.requestPermission()
			if (permission !== 'granted') {
				toast(_('Le navigateur refuse les notifications pour ce site : autorisez-les dans les réglages du site (icône à gauche de l\'adresse).'), true)
				return
			}
			try { localStorage.setItem('webmail.notify', 'on') } catch (e) { /* ignore */ }
			toast(_('Vous serez prévenu des nouveaux messages tant que Nextcloud est ouvert dans un onglet'))
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
		const sender = (m) => (m.from && (m.from.name || m.from.email)) || _('Nouveau message')
		const inFront = !document.hidden && document.hasFocus()
		if (account.id === state.accountId && state.module === 'mail' && folderRole() === 'inbox' && !isSearching()) refresh()
		if (inFront || !notificationsOn()) {
			if (inFront) {
				toast(items.length > 1
					? _('{count} nouveaux messages', { count: items.length }) + many
					: '✉️ ' + _('Nouveau message de {sender}{account} : {subject}', { sender: sender(items[0]), account: many, subject: items[0].subject || _('(sans objet)') }))
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
			const n = new window.Notification(_('{count} nouveaux messages', { count: items.length }) + many, { body: items.slice(0, 3).map((m) => _('{sender} : {subject}', { sender: sender(m), subject: m.subject })).join('\n'), icon, tag: 'webmail-' + account.id })
			n.onclick = open(null)
			return
		}
		items.forEach((m) => {
			const n = new window.Notification(sender(m) + many, { body: m.subject || _('(sans objet)'), icon, tag: 'webmail-' + m.id })
			n.onclick = open(m.id)
		})
	}

	// ------------------------------------------------------------------
	// keyboard shortcuts (mail)
	// ------------------------------------------------------------------

	const SHORTCUTS = () => [
		[_('j  ou  ↓'), _('Message suivant')],
		[_('k  ou  ↑'), _('Message précédent')],
		[_('Entrée'), _('Ouvrir le message sélectionné')],
		['r', _('Répondre')],
		['a', _('Répondre à tous')],
		['f', _('Transférer')],
		['n', _('Nouveau message')],
		[_('Suppr'), _('Supprimer (Maj+Suppr : définitivement)')],
		['u', _('Marquer comme non lu')],
		['x', _('Sélectionner le message (Ctrl+clic, Maj+clic dans la liste)')],
		['s', _('Assurer un suivi / le retirer')],
		['/', _('Rechercher dans le dossier')],
		[_('Échap'), _('Revenir à la liste')],
		['?', _('Afficher cette aide')],
		[_('Ctrl+K'), _('Palette de commandes : aller à un dossier, créer, rechercher')],
	]

	function showShortcuts() {
		if (document.querySelector('.wm-overlay')) return
		const overlay = h('div', { class: 'wm-overlay' })
		const close = () => { overlay.remove(); document.removeEventListener('keydown', onKey, true) }
		const onKey = (e) => { if (e.key === 'Escape' || e.key === '?') { e.preventDefault(); e.stopPropagation(); close() } }
		document.addEventListener('keydown', onKey, true)
		overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) close() })
		overlay.appendChild(h('div', { class: 'wm-modal wm-shortcuts', role: 'dialog', 'aria-modal': 'true', 'aria-label': _('Raccourcis clavier') },
			h('div', { class: 'wm-modal-header' }, h('h2', {}, _('Raccourcis clavier du courrier')),
				h('button', { class: 'wm-modal-close', 'aria-label': _('Fermer'), onclick: close }, '✕')),
			h('table', {}, h('tbody', {}, SHORTCUTS().map(([k, label]) => h('tr', {}, h('td', {}, h('kbd', {}, k)), h('td', {}, label)))))))
		document.body.appendChild(overlay)
	}

	// ------------------------------------------------------------------
	// command palette (Ctrl+K / ⌘K): go anywhere, do anything, with a few letters
	// ------------------------------------------------------------------

	/** Lower case, without accents: « Éléments » matches « elem ». */
	const fold = (t) => String(t || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()

	/** What the palette offers now: modules, actions, the folders of the account, the other accounts. */
	function paletteCommands() {
		const mods = window.WebmailModules
		const go = (id) => () => { if (state.module !== id) selectModule(id) }
		// (the « new » button of a module: first in its pane)
		const create = (id) => () => {
			if (state.module !== id) selectModule(id)
			setTimeout(() => { const b = navEl.querySelector('.wm-compose-btn'); if (b) b.click() }, 0)
		}
		const out = [
			{ group: _('Aller à'), icon: '✉️', label: _('Courrier'), run: go('mail') },
			mods.calendar ? { group: _('Aller à'), icon: '📅', label: _('Agenda'), run: go('calendar') } : null,
			mods.contacts ? { group: _('Aller à'), icon: '👤', label: _('Contacts'), run: go('contacts') } : null,
			mods.tasks ? { group: _('Aller à'), icon: '✅', label: _('Tâches'), run: go('tasks') } : null,
			{ group: _('Créer'), icon: '✉️', label: _('Nouveau message'), keys: 'n', run: () => { if (state.module !== 'mail') selectModule('mail'); openCompose('new') } },
			mods.calendar ? { group: _('Créer'), icon: '📅', label: _('Nouvel événement'), run: create('calendar') } : null,
			mods.contacts ? { group: _('Créer'), icon: '👤', label: _('Nouveau contact'), run: create('contacts') } : null,
			mods.tasks ? { group: _('Créer'), icon: '✅', label: _('Nouvelle tâche'), run: create('tasks') } : null,
			{ group: _('Réglages'), icon: '⚙️', label: _('Comptes et signature'), run: () => openAccountSettings() },
			{ group: _('Réglages'), icon: '⌨️', label: _('Raccourcis clavier'), keys: '?', run: () => showShortcuts() },
		]
		state.folders.forEach((f) => out.push({ group: _('Dossiers'), icon: '📁', label: f.name, hint: f.unread ? String(f.unread) : '', run: () => { if (state.module !== 'mail') selectModule('mail'); selectFolder(f.id) } }))
		if (state.accounts.length > 1) {
			state.accounts.forEach((a) => { if (a.id !== state.accountId) out.push({ group: _('Comptes'), icon: '👥', label: a.email, run: () => selectAccount(a.id) }) })
		}
		return out.filter(Boolean)
	}

	/** The commands whose words all start a word of the label (or appear in it), the best first. */
	function paletteMatch(commands, query) {
		const words = fold(query).split(/\s+/).filter(Boolean)
		if (!words.length) return commands
		const scored = []
		commands.forEach((c, i) => {
			const label = fold(c.label)
			const starts = label.split(/[^a-z0-9]+/)
			if (!words.every((w) => label.includes(w))) return
			const score = (label.startsWith(words[0]) ? 0 : starts.some((x) => x.startsWith(words[0])) ? 1 : 2)
			scored.push([score, i, c])
		})
		return scored.sort((a, b) => a[0] - b[0] || a[1] - b[1]).map((x) => x[2])
	}

	function openPalette() {
		if (document.querySelector('.wm-overlay')) return
		const opener = document.activeElement
		const overlay = h('div', { class: 'wm-overlay wm-palette-overlay' })
		const input = h('input', {
			type: 'text',
			class: 'wm-palette-input',
			placeholder: _('Aller à un dossier, créer, rechercher…'),
			'aria-label': _('Commande'),
			role: 'combobox',
			'aria-expanded': 'true',
			'aria-controls': 'wm-palette-list',
			autocomplete: 'off',
			spellcheck: 'false',
		})
		const list = h('ul', { class: 'wm-palette-list', id: 'wm-palette-list', role: 'listbox' })
		const all = paletteCommands()
		let shown = []
		let active = 0
		const close = () => { overlay.remove(); document.removeEventListener('keydown', onKey, true); if (opener && opener.isConnected) opener.focus() }
		const run = (c) => { close(); if (c) c.run() }
		const paint = () => {
			const q = input.value.trim()
			const matched = paletteMatch(all, q)
			shown = matched
			// typed text: also « search for it in all the mail »
			if (q) shown = shown.concat([{ group: _('Rechercher'), icon: '🔍', label: _('Rechercher « {text} » dans tout le courrier', { text: q }), run: () => {
				if (state.module !== 'mail') selectModule('mail')
				state.filters.all = true
				state.query = q
				setView('list')
				loadMessages(true)
			} }])
			active = Math.min(active, Math.max(0, shown.length - 1))
			clear(list)
			let group = null
			shown.slice(0, 30).forEach((c, i) => {
				if (c.group !== group) {
					group = c.group
					list.appendChild(h('li', { class: 'wm-palette-group', role: 'presentation' }, group))
				}
				list.appendChild(h('li', {
					class: 'wm-palette-item' + (i === active ? ' active' : ''),
					role: 'option',
					id: 'wm-palette-' + i,
					'aria-selected': i === active ? 'true' : 'false',
					onmousedown: (e) => { e.preventDefault(); run(c) },
					onmousemove: () => { if (active !== i) { active = i; paint() } },
				}, h('span', { class: 'wm-palette-icon', 'aria-hidden': 'true' }, c.icon), h('span', { class: 'wm-palette-label' }, c.label),
				c.hint ? h('span', { class: 'wm-badge' }, c.hint) : null, c.keys ? h('kbd', {}, c.keys) : null))
			})
			if (!matched.length) list.prepend(h('li', { class: 'wm-palette-empty' }, _('Aucune commande')))
			input.setAttribute('aria-activedescendant', shown.length ? 'wm-palette-' + active : '')
			const el = list.querySelector('.wm-palette-item.active')
			if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' })
		}
		const onKey = (e) => {
			if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close() } else if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(active + 1, Math.min(shown.length, 30) - 1); paint() } else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, 0); paint() } else if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); run(shown[active]) }
		}
		document.addEventListener('keydown', onKey, true)
		input.addEventListener('input', () => { active = 0; paint() })
		overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) close() })
		overlay.appendChild(h('div', { class: 'wm-modal wm-palette', role: 'dialog', 'aria-modal': 'true', 'aria-label': _('Palette de commandes') }, input, list))
		document.body.appendChild(overlay)
		paint()
		input.focus()
	}

	/** Ctrl+K (⌘K on a Mac): the palette, from any module, even while typing. */
	function onPaletteKey(e) {
		if (!root || !(e.ctrlKey || e.metaKey) || e.altKey || e.shiftKey || (e.key !== 'k' && e.key !== 'K')) return
		e.preventDefault()
		if (document.querySelector('.wm-palette-overlay')) return
		openPalette()
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
			toast(_('Module indisponible'), true)
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
		navEl.appendChild(h('button', { class: 'wm-compose-btn primary', onclick: () => openCompose('new') }, '✉️  ' + _('Nouveau message')))
		const away = oofBanner()
		if (away) navEl.appendChild(away)
		const sel = accountSelector()
		if (sel) navEl.appendChild(sel)

		// the favorite folders first (those still there), one click away whatever the depth
		const favs = favorites().map((id) => state.folders.find((f) => f.id === id)).filter(Boolean)
		if (favs.length) {
			const favUl = h('ul', { class: 'wm-folders wm-favorites', 'aria-label': _('Favoris') })
			favs.forEach((f) => {
				const active = f.id === state.folderId || f.role === state.folderId
				const link = h('a', {
					href: '#',
					class: 'wm-folder' + (active ? ' active' : '') + (f.unread ? ' has-unread' : ''),
					onclick: (e) => { e.preventDefault(); selectFolder(f.id) },
					dataset: { folderId: f.id },
				},
				h('span', { class: 'wm-folder-icon', 'aria-hidden': 'true' }, '★'),
				h('span', { class: 'wm-folder-name' }, f.name),
				f.unread && f.role !== 'sentitems' && f.role !== 'deleteditems' ? h('span', { class: 'wm-badge' }, String(f.unread)) : null)
				folderDropTarget(link, f)
				favUl.appendChild(h('li', { class: 'wm-folder-row' }, link,
					h('button', {
						type: 'button',
						class: 'wm-folder-menu-btn wm-unfavorite',
						title: _('Retirer des favoris'),
						'aria-label': _('Retirer {name} des favoris', { name: f.name }),
						onclick: (e) => { e.stopPropagation(); setFavorite(f, false) },
					}, '✕')))
			})
			navEl.appendChild(h('div', { class: 'wm-nav-section' }, _('Favoris')))
			navEl.appendChild(favUl)
			navEl.appendChild(h('div', { class: 'wm-nav-section' }, _('Dossiers')))
		}

		const ul = h('ul', { class: 'wm-folders' })
		const byParent = {}
		state.folders.forEach((f) => { (byParent[f.parentId || ''] = byParent[f.parentId || ''] || []).push(f) })
		const top = (byParent[''] || []).slice().sort((a, b) => {
			const ra = a.role ? ROLE_ORDER.indexOf(a.role) : 99
			const rb = b.role ? ROLE_ORDER.indexOf(b.role) : 99
			return ra !== rb ? ra - rb : a.name.localeCompare(b.name, locale())
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
					title: _('Actions sur le dossier'),
					'aria-label': _('Actions sur le dossier {name}', { name: f.name }),
					'aria-haspopup': 'menu',
					onclick: (e) => { e.stopPropagation(); openFolderMenu(e.currentTarget, f) },
				}, '⋯')))
			const children = (byParent[f.id] || []).slice().sort((a, b) => a.name.localeCompare(b.name, locale()))
			children.forEach((c) => addFolder(c, depth + 1))
		}
		top.forEach((f) => addFolder(f, 0))
		navEl.appendChild(ul)
		navEl.appendChild(h('a', { href: '#', class: 'wm-new-folder', onclick: (e) => { e.preventDefault(); createFolder(null) } }, '＋ ' + _('Nouveau dossier')))
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
			title: mb.delegate ? _('Boîte dont vous êtes délégué (les dossiers qu\'on vous a partagés) : {email}', { email: mb.email }) : _('Boîte partagée {email}', { email: mb.email }),
			onclick: () => {
				mb.open = !mb.open
				try { localStorage.setItem('webmail.shared.' + mb.email, mb.open ? '1' : '0') } catch (e) { /* ignore */ }
				if (mb.open && !mb.folders) loadSharedFolders(mb)
				renderNav()
			},
		}, (mb.open ? '▾ ' : '▸ ') + (mb.delegate ? '👥 ' : '📮 ') + mb.name)
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
			;(byParent[f.id] || []).slice().sort((a, b) => a.name.localeCompare(b.name, locale())).forEach((c) => add(c, depth + 1))
		}
		;(byParent[''] || []).slice().sort((a, b) => {
			const ra = a.role ? ROLE_ORDER.indexOf(a.role) : 99
			const rb = b.role ? ROLE_ORDER.indexOf(b.role) : 99
			return ra !== rb ? ra - rb : a.name.localeCompare(b.name, locale())
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
		if (n >= 1024 ** 3) return _('{size} Go', { size: (n / 1024 ** 3).toLocaleString(locale(), { maximumFractionDigits: n >= 10 * 1024 ** 3 ? 0 : 1 }) })
		if (n >= 1024 ** 2) return _('{size} Mo', { size: Math.round(n / 1024 ** 2) })
		return _('{size} Ko', { size: Math.max(1, Math.round(n / 1024)) })
	}

	/** Space used by the mailbox, against its limit when Exchange gives it. */
	function usageGauge() {
		const u = state.usage
		if (!u || typeof u.used !== 'number') return null
		if (!u.limit) {
			return h('div', { class: 'wm-quota', title: _('Espace utilisé par la boîte aux lettres') }, h('span', { class: 'wm-muted' }, _('{size} utilisés', { size: formatBytes(u.used) })))
		}
		const pct = Math.min(100, Math.round((u.used / u.limit) * 100))
		const level = pct >= 90 ? 'full' : (pct >= 75 ? 'high' : 'ok')
		return h('div', { class: 'wm-quota wm-quota-' + level, title: _('Espace utilisé par la boîte aux lettres') },
			h('div', { class: 'wm-quota-bar', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(pct), 'aria-label': _('Espace utilisé') },
				h('div', { class: 'wm-quota-fill', style: 'width:' + pct + '%' })),
			h('span', { class: 'wm-quota-text' }, _('{used} sur {limit} ({percent} %)', { used: formatBytes(u.used), limit: formatBytes(u.limit), percent: pct })))
	}

	const warnedFull = new Set()
	function warnIfFull() {
		const u = state.usage
		if (!u || !u.limit || u.used / u.limit < 0.9 || warnedFull.has(state.accountId)) return
		warnedFull.add(state.accountId)
		toast(_('Votre boîte aux lettres est presque pleine ({percent} %) : videz les éléments supprimés ou archivez des messages, sinon l\'envoi sera bloqué.', { percent: Math.round((u.used / u.limit) * 100) }), true)
	}

	async function loadSnoozes() {
		const account = state.accountId
		try {
			const r = await api('/accounts/' + account + '/snoozes')
			if (account === state.accountId) state.snoozes = new Map(r.snoozes.map((x) => [x.id, x.until]))
		} catch (e) { /* the messages are shown without their time */ }
	}

	async function loadFolders() {
		loadSnoozes()
		try {
			const data = await api('/accounts/' + state.accountId + '/folders')
			state.folders = data.folders
			state.usage = data.usage || null
			folderCache.set(String(state.accountId), { folders: data.folders, usage: state.usage })
			persistTab()
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
		// folders seen before in this tab: shown at once (with the real inbox id,
		// so that its remembered list shows too), then refreshed
		const known = folderCache.get(String(id))
		if (known) {
			state.folders = known.folders
			state.usage = known.usage
			const inbox = known.folders.find((f) => f.role === 'inbox')
			if (inbox) state.folderId = inbox.id
		}
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

	const shortDate = (iso) => new Date(iso).toLocaleString(locale(), { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' })

	/** Reminder in the folder pane while automatic replies are on. */
	function oofBanner() {
		const o = oofState[state.accountId]
		if (!o || !o.active) return null
		const account = state.accounts.find((a) => a.id === state.accountId)
		return h('div', { class: 'wm-oof-banner', role: 'status' },
			h('div', {}, '🌴 ' + (o.state === 'Scheduled' && o.end ? _('Réponse automatique activée jusqu\'au {date}', { date: shortDate(o.end) }) : _('Réponse automatique activée'))),
			h('div', {},
				h('a', { href: '#', onclick: (e) => { e.preventDefault(); openOof(account) } }, _('Modifier')), ' · ',
				h('a', {
					href: '#',
					onclick: async (e) => {
						e.preventDefault()
						try {
							oofState[account.id] = await api('/accounts/' + account.id + '/oof', { method: 'POST', body: { state: 'Disabled', audience: o.audience, internal: o.internal, external: o.external } })
							toast(_('Réponse automatique désactivée'))
							renderNav()
						} catch (err) { toast(err.message, true) }
					},
				}, _('Désactiver'))))
	}

	/** Same settings as Outlook's "Automatic replies". */
	async function openOof(account) {
		const overlay = h('div', { class: 'wm-overlay' })
		const onKey = (e) => { if (e.key === 'Escape') close() }
		const close = () => { overlay.remove(); document.removeEventListener('keydown', onKey) }
		document.addEventListener('keydown', onKey)
		overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) close() })
		const body = h('div', { class: 'wm-event-form' }, h('div', { class: 'wm-center' }, h('span', { class: 'icon-loading' })))
		overlay.appendChild(h('div', { class: 'wm-modal', role: 'dialog', 'aria-modal': 'true', 'aria-label': _('Réponse automatique') },
			h('div', { class: 'wm-modal-header' }, h('h2', {}, _('Réponse automatique – {email}', { email: account.email })),
				h('button', { class: 'wm-modal-close', 'aria-label': _('Fermer'), onclick: close }, '✕')),
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
		const internal = h('textarea', { name: 'oofInternal', rows: '5', placeholder: _('Bonjour, je suis absent jusqu\'au … Pour toute urgence, contactez …') })
		internal.value = o.internal || ''
		const audience = h('select', { name: 'oofAudience' },
			[['None', _('Ne pas répondre aux personnes extérieures')], ['Known', _('Répondre seulement à mes contacts extérieurs')], ['All', _('Répondre à toutes les personnes extérieures')]]
				.map(([v, l]) => h('option', { value: v, selected: o.audience === v }, l)))
		const differs = h('input', { type: 'checkbox', name: 'oofDiffers', checked: !!o.external && o.external !== o.internal, onchange: sync })
		const external = h('textarea', { name: 'oofExternal', rows: '4', placeholder: _('Message pour les personnes extérieures') })
		external.value = o.external && o.external !== o.internal ? o.external : ''
		const period = h('div', { class: 'wm-oof-period' }, h('label', {}, _('Du '), start), h('label', {}, _(' au '), end))
		const externalBox = h('div', {}, h('label', { class: 'wm-radio' }, differs, ' ' + _('Message différent pour l\'extérieur')), external)
		const details = h('div', {}, h('label', { class: 'wm-field wm-field-top' }, h('span', {}, _('Message')), internal),
			h('label', { class: 'wm-field' }, h('span', {}, _('Extérieur')), audience), externalBox)
		function sync() {
			const st = overlay.querySelector('input[name=oofState]:checked').value
			period.hidden = st !== 'Scheduled'
			details.hidden = st === 'Disabled'
			externalBox.hidden = audience.value === 'None'
			external.hidden = !differs.checked
		}
		audience.addEventListener('change', sync)
		// the status of Nextcloud: « En vacances » while the automatic reply is active
		const ncStatus = h('input', { type: 'checkbox', name: 'oofNextcloudStatus', checked: o.nextcloudStatus !== false })
		const ncStatusRow = h('label', { class: 'wm-radio wm-oof-nc-status' }, ncStatus, ' ' + _('Afficher « En vacances » dans mon statut Nextcloud pendant l\'absence'))
		const err = h('div', { class: 'wm-form-error', role: 'alert' })
		const save = h('button', { type: 'submit', class: 'primary' }, _('Enregistrer'))
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
							nextcloudStatus: ncStatus.checked,
						},
					})
					close()
					const r = oofState[account.id]
					toast(r.state === 'Disabled' ? _('Réponse automatique désactivée')
						: r.state === 'Enabled' ? _('Réponse automatique activée')
							: _('Réponse automatique programmée du {start} au {end}', { start: shortDate(r.start), end: shortDate(r.end) }))
					if (account.id === state.accountId) renderNav()
				} catch (ex) {
					err.textContent = ex.message
					save.disabled = false
				}
			},
		},
		h('div', { class: 'wm-oof-states' },
			radio('Disabled', _('Ne pas envoyer de réponse automatique')),
			radio('Enabled', _('Envoyer une réponse automatique')),
			radio('Scheduled', _('Envoyer une réponse automatique pendant une période'))),
		period, details, ncStatusRow, err,
		h('div', { class: 'wm-compose-actions' }, save, h('button', { type: 'button', onclick: close }, _('Annuler')))))
		sync()
	}

	/**
	 * « Classer les pièces jointes dans Fichiers »: sender (address or @domain) → folder; the
	 * messages arriving from now on have their attachments put there by Nextcloud.
	 */
	async function openAutoFile(account, prefillFrom) {
		const overlay = h('div', { class: 'wm-overlay' })
		const close = () => { overlay.remove(); document.removeEventListener('keydown', onKey) }
		const onKey = (e) => { if (e.key === 'Escape') close() }
		document.addEventListener('keydown', onKey)
		overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) close() })
		const base = '/accounts/' + account.id + '/autofile'
		const list = h('ul', { class: 'wm-autofile-list' })
		const show = (rules) => {
			clear(list)
			if (!rules.length) list.appendChild(h('li', { class: 'wm-muted' }, _('Aucune règle : les pièces jointes restent dans les messages.')))
			rules.forEach((r) => list.appendChild(h('li', { dataset: { from: r.from } },
				h('span', {}, r.from), ' → ', h('strong', {}, r.folder), ' ',
				h('button', { type: 'button', class: 'wm-small', 'aria-label': _('Retirer la règle de {from}', { from: r.from }), onclick: async () => {
					try { show((await api(base + '/remove', { method: 'POST', body: { from: r.from } })).rules) } catch (e) { toast(e.message, true) }
				} }, '✕'))))
		}
		const from = h('input', { type: 'text', name: 'autofileFrom', placeholder: _('facturation@fournisseur.fr ou @fournisseur.fr'), value: prefillFrom || '' })
		const folder = h('input', { type: 'text', name: 'autofileFolder', placeholder: '/Documents/Factures' })
		const pick = h('button', { type: 'button', onclick: () => {
			if (!(OC.dialogs && OC.dialogs.filepicker)) return
			OC.dialogs.filepicker(_('Dossier de destination'), (dir) => { if (dir) folder.value = dir }, false, 'httpd/unix-directory', true, OC.dialogs.FILEPICKER_TYPE_CHOOSE)
		} }, '📁 ' + _('Choisir'))
		const form = h('form', {
			class: 'wm-event-form',
			onsubmit: async (e) => {
				e.preventDefault()
				try {
					show((await api(base, { method: 'POST', body: { from: from.value, folder: folder.value } })).rules)
					toast(_('Les pièces jointes de {from} iront dans {folder}', { from: from.value.trim().toLowerCase(), folder: folder.value.trim() }))
					from.value = ''
				} catch (ex) { toast(ex.message, true) }
			},
		},
		h('label', { class: 'wm-field' }, h('span', {}, _('Expéditeur')), from),
		h('label', { class: 'wm-field' }, h('span', {}, _('Dossier')), folder, pick),
		h('div', { class: 'wm-compose-actions' }, h('button', { type: 'submit', class: 'primary' }, _('Ajouter'))))
		overlay.appendChild(h('div', { class: 'wm-modal wm-autofile', role: 'dialog', 'aria-modal': 'true', 'aria-label': _('Pièces jointes → Fichiers') },
			h('div', { class: 'wm-modal-header' }, h('h2', {}, '📂 ' + _('Pièces jointes → Fichiers')), h('button', { class: 'wm-modal-close', 'aria-label': _('Fermer'), onclick: close }, '✕')),
			h('div', { class: 'wm-modal-body' },
				h('p', { class: 'wm-muted' }, _('Les pièces jointes des messages qui arrivent de ces expéditeurs sont rangées par Nextcloud dans le dossier choisi (toutes les 10 minutes, même webmail fermé). Les messages déjà reçus ne sont pas concernés.')),
				list, form)))
		document.body.appendChild(overlay)
		try { show((await api(base)).rules) } catch (e) { toast(e.message, true) }
		;(prefillFrom ? folder : from).focus()
	}

	function openRules(account, prefill) {
		if (!window.WebmailRules) { toast(_('Module indisponible'), true); return }
		window.WebmailRules.open(core, account, prefill)
	}

	/** A new rule for the messages of this sender, to be completed. */
	function ruleFromMessage(m) {
		const account = state.accounts.find((a) => a.id === state.accountId)
		const from = m.from && m.from.email && !m.from.email.startsWith('/') ? m.from.email : ''
		openRules(account, {
			name: _('Messages de {sender}', { sender: (m.from && (m.from.name || m.from.email)) || _('cet expéditeur') }),
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
	/**
	 * @param {boolean} reset
	 * @param {number} [count] rows to add (default: a page)
	 */
	async function loadMessages(reset, count) {
		if (state.module !== 'mail') return
		if (!reset && (state.loadingList || !state.hasMore)) return
		const seq = ++listSeq
		let limit = count || PAGE_SIZE
		let firstPage = false
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
				// a folder never seen: its first rows, then the rest of the page
				limit = FIRST_PAGE
				firstPage = true
			}
		}
		try {
			const sentAt = Date.now()
			const data = await api(listEndpoint(), {
				query: listQuery(reset ? 0 : state.messages.length, limit),
			})
			// a newer load (another folder, a search) replaced this one; the folder id
			// itself may change meanwhile ("inbox" becomes its real id)
			if (seq !== listSeq) return
			data.items = withLocalEdits(data.items, sentAt)
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
		if (seq !== listSeq || state.module !== 'mail') return
		if (firstPage && state.hasMore && state.messages.length < PAGE_SIZE) {
			await loadMessages(false, PAGE_SIZE - state.messages.length)
			return
		}
		if (reset) { prefetchUnreadWhenIdle(); prefetchModulesWhenIdle() }
	}

	/**
	 * The first unread messages of the inbox, loaded while the browser has
	 * nothing else to do (not marked as read): the first click opens at once.
	 * Not on a connection that asks to save data, nor in a hidden tab.
	 */
	function prefetchUnreadWhenIdle() {
		if (folderRole() !== 'inbox' || isSearching() || document.hidden) return
		if (navigator.connection && navigator.connection.saveData) return
		const seq = listSeq
		const accountId = state.accountId
		const ids = state.messages.filter((m) => !m.isRead && !msgCache.has(msgKey(m.id))).slice(0, IDLE_PREFETCH).map((m) => m.id)
		if (!ids.length) return
		const idle = window.requestIdleCallback || ((fn) => setTimeout(fn, 1500))
		// stop when the user moved to another folder or another account
		const current = () => seq === listSeq && accountId === state.accountId && state.module === 'mail'
		const next = (i) => {
			if (i >= ids.length || !current()) return
			idle(() => (current() ? prefetchMessage(ids[i]).catch(() => null).then(() => next(i + 1)) : null), { timeout: 4000 })
		}
		next(0)
	}

	/** account id -> when its calendar, contacts and tasks were last loaded ahead */
	const modulesPrefetched = new Map()
	const MODULES_PREFETCH_EVERY = 10 * 60 * 1000

	/**
	 * The calendar, the contacts and the tasks loaded while the browser has nothing else
	 * to do, one after the other, so that their tab shows at once (then refreshes).
	 * Once per account (again after 10 minutes); not on a connection that asks to save data,
	 * nor in a hidden tab; stops when the account changes.
	 */
	function prefetchModulesWhenIdle() {
		if (folderRole() !== 'inbox' || document.hidden || state.module !== 'mail') return
		if (navigator.connection && navigator.connection.saveData) return
		const accountId = state.accountId
		if (Date.now() - (modulesPrefetched.get(accountId) || 0) < MODULES_PREFETCH_EVERY) return
		modulesPrefetched.set(accountId, Date.now())
		const idle = window.requestIdleCallback || ((fn) => setTimeout(fn, 1500))
		const mods = ['calendar', 'tasks', 'contacts'].map((k) => window.WebmailModules[k]).filter((m) => m && m.prefetch)
		const next = (i) => {
			if (i >= mods.length || accountId !== state.accountId) return
			idle(() => {
				if (accountId !== state.accountId) return
				// (a failure is not shown: the tab loads again when opened)
				Promise.resolve().then(() => mods[i].prefetch(core)).catch(() => null).then(() => next(i + 1))
			}, { timeout: 5000 })
		}
		next(0)
	}

	async function refresh() {
		if (!state.accountId || document.hidden || state.module !== 'mail') return
		loadFolders()
		if (state.messages.length <= 100 && !state.loadingList) {
			const seq = listSeq
			try {
				const sentAt = Date.now()
				const data = await api(listEndpoint(), {
					query: listQuery(0, Math.max(PAGE_SIZE, state.messages.length)),
				})
				if (seq !== listSeq || state.module !== 'mail') return
				state.messages = withLocalEdits(data.items, sentAt)
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
		// the recent searches, offered by the browser under the field
		const recentList = h('datalist', { id: 'wm-recent-searches' }, recentSearches().map((q) => h('option', { value: q })))
		const searchInput = h('input', {
			type: 'search',
			class: 'wm-search',
			list: 'wm-recent-searches',
			placeholder: state.filters.all ? _('Rechercher dans tous les dossiers') : _('Rechercher dans {folder}', { folder: f ? f.name : _('le dossier') }),
			value: typing ? typing.value : state.query,
			'aria-label': _('Rechercher'),
		})
		// results while typing: after a short pause, from 2 characters (or the whole folder again when emptied)
		searchInput.addEventListener('input', (e) => {
			clearTimeout(searchTimer)
			if (e.isComposing) return
			searchTimer = setTimeout(() => {
				const v = searchInput.value.trim()
				if (v === state.query || (v.length > 0 && v.length < SEARCH_MIN)) return
				state.query = v
				loadMessages(true)
			}, SEARCH_DELAY)
		})
		const form = h('form', {
			class: 'wm-list-header',
			onsubmit: (e) => { e.preventDefault(); clearTimeout(searchTimer); state.query = searchInput.value.trim(); rememberSearch(state.query); loadMessages(true) },
		},
		h('button', { type: 'button', class: 'wm-back wm-mobile-only', 'aria-label': _('Dossiers'), onclick: () => setView('nav') }, '☰'),
		searchInput,
		recentList,
		h('button', {
			type: 'button',
			class: 'wm-icon-btn wm-filter-toggle' + (state.showFilters || filtersOn() ? ' active' : ''),
			title: _('Filtres'),
			'aria-label': _('Filtres'),
			'aria-expanded': state.showFilters ? 'true' : 'false',
			onclick: () => { state.showFilters = !state.showFilters; renderList(state.loadingList && !state.messages.length) },
		}, '⏷' + (filtersOn() ? ' ' + filtersOn() : '')),
		state.grouped ? null : h('select', {
			class: 'wm-sort' + (state.sort !== 'date-desc' ? ' active' : ''),
			title: _('Trier par'),
			'aria-label': _('Trier par'),
			onchange: (e) => {
				state.sort = e.target.value
				try { localStorage.setItem('webmail.sort', state.sort) } catch (ex) { /* not kept */ }
				state.checked.clear()
				loadMessages(true)
			},
		}, SORTS.map(([v, label]) => h('option', { value: v, selected: v === state.sort }, label()))),
		h('button', {
			type: 'button',
			class: 'wm-icon-btn wm-group-toggle' + (state.grouped ? ' active' : ''),
			title: state.grouped ? _('Afficher chaque message') : _('Regrouper par conversation'),
			'aria-label': _('Regrouper par conversation'),
			'aria-pressed': state.grouped ? 'true' : 'false',
			onclick: () => {
				state.grouped = !state.grouped
				try { localStorage.setItem('webmail.grouped', state.grouped ? '1' : '0') } catch (e) { /* ignore */ }
				state.checked.clear()
				loadMessages(true)
			},
		}, '🗨'),
		h('button', { type: 'button', class: 'wm-icon-btn', title: _('Actualiser'), 'aria-label': _('Actualiser'), onclick: () => { loadFolders(); loadMessages(true) } }, '⟳'),
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
			const folder = f ? f.name : _('le dossier')
			const all = state.filters.all
			const what = /^category:"[^"]*"$/.test(state.query)
				? (all ? _('Catégorie « {name} » dans tous les dossiers', { name: state.query.slice(10, -1) }) : _('Catégorie « {name} » dans {folder}', { name: state.query.slice(10, -1), folder }))
				: state.query
					? (all ? _('Résultats pour « {query} » dans tous les dossiers', { query: state.query }) : _('Résultats pour « {query} » dans {folder}', { query: state.query, folder }))
					: (all ? _('Messages filtrés dans tous les dossiers') : _('Messages filtrés dans {folder}', { folder }))
			listEl.appendChild(h('div', { class: 'wm-search-info' },
				what + (state.loadingList ? '' : ' (' + state.total + ') '),
				h('a', { href: '#', onclick: (e) => { e.preventDefault(); resetSearch(); loadMessages(true) } }, _('effacer'))))
		}

		if (loading) {
			listEl.appendChild(h('div', { class: 'wm-center' }, h('span', { class: 'icon-loading' })))
			return
		}
		if (state.messages.length === 0) {
			listEl.appendChild(h('div', { class: 'wm-empty' }, isSearching() ? _('Aucun résultat') : _('Aucun message')))
			return
		}

		const showRecipients = folderRole() === 'sentitems' || folderRole() === 'drafts'
		// conversations: one row each, opened on their newest message (no ticks nor drag and drop)
		const conv = grouped()
		const ul = h('ul', { class: 'wm-messages' + (conv ? ' wm-conversations' : ''), role: 'list' })
		// « Aujourd'hui », « Hier », « Cette semaine »…: a heading where the period changes
		// (only in date order; sorted otherwise, the size where the date usually is)
		const byDate = conv || sortField()[0] === 'date'
		const bySize = !conv && sortField()[0] === 'size'
		const period = dayPeriods()
		let lastPeriod = null
		state.messages.forEach((m) => {
			const p = byDate ? period(m.date) : null
			if (p && p !== lastPeriod) {
				lastPeriod = p
				ul.appendChild(h('li', { class: 'wm-list-period', role: 'presentation' }, p))
			}
			const who = showRecipients ? _('À : {names}', { names: m.displayTo || '' }) : (personShort(m.from) || _('(expéditeur inconnu)'))
			const li = h('li', {
				class: 'wm-message' + (m.isRead ? '' : ' unread') + (m.id === state.selectedId ? ' selected' : '') + (state.checked.has(m.id) ? ' checked' : ''),
				tabindex: '0',
				draggable: conv ? 'false' : 'true',
				dataset: { id: m.id },
				onclick: (e) => {
					// (the end of a swipe is not a click)
					if (e.currentTarget.dataset.swiped) return
					// Ctrl/Cmd+click: tick; Shift+click: tick a range; click: open
					if (!conv && (e.ctrlKey || e.metaKey)) toggleChecked(m.id)
					else if (!conv && e.shiftKey) checkRange(m.id)
					else openMessage(m.id)
				},
				ondragstart: (e) => {
					const ids = state.checked.has(m.id) ? [...state.checked] : [m.id]
					e.dataTransfer.setData('application/x-webmail-ids', JSON.stringify(ids))
					e.dataTransfer.effectAllowed = 'move'
					// the image shown under the pointer, kept until the drag ends
					dragGhost = h('div', { class: 'wm-drag-ghost' }, ids.length > 1 ? _('{count} messages', { count: ids.length }) : (m.subject || _('(sans objet)')))
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
			conv ? null : h('input', {
				type: 'checkbox',
				class: 'wm-check',
				checked: state.checked.has(m.id),
				'aria-label': _('Sélectionner « {subject} »', { subject: m.subject || _('(sans objet)') }),
				onclick: (e) => { e.stopPropagation(); if (e.shiftKey) { e.preventDefault(); checkRange(m.id) } else toggleChecked(m.id) },
			}),
			h('div', { class: 'wm-message-line1' },
				h('span', { class: 'wm-message-from' }, who),
				conv && m.count > 1 ? h('span', { class: 'wm-thread-count', title: !m.unreadCount ? _('{count} messages', { count: m.count }) : m.unreadCount > 1 ? _('{count} messages, dont {unread} non lus', { count: m.count, unread: m.unreadCount }) : _('{count} messages, dont {unread} non lu', { count: m.count, unread: m.unreadCount }) }, String(m.count)) : null,
				m.folderId && state.filters.all ? h('span', { class: 'wm-message-folder' }, folderName(m.folderId)) : null,
				h('span', { class: 'wm-message-date' }, bySize && m.size ? formatSize(m.size) : formatDate(m.date))),
			h('div', { class: 'wm-message-line2' },
				m.importance === 'High' ? h('span', { class: 'wm-important', title: _('Importance haute') }, '❗') : null,
				h('span', { class: 'wm-message-subject' }, m.subject || _('(sans objet)')),
				categoryPills(m.categories),
				m.hasAttachments ? h('span', { class: 'wm-attach-icon', title: _('Pièce jointe') }, '📎') : null,
				state.snoozes.has(m.id) ? h('span', { class: 'wm-snooze-due', title: _('Revient dans la boîte de réception') }, '⏰ ' + snoozeLabel(state.snoozes.get(m.id))) : null,
				flagDueBadge(m),
				h('button', {
					class: 'wm-flag' + (m.flagged ? ' on' : ''),
					title: m.flagged ? _('Retirer le suivi') : _('Assurer un suivi'),
					'aria-label': _('Suivi'),
					onclick: (e) => { e.stopPropagation(); toggleFlag(m) },
				}, '⚑')),
			m.preview ? h('div', { class: 'wm-message-preview' }, m.preview) : null,
			)
			if (!conv) swipeable(li, m)
			ul.appendChild(li)
		})
		listEl.appendChild(ul)

		if (state.hasMore) {
			// the next page loads by itself when the end of the list comes into view
			const more = h('div', { class: 'wm-center wm-list-more' },
				h('button', { onclick: () => loadMessages(false) }, _('Charger plus ({loaded} / {total})', { loaded: state.messages.length, total: state.total })))
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
			'aria-label': _('Déplacer les messages sélectionnés vers'),
			onchange: (e) => { if (e.target.value) moveMessages(ids(), e.target.value) },
		}, h('option', { value: '' }, _('Déplacer vers…')),
		moveTargets().map((f) => h('option', { value: f.id }, f.name)))
		const allTicked = state.checked.size === state.messages.length
		bulkBarEl.appendChild(h('div', { class: 'wm-bulk', role: 'toolbar', 'aria-label': _('Messages sélectionnés') },
			h('strong', {}, state.checked.size > 1 ? _('{count} sélectionnés', { count: state.checked.size }) : _('{count} sélectionné', { count: state.checked.size })),
			h('button', {
				type: 'button',
				class: 'wm-small',
				onclick: () => {
					if (allTicked) state.checked.clear(); else state.messages.forEach((m) => state.checked.add(m.id))
					refreshChecks()
				},
			}, allTicked ? _('Tout désélectionner') : _('Tout sélectionner')),
			h('button', { type: 'button', class: 'wm-small', onclick: () => setReadMany(ids(), true) }, _('Lu')),
			h('button', { type: 'button', class: 'wm-small', onclick: () => setReadMany(ids(), false) }, _('Non lu')),
			h('button', { type: 'button', class: 'wm-small', onclick: () => setFlagMany(ids()) }, '⚑ ' + _('Suivi')),
			move,
			h('button', {
				type: 'button',
				class: 'wm-small wm-forward-attach',
				title: _('Transférer les messages sélectionnés en pièces jointes (.eml)'),
				onclick: () => forwardAsAttachment(state.messages.filter((x) => state.checked.has(x.id))),
			}, '↪ ' + _('Transférer')),
			h('button', { type: 'button', class: 'wm-small wm-danger', onclick: () => deleteMessages(ids(), role === 'deleteditems') },
				role === 'deleteditems' ? _('Supprimer définitivement') : '🗑 ' + _('Supprimer')),
			junkButton(null, role, ids),
			h('button', {
				type: 'button',
				class: 'wm-small wm-cat-btn',
				onclick: (e) => categoryMenu(e.currentTarget, () => state.messages.filter((x) => state.checked.has(x.id))),
			}, '🏷 ' + _('Catégoriser')),
			h('button', { type: 'button', class: 'wm-small wm-icon-btn', 'aria-label': _('Annuler la sélection'), title: _('Annuler la sélection (Échap)'), onclick: clearChecked }, '✕')))
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
				title: clickable ? _('Tous les messages de la catégorie « {name} »', { name: n }) : _('Catégorie {name}', { name: n }),
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
		const menu = h('div', { class: 'wm-cat-menu', role: 'dialog', 'aria-label': _('Catégories') })
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
			if (!known.length) list.appendChild(h('p', { class: 'wm-muted' }, _('Aucune catégorie pour l\'instant')))
			const name = h('input', { type: 'text', name: 'newCategory', placeholder: _('Nouvelle catégorie'), maxlength: '255' })
			const color = h('select', { name: 'newCategoryColor', 'aria-label': _('Couleur') },
				categories.colorNames.map((l, i) => h('option', { value: String(i), selected: i === 7 }, l)))
			const err = h('div', { class: 'wm-form-error' })
			menu.append(h('div', { class: 'wm-cat-title' }, _('Catégories')), list,
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
				}, name, color, h('button', { type: 'submit', class: 'primary' }, _('Créer')), err))
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
	/**
	 * On a touch screen, a message swiped in the list: to the left, to « Éléments supprimés »
	 * (with « Annuler »); to the right, read / unread. Past a third of the width, the action is
	 * armed (stronger colour); let go before, nothing happens. A vertical move scrolls as usual.
	 */
	function swipeable(li, m) {
		let x0 = null
		let y0 = 0
		let dx = 0
		let decided = false
		let swiping = false
		const armed = () => Math.abs(dx) > li.offsetWidth * 0.35
		// the message as it is now (a refresh of the list may have replaced the one this row was drawn from)
		const current = () => state.messages.find((x) => x.id === m.id) || m
		const reset = () => {
			li.style.transform = ''
			li.classList.remove('wm-swiping', 'wm-swipe-left', 'wm-swipe-right', 'wm-swipe-armed')
			delete li.dataset.swipe
		}
		li.addEventListener('touchstart', (e) => {
			if (e.touches.length !== 1) { x0 = null; return }
			x0 = e.touches[0].clientX
			y0 = e.touches[0].clientY
			dx = 0
			decided = false
			swiping = false
		}, { passive: true })
		li.addEventListener('touchmove', (e) => {
			if (x0 === null) return
			const mx = e.touches[0].clientX - x0
			const my = e.touches[0].clientY - y0
			if (!decided) {
				if (Math.abs(mx) < 10 && Math.abs(my) < 10) return
				decided = true
				swiping = Math.abs(mx) > Math.abs(my) * 1.5
			}
			if (!swiping) return
			e.preventDefault()
			dx = mx
			li.classList.add('wm-swiping')
			li.style.transform = 'translateX(' + dx + 'px)'
			li.classList.toggle('wm-swipe-left', dx < 0)
			li.classList.toggle('wm-swipe-right', dx > 0)
			li.classList.toggle('wm-swipe-armed', armed())
			li.dataset.swipe = dx < 0 ? '🗑 ' + _('Supprimer') : (current().isRead ? '● ' + _('Non lu') : '✓ ' + _('Lu'))
		}, { passive: false })
		const end = () => {
			if (x0 === null) return
			x0 = null
			if (!swiping) return
			const go = armed()
			reset()
			li.dataset.swiped = '1'
			setTimeout(() => { delete li.dataset.swiped }, 400)
			if (!go) return
			if (dx < 0) deleteMessages([m.id], folderRole() === 'deleteditems')
			else setReadMany([m.id], !current().isRead)
		}
		li.addEventListener('touchend', end)
		li.addEventListener('touchcancel', () => { x0 = null; reset() })
	}

	async function setReadMany(ids, value) {
		const changed = state.messages.filter((m) => ids.includes(m.id) && m.isRead !== value)
		changed.forEach((m) => updateListItem(m.id, { isRead: value }))
		const f = currentFolder()
		if (f) { f.unread = Math.max(0, f.unread + (value ? -changed.length : changed.length)); renderNav() }
		clearChecked()
		try {
			await api('/accounts/' + state.accountId + '/flags', { method: 'POST', body: { itemIds: ids, flag: 'read', value } })
			toast(ids.length > 1
				? (value ? _('{count} messages marqués comme lus', { count: ids.length }) : _('{count} messages marqués comme non lus', { count: ids.length }))
				: (value ? _('{count} message marqué comme lu', { count: ids.length }) : _('{count} message marqué comme non lu', { count: ids.length })))
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
		const before = items.map((m) => [m.id, { flagged: !!m.flagged, flagDue: m.flagDue || null }])
		items.forEach((m) => updateListItem(m.id, value ? { flagged: true } : { flagged: false, flagDue: null }))
		clearChecked()
		try {
			await api('/accounts/' + state.accountId + '/flags', { method: 'POST', body: { itemIds: ids, flag: 'flagged', value } })
		} catch (e) {
			before.forEach(([id, v]) => updateListItem(id, v))
			toast(e.message, true)
		}
	}

	/**
	 * .eml files (Outlook, Thunderbird, another mailbox…) put into a folder.
	 * @param {Array<File>} files from the computer
	 */
	async function importEml(folder, files) {
		const emls = Array.from(files || []).filter((f) => /\.eml$/i.test(f.name) || f.type === 'message/rfc822')
		if (!emls.length) { toast(_('Choisissez des messages enregistrés en .eml'), true); return }
		const fd = new FormData()
		fd.append('folderId', folder.id)
		emls.forEach((f) => fd.append('files[]', f, f.name))
		toast(_('Import dans « {folder} »…', { folder: folder.name }))
		try {
			const r = await api('/accounts/' + state.accountId + '/import', { method: 'POST', body: fd })
			const parts = []
			if (r.imported) parts.push(r.imported > 1 ? _('{count} messages importés dans « {folder} »', { count: r.imported, folder: folder.name }) : _('{count} message importé dans « {folder} »', { count: r.imported, folder: folder.name }))
			r.failed.forEach((f) => parts.push(_('{name} : {error}', { name: f.name, error: f.error })))
			toast(parts.join(_(' ; ')), r.failed.length > 0 && !r.imported)
			listCache.delete(state.accountId + '|' + folder.id)
			if (folder.id === state.folderId) loadMessages(true)
			loadFolders()
		} catch (e) { toast(e.message, true) }
	}

	/** Every message of a folder read, at once on screen, in one request to Exchange. */
	async function markFolderRead(folder) {
		const before = folder.unread
		folder.unread = 0
		if (folder.id === state.folderId) state.messages.forEach((m) => { if (!m.isRead) updateListItem(m.id, { isRead: true }) })
		listCache.delete(state.accountId + '|' + folder.id)
		renderNav()
		try {
			await api('/accounts/' + state.accountId + '/folders/read', { method: 'POST', body: { folderId: folder.id } })
			toast(_('Tous les messages de « {folder} » sont lus', { folder: folder.name }))
			saveListCache()
		} catch (e) {
			folder.unread = before
			toast(e.message, true)
			if (folder.id === state.folderId) loadMessages(true)
			renderNav()
		}
	}

	function pickEml(folder) {
		const input = h('input', { type: 'file', multiple: true, accept: '.eml,message/rfc822', hidden: true, class: 'wm-eml-input' })
		input.addEventListener('change', () => { const list = Array.from(input.files); input.remove(); importEml(folder, list) })
		document.body.appendChild(input)
		input.click()
	}

	/** Folders accept messages dropped on them, and .eml files from the computer. */
	function folderDropTarget(el, folder) {
		const hasFiles = (e) => e.dataTransfer.types.includes('Files')
		el.addEventListener('dragover', (e) => {
			if (hasFiles(e)) { e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; el.classList.add('wm-drop-target'); return }
			if (folder.id === state.folderId || !e.dataTransfer.types.includes('application/x-webmail-ids')) return
			e.preventDefault()
			e.dataTransfer.dropEffect = 'move'
			el.classList.add('wm-drop-target')
		})
		el.addEventListener('dragleave', () => el.classList.remove('wm-drop-target'))
		el.addEventListener('drop', (e) => {
			el.classList.remove('wm-drop-target')
			root.classList.remove('wm-dragging')
			if (e.dataTransfer.files && e.dataTransfer.files.length) { e.preventDefault(); importEml(folder, e.dataTransfer.files); return }
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

	/** « Favoris »: folders pinned at the top of the pane, in the order chosen (kept per account in this browser). */
	const favKey = () => 'webmail.favorites.' + state.accountId
	function favorites() {
		try {
			const v = JSON.parse(localStorage.getItem(favKey()) || '[]')
			return Array.isArray(v) ? v.filter((x) => typeof x === 'string') : []
		} catch (e) { return [] }
	}
	function setFavorite(f, on) {
		const list = favorites().filter((id) => id !== f.id)
		if (on) list.push(f.id)
		try { localStorage.setItem(favKey(), JSON.stringify(list)) } catch (e) { /* not kept */ }
		toast(on ? _('« {name} » ajouté aux favoris', { name: f.name }) : _('« {name} » retiré des favoris', { name: f.name }))
		renderNav()
	}

	function openFolderMenu(anchor, f) {
		document.querySelectorAll('.wm-folder-menu').forEach((m) => m.remove())
		const fixed = FIXED_ROLES.includes(f.role)
		const item = (label, action, danger) => h('button', {
			type: 'button',
			role: 'menuitem',
			class: 'wm-folder-menu-item' + (danger ? ' wm-danger' : ''),
			onclick: () => { close(); action() },
		}, label)
		const menu = h('div', { class: 'wm-folder-menu', role: 'menu', 'aria-label': _('Dossier {name}', { name: f.name }) },
			favorites().includes(f.id)
				? item('☆ ' + _('Retirer des favoris'), () => setFavorite(f, false))
				: item('★ ' + _('Ajouter aux favoris'), () => setFavorite(f, true)),
			item('＋ ' + _('Nouveau sous-dossier'), () => createFolder(f)),
			f.unread ? item('✓ ' + _('Tout marquer comme lu'), () => markFolderRead(f)) : null,
			item('📥 ' + _('Importer des messages (.eml)…'), () => pickEml(f)),
			fixed ? null : item('✏️ ' + _('Renommer'), () => renameFolder(f)),
			fixed ? null : item('↪ ' + _('Déplacer…'), () => moveFolder(f)),
			fixed ? null : item('🗑 ' + _('Supprimer'), () => deleteFolder(f), true))
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
				h('div', { class: 'wm-modal-header' }, h('h2', {}, title), h('button', { class: 'wm-modal-close', 'aria-label': _('Fermer'), onclick: () => done(null) }, '✕')),
				h('form', {
					class: 'wm-event-form',
					onsubmit: (e) => { e.preventDefault(); const v = field.value.trim ? field.value.trim() : field.value; if (choices || v) done(v) },
				},
				h('label', { class: 'wm-field' }, h('span', {}, label), field),
				h('div', { class: 'wm-compose-actions' }, h('button', { type: 'submit', class: 'primary' }, confirmLabel), h('button', { type: 'button', onclick: () => done(null) }, _('Annuler'))))))
			document.body.appendChild(overlay)
			field.focus()
			if (field.select) field.select()
		})
	}

	/**
	 * « Tous ses messages »: the messages of one sender in the folder shown,
	 * to see them, or to mark as read, move or delete them all at once.
	 */
	async function senderMessages(m) {
		const email = m.from && m.from.email
		if (!email) return
		const folderId = state.folderId
		const base = '/accounts/' + state.accountId + '/sender'
		const who = m.from.name && m.from.name !== email ? m.from.name + ' <' + email + '>' : email
		const title = _('Messages de {who}', { who })
		const body = h('div', { class: 'wm-sender-body' }, h('span', { class: 'icon-loading' }))
		const overlay = h('div', { class: 'wm-overlay' })
		const close = () => { overlay.remove(); document.removeEventListener('keydown', onKey) }
		const onKey = (e) => { if (e.key === 'Escape') close() }
		document.addEventListener('keydown', onKey)
		overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) close() })
		overlay.appendChild(h('div', { class: 'wm-modal wm-modal-small wm-sender-dialog', role: 'dialog', 'aria-modal': 'true', 'aria-label': title },
			h('div', { class: 'wm-modal-header' }, h('h2', {}, title), h('button', { class: 'wm-modal-close', 'aria-label': _('Fermer'), onclick: close }, '✕')),
			body))
		document.body.appendChild(overlay)
		let info
		try {
			info = await api(base, { query: { folderId, email } })
		} catch (e) { close(); toast(e.message, true); return }
		const folder = folderName(folderId)
		const apply = async (action, target, done) => {
			try {
				const r = await api(base, { method: 'POST', body: { folderId, email, action, target: target || '' } })
				close()
				if (action !== 'read') removeFromList(r.itemIds)
				else r.itemIds.forEach((id) => updateListItem(id, { isRead: true }))
				toast(done(r.count))
				listCache.delete(state.accountId + '|' + folderId)
				if (target) listCache.delete(state.accountId + '|' + target)
				loadFolders()
			} catch (e) { toast(e.message, true) }
		}
		clear(body)
		const n = info.count
		body.appendChild(h('p', {}, n > 1 ? _('{count} messages de cet expéditeur dans « {folder} ».', { count: n, folder }) : _('{count} message de cet expéditeur dans « {folder} ».', { count: n, folder })))
		if (n >= info.max) body.appendChild(h('p', { class: 'wm-muted' }, _('Les {max} plus récents seulement : recommencez pour les suivants.', { max: info.max })))
		const targets = state.folders.filter((f) => f.id !== folderId && f.role !== 'drafts' && f.role !== 'outbox')
		const target = h('select', { name: 'senderTarget', 'aria-label': _('Dossier de destination') }, targets.map((f) => h('option', { value: f.id, selected: f.role === 'archive' }, folderName(f.id))))
		body.appendChild(h('div', { class: 'wm-sender-actions' },
			h('button', { type: 'button', class: 'wm-sender-show', onclick: () => { close(); resetSearch(); state.query = info.query; loadMessages(true) } }, '🔍 ' + _('Les afficher')),
			h('button', { type: 'button', class: 'wm-sender-read', onclick: () => apply('read', '', (c) => c > 1 ? _('{count} messages marqués comme lus', { count: c }) : _('{count} message marqué comme lu', { count: c })) }, '✓ ' + _('Tout marquer comme lu')),
			h('div', { class: 'wm-sender-move' }, target,
				h('button', { type: 'button', class: 'wm-sender-move-btn', onclick: () => apply('move', target.value, (c) => c > 1 ? _('{count} messages déplacés dans « {folder} »', { count: c, folder: folderName(target.value) }) : _('{count} message déplacé dans « {folder} »', { count: c, folder: folderName(target.value) })) }, _('Tout déplacer'))),
			h('button', {
				type: 'button',
				class: 'wm-danger wm-sender-delete',
				onclick: () => {
					if (!window.confirm(n > 1 ? _('Mettre les {count} messages de {who} à la corbeille ?', { count: n, who }) : _('Mettre le message de {who} à la corbeille ?', { who }))) return
					apply('delete', '', (c) => c > 1 ? _('{count} messages mis à la corbeille', { count: c }) : _('{count} message mis à la corbeille', { count: c }))
				},
			}, '🗑 ' + _('Tout supprimer'))))
		const first = body.querySelector('button')
		if (first) first.focus()
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
		const name = await askFolder({ title: parent ? _('Nouveau dossier dans « {name} »', { name: parent.name }) : _('Nouveau dossier'), label: _('Nom'), confirmLabel: _('Créer') })
		if (name) await folderAction('', { name, parentId: parent ? parent.id : '' }, _('Dossier « {name} » créé', { name }))
	}

	async function renameFolder(f) {
		const name = await askFolder({ title: _('Renommer « {name} »', { name: f.name }), label: _('Nouveau nom'), value: f.name, confirmLabel: _('Renommer') })
		if (name && name !== f.name) await folderAction('/rename', { folderId: f.id, name }, _('Dossier renommé en « {name} »', { name }))
	}

	async function moveFolder(f) {
		// not into itself nor into one of its own sub-folders
		const inside = new Set([f.id])
		let grew = true
		while (grew) {
			grew = false
			state.folders.forEach((x) => { if (x.parentId && inside.has(x.parentId) && !inside.has(x.id)) { inside.add(x.id); grew = true } })
		}
		const choices = [['', _('(en haut de la boîte aux lettres)')]].concat(state.folders.filter((x) => !inside.has(x.id) && x.id !== f.parentId).map((x) => [x.id, x.name]))
		const target = await askFolder({ title: _('Déplacer « {name} »', { name: f.name }), label: _('Dans'), choices, confirmLabel: _('Déplacer') })
		if (target === null) return
		await folderAction('/move', { folderId: f.id, parentId: target }, target
			? _('Dossier « {name} » déplacé dans « {target} »', { name: f.name, target: folderName(target) })
			: _('Dossier « {name} » déplacé en haut de la boîte aux lettres', { name: f.name }))
	}

	async function deleteFolder(f) {
		if (!window.confirm(_('Supprimer le dossier « {name} » et tout son contenu ? Il sera placé dans « Éléments supprimés ».', { name: f.name }))) return
		const wasOpen = state.folderId === f.id
		if (await folderAction('/delete', { folderId: f.id }, _('Dossier « {name} » placé dans les éléments supprimés', { name: f.name })) && wasOpen) {
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
		const period = h('select', { class: 'wm-chip-select', name: 'since', 'aria-label': _('Période'), onchange: (e) => { f.since = e.target.value; apply() } },
			[['', _('Toute période')], ['7', _('7 derniers jours')], ['30', _('30 derniers jours')], ['365', _('12 derniers mois')]]
				.map(([v, l]) => h('option', { value: v, selected: f.since === v }, l)))
		const all = h('label', { class: 'wm-chip-check' },
			h('input', { type: 'checkbox', name: 'allFolders', checked: f.all, onchange: (e) => { f.all = e.target.checked; if (isSearching() || searchInput.value.trim()) apply() } }),
			' ' + _('Tous les dossiers'))
		return h('div', { class: 'wm-filters', role: 'group', 'aria-label': _('Filtres') },
			chip('unread', _('Non lus')), chip('attachments', '📎 ' + _('Pièces jointes')), chip('flagged', '⚑ ' + _('Suivis')), period, all)
	}

	function listItemEl(id) {
		return [...listEl.querySelectorAll('.wm-message')].find((li) => li.dataset.id === id) || null
	}

	/** Update one message of the list in place (no full redraw: no flicker, scroll kept). */
	/** « Demain », « En retard »… next to the flag of a message in the list. */
	function flagDueBadge(m) {
		if (!m.flagged || !m.flagDue) return null
		return h('span', { class: 'wm-flag-due' + (m.flagDue < ymdLocal(new Date()) ? ' late' : ''), title: _('Échéance du suivi') }, flagDueLabel(m.flagDue))
	}

	function updateListItem(id, changes) {
		const m = state.messages.find((x) => x.id === id)
		if (!m) return
		Object.assign(m, changes)
		noteEdit(id, changes)
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
			flag.title = m.flagged ? _('Retirer le suivi') : _('Assurer un suivi')
			const old = li.querySelector('.wm-flag-due')
			if (old) old.remove()
			const badge = flagDueBadge(m)
			if (badge) flag.before(badge)
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
	/** Y-m-d of a local date. */
	function ymdLocal(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0') }

	/** « En retard », « Aujourd'hui », « Demain », « ven. 20 nov. »: when a flagged message is due. */
	function flagDueLabel(due) {
		if (!due) return ''
		const now = new Date()
		const today = ymdLocal(now)
		if (due < today) return _('En retard')
		if (due === today) return _('Aujourd\'hui')
		if (due === ymdLocal(new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1))) return _('Demain')
		return new Date(due + 'T12:00').toLocaleDateString(locale(), { weekday: 'short', day: 'numeric', month: 'short' })
	}

	function flagButtonText(m) {
		if (!m.flagged) return '⚑ ' + _('Suivi')
		return '⚑ ' + _('Suivi') + (m.flagDue ? ' : ' + flagDueLabel(m.flagDue) : ' ✓')
	}

	/** The choices of Outlook's flag menu: today, tomorrow, this week (Friday), next week (its Friday). */
	function flagChoices() {
		const now = new Date()
		const day = (n) => ymdLocal(new Date(now.getFullYear(), now.getMonth(), now.getDate() + n))
		const wd = now.getDay() // 0 = Sunday
		const toNextMonday = ((8 - wd) % 7) || 7
		return [
			['today', _('Aujourd\'hui'), day(0)],
			['tomorrow', _('Demain'), day(1)],
			// « this week » only while there is some of it left before Friday
			wd >= 1 && wd <= 3 ? ['week', _('Cette semaine'), day(5 - wd)] : null,
			['nextweek', _('La semaine prochaine'), day(toNextMonday + 4)],
			['nodate', _('Sans date'), ''],
		].filter(Boolean)
	}

	async function toggleFlag(m) {
		return setFlag(m, !m.flagged, '')
	}

	/** Flag (with a due date, or '' for none) or unflag a message, at once on screen. */
	async function setFlag(m, value, due) {
		const before = { flagged: !!m.flagged, flagDue: m.flagDue || null }
		const after = { flagged: value, flagDue: value && due ? due : null }
		const apply = (v) => {
			updateListItem(m.id, v)
			const cached = msgCache.get(msgKey(m.id))
			if (cached) Object.assign(cached, v)
			if (state.current && state.current.id === m.id) {
				Object.assign(state.current, v)
				const btn = detailEl.querySelector('.wm-flag-btn')
				if (btn) { btn.classList.toggle('on', !!v.flagged); btn.textContent = flagButtonText(state.current) }
			}
		}
		apply(after)
		try {
			await api('/accounts/' + state.accountId + '/flags', { method: 'POST', body: { itemIds: [m.id], flag: 'flagged', value, due: after.flagDue || '' } })
		} catch (e) {
			apply(before)
			toast(e.message, true)
		}
	}

	/** Outlook's flag menu: a due date for the follow-up, or none, or no more follow-up. */
	function flagMenu(anchor, m) {
		document.querySelectorAll('.wm-cat-menu').forEach((x) => x.remove())
		const menu = h('div', { class: 'wm-cat-menu wm-flag-menu', role: 'menu', 'aria-label': _('Échéance du suivi') })
		const close = () => { menu.remove(); document.removeEventListener('mousedown', outside, true); document.removeEventListener('keydown', esc, true); if (anchor.isConnected) anchor.focus() }
		const outside = (e) => { if (!menu.contains(e.target) && e.target !== anchor) close() }
		const esc = (e) => { if (e.key === 'Escape') { e.stopPropagation(); close() } }
		document.addEventListener('mousedown', outside, true)
		document.addEventListener('keydown', esc, true)
		const pick = (due) => { close(); setFlag(m, true, due) }
		menu.appendChild(h('div', { class: 'wm-cat-title' }, _('Assurer un suivi')))
		flagChoices().forEach(([key, label, due]) => menu.appendChild(h('button', {
			type: 'button',
			role: 'menuitem',
			class: 'wm-flag-choice' + (m.flagged && (m.flagDue || '') === due ? ' current' : ''),
			dataset: { choice: key },
			onclick: () => pick(due),
		}, h('span', {}, label), due ? h('span', { class: 'wm-muted' }, new Date(due + 'T12:00').toLocaleDateString(locale(), { weekday: 'short', day: 'numeric', month: 'short' })) : null)))
		const date = h('input', { type: 'date', name: 'flagDue', 'aria-label': _('Date personnalisée'), min: ymdLocal(new Date()) })
		if (m.flagDue) date.value = m.flagDue
		menu.appendChild(h('form', {
			class: 'wm-flag-custom',
			onsubmit: (e) => { e.preventDefault(); if (date.value) pick(date.value) },
		}, date, h('button', { type: 'submit', class: 'primary' }, _('OK'))))
		if (m.flagged) {
			menu.appendChild(h('button', { type: 'button', role: 'menuitem', class: 'wm-flag-choice', dataset: { choice: 'clear' }, onclick: () => { close(); setFlag(m, false, '') } }, '✕ ' + _('Retirer le suivi')))
		}
		document.body.appendChild(menu)
		const r = anchor.getBoundingClientRect()
		menu.style.top = Math.min(r.bottom + 4, window.innerHeight - menu.offsetHeight - 8) + 'px'
		menu.style.left = Math.max(8, Math.min(r.left, window.innerWidth - menu.offsetWidth - 8)) + 'px'
		const first = menu.querySelector('button')
		if (first) first.focus()
	}

	// ------------------------------------------------------------------
	// message detail
	// ------------------------------------------------------------------

	function renderEmptyDetail() {
		state.current = null
		clear(detailEl)
		detailEl.appendChild(h('div', { class: 'wm-empty wm-empty-detail' }, h('div', { class: 'wm-empty-icon' }, '✉️'), _('Sélectionnez un message')))
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
		// the next two (reading down the list), then the previous one
		const ids = [state.messages[i + 1], state.messages[i + 2], state.messages[i - 1]].filter(Boolean).map((m) => m.id)
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
		// a result opened: the search was useful, kept among the recent ones
		if (state.query) rememberSearch(state.query)
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

	/** Lists left during this session (sender addresses): their other messages say so. */
	const unsubscribed = new Set()

	/**
	 * « Se désabonner »: a mailing list's message (List-Unsubscribe) can be left in one click,
	 * the way the list asks (done by the server), or on its page.
	 */
	function unsubscribeBanner(m, senderEmail) {
		const how = m.unsubscribe
		const banner = h('div', { class: 'wm-info wm-unsub-banner' })
		const done = () => {
			clear(banner)
			banner.appendChild(document.createTextNode('✓ ' + _('Désabonnement demandé. Il peut falloir quelques jours pour ne plus rien recevoir.')))
		}
		if (senderEmail && unsubscribed.has(senderEmail)) {
			done()
			return banner
		}
		const go = async (e) => {
			if (how.method === 'link') {
				// the list's own page: it asks for a confirmation there
				window.open(how.url, '_blank', 'noopener,noreferrer')
				return
			}
			const question = how.method === 'mailto'
				? _('Se désabonner de cette liste de diffusion ? Un message de désinscription sera envoyé de votre part à {to}.', { to: how.to })
				: _('Se désabonner de cette liste de diffusion ? La demande sera envoyée directement à son site.')
			if (!window.confirm(question)) return
			const btn = e.currentTarget
			btn.disabled = true
			try {
				await api('/accounts/' + state.accountId + '/message/unsubscribe', { method: 'POST', body: { itemId: m.id } })
				if (senderEmail) unsubscribed.add(senderEmail)
				toast(_('Désabonnement demandé'))
				done()
			} catch (ex) {
				btn.disabled = false
				toast(ex.message, true)
			}
		}
		banner.append(
			_('Ce message vient d\'une liste de diffusion.') + ' ',
			how.method === 'link'
				? h('button', { class: 'wm-unsub', title: _('Ouvre la page de désinscription de la liste'), onclick: go }, _('Se désabonner') + ' ↗')
				: h('button', { class: 'wm-unsub', onclick: go }, _('Se désabonner')))
		return banner
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
			'aria-label': _('Déplacer vers'),
			onchange: (e) => { if (e.target.value) moveMessages([m.id], e.target.value) },
		},
		h('option', { value: '' }, _('Déplacer vers…')),
		moveTargets().map((f) => h('option', { value: f.id }, f.name)))

		const toolbar = h('div', { class: 'wm-toolbar' },
			h('button', { class: 'wm-back wm-mobile-only', onclick: () => setView('list'), 'aria-label': _('Retour') }, '←'),
			h('button', { onclick: () => openCompose('reply', m) }, '↩ ' + _('Répondre')),
			h('button', { onclick: () => openCompose('replyAll', m) }, '↩↩ ' + _('Répondre à tous')),
			h('span', { class: 'wm-split' },
				h('button', { onclick: () => openCompose('forward', m) }, '↪ ' + _('Transférer')),
				h('button', { class: 'wm-forward-attach', title: _('Transférer en pièce jointe (.eml)'), 'aria-label': _('Transférer en pièce jointe'), onclick: () => forwardAsAttachment([m]) }, '📎')),
			h('button', { onclick: () => markUnread(m) }, _('Non lu')),
			h('span', { class: 'wm-split' },
				h('button', { class: m.flagged ? 'wm-flag-btn on' : 'wm-flag-btn', onclick: () => toggleFlag(m) }, flagButtonText(m)),
				h('button', { class: 'wm-flag-menu-btn', title: _('Échéance du suivi'), 'aria-label': _('Échéance du suivi'), 'aria-haspopup': 'menu', onclick: (e) => flagMenu(e.currentTarget, m) }, '▾')),
			moveSelect,
			h('button', { class: 'wm-danger', onclick: () => deleteMessages([m.id], role === 'deleteditems') }, role === 'deleteditems' ? _('Supprimer définitivement') : '🗑 ' + _('Supprimer')),
			junkButton([m.id], role),
			m.conversationId && role !== 'deleteditems' && role !== 'sentitems' && role !== 'drafts'
				? h('button', { class: 'wm-ignore-btn', title: _('Ignorer la conversation : ses messages, et ceux qui arriveront, vont dans les éléments supprimés'), 'aria-label': _('Ignorer la conversation'), onclick: () => ignoreConversation(m) }, '🔇')
				: null,
			// (the folder shown: a message back from « Reportés » may still be remembered there)
			role === 'inbox' || folderRole() === 'inbox' || state.snoozes.has(m.id)
				? h('button', { class: 'wm-snooze-btn', title: _('Ne plus voir ce message jusqu\'à l\'heure choisie : il revient alors dans la boîte de réception, non lu'), 'aria-haspopup': 'menu', onclick: (e) => snoozeMenu(e.currentTarget, m) }, '⏰ ' + _('Reporter'))
				: null,
			h('button', { class: 'wm-cat-btn', title: _('Catégoriser'), 'aria-label': _('Catégoriser'), onclick: (e) => categoryMenu(e.currentTarget, () => [m]) }, '🏷'),
			h('button', { class: 'wm-print-btn', title: _('Imprimer'), 'aria-label': _('Imprimer'), onclick: () => printMessage(m) }, '🖨'),
			h('button', { class: 'wm-note-btn', title: _('Garder ce message comme note (Nextcloud Notes)'), 'aria-label': _('Créer une note'), onclick: () => messageToNote(m) }, '📝'),
			h('button', { class: 'wm-matrix-send-btn', title: _('Envoyer sur Matrix (Element) : un résumé du message dans un salon'), 'aria-label': _('Envoyer sur Matrix'), onclick: () => sendToMatrix(mailSummaryForMatrix(m)) }, '💬'),
			h('button', { class: 'wm-headers-btn', title: _('En-têtes Internet : expéditeur réel, serveurs traversés, contrôles SPF, DKIM et DMARC'), 'aria-label': _('Afficher les en-têtes Internet'), onclick: () => showHeaders(m) }, '🧾'),
			h('a', {
				class: 'button wm-eml-btn',
				href: OC.generateUrl('/apps/webmail/api/accounts/' + state.accountId + '/message/eml') + '?' + new URLSearchParams({ itemId: m.id }),
				download: '',
				title: _('Télécharger le message (.eml, pour Outlook, Thunderbird…)'),
				'aria-label': _('Télécharger le message'),
			}, '⬇\uFE0E'),
			window.WebmailModules.tasks ? h('button', { class: 'wm-task-from-msg', title: _('Créer une tâche à partir de ce message'), 'aria-label': _('Créer une tâche à partir de ce message'), onclick: () => taskFromMessage(m) }, '✅') : null,
			window.WebmailModules.calendar ? h('button', { class: 'wm-event-from-msg', title: _('Créer un événement à partir de ce message'), 'aria-label': _('Créer un événement à partir de ce message'), onclick: () => eventFromMessage(m) }, '📅') : null,
			h('button', { class: 'wm-eml-save-btn', title: _('Enregistrer le message dans Fichiers (.eml)'), 'aria-label': _('Enregistrer le message dans Fichiers'), onclick: () => saveMessageToFiles(m) }, '☁️'),
			m.from && m.from.email && !isSearching() ? h('button', { class: 'wm-sender-btn', title: _('Les messages de cet expéditeur dans ce dossier : les afficher, les marquer comme lus, les déplacer ou les supprimer d\'un coup'), onclick: () => senderMessages(m) }, '👤 ' + _('Tous ses messages…')) : null,
			h('button', { class: 'wm-rule-from-msg', title: _('Créer une règle pour les messages de cet expéditeur'), onclick: () => ruleFromMessage(m) }, '📋 ' + _('Règle…')),
		)
		detailEl.appendChild(toolbar)

		const recipients = (label, list) => list && list.length
			? h('div', { class: 'wm-header-line' }, h('span', { class: 'wm-header-label' }, label), list.map(personLabel).join(', '))
			: null

		detailEl.appendChild(h('header', { class: 'wm-message-header' },
			h('h2', { class: 'wm-subject' }, m.subject || _('(sans objet)')),
			h('div', { class: 'wm-header-cats' }, categoryPills(m.categories, true)),
			h('div', { class: 'wm-header-line' }, h('span', { class: 'wm-header-label' }, _('De')), h('strong', {}, personLabel(m.from)),
				m.from && /@/.test(m.from.email || '') ? h('button', {
					type: 'button',
					class: 'wm-small wm-add-contact',
					title: _('Ajouter {email} à mes contacts', { email: m.from.email }),
					'aria-label': _('Ajouter {email} à mes contacts', { email: m.from.email }),
					onclick: (e) => addSenderToContacts(m.from, e.currentTarget),
				}, '👤＋') : null),
			recipients(_('À'), m.to),
			recipients('Cc', m.cc),
			recipients(_('Cci'), m.bcc),
			h('div', { class: 'wm-header-line wm-muted' }, formatDate(m.date, true)),
		))

		// the other messages of the conversation (the replies sent included)
		const thread = h('section', { class: 'wm-thread', hidden: true, 'aria-label': _('Conversation') })
		detailEl.appendChild(thread)
		if (m.conversationId) loadThread(m, thread)

		if (m.meeting) {
			detailEl.appendChild(meetingBanner(m))
		}

		if (state.snoozes.has(m.id)) {
			detailEl.appendChild(h('div', { class: 'wm-snooze-banner' },
				'⏰ ' + _('Reporté : revient dans la boîte de réception {date}', { date: snoozeLabel(state.snoozes.get(m.id)) }) + ' ',
				h('button', { class: 'wm-unsnooze-btn', onclick: () => unsnooze(m) }, _('Remettre dans la boîte de réception maintenant'))))
		}

		const senderEmail = (m.from && m.from.email && !m.from.email.startsWith('/') ? m.from.email : '').toLowerCase()
		const senderDomain = senderEmail.includes('@') ? senderEmail.slice(senderEmail.lastIndexOf('@')) : ''
		if (m.hasRemoteContent && !m.remoteAllowed && !m.remoteTrusted) {
			// « always for this sender »: their next messages show their images at once (as in Outlook)
			const trust = (entry) => async () => {
				try {
					await api('/trusted-images', { method: 'POST', body: { entry } })
					msgCache.clear()
					toast(_('Les images de {who} s\'afficheront désormais directement', { who: entry }))
					openMessage(m.id)
				} catch (e) { toast(e.message, true) }
			}
			detailEl.appendChild(h('div', { class: 'wm-remote-banner' },
				_('Les images distantes ont été bloquées pour protéger votre vie privée.') + ' ',
				h('button', { onclick: () => openMessage(m.id, true) }, _('Afficher les images')),
				senderEmail ? h('button', { class: 'wm-trust-sender', onclick: trust(senderEmail) }, _('Toujours pour {who}', { who: senderEmail })) : null,
				senderDomain ? h('button', { class: 'wm-trust-domain', title: _('Tous les expéditeurs de ce domaine'), onclick: trust(senderDomain) }, _('Toujours pour {who}', { who: senderDomain })) : null))
		}
		if (m.remoteTrusted) {
			detailEl.appendChild(h('div', { class: 'wm-info wm-trusted-banner' },
				_('Images affichées : {who} fait partie de vos expéditeurs de confiance.', { who: senderEmail }) + ' ',
				h('button', { class: 'wm-small', onclick: () => openAccountSettings() }, _('Gérer'))))
		}

		if (m.unsubscribe) detailEl.appendChild(unsubscribeBanner(m, senderEmail))

		if (m.attachments && m.attachments.length) {
			const list = h('ul', { class: 'wm-attachments' })
			const previewable = m.attachments.filter((a) => previewKind(a))
			m.attachments.forEach((a) => {
				const kind = previewKind(a)
				const open = kind ? () => openPreview(previewable, previewable.indexOf(a)) : null
				list.appendChild(h('li', { class: 'wm-attachment' + (kind === 'image' ? ' wm-attachment-image' : '') },
					kind === 'image'
						? h('button', { type: 'button', class: 'wm-attachment-thumb', title: _('Aperçu de {name}', { name: a.name }), onclick: open },
							h('img', { src: attachmentUrl(a, true), alt: '', loading: 'lazy' }))
						: null,
					h('a', { href: attachmentUrl(a), class: 'wm-attachment-name', title: _('Télécharger'), download: a.name }, '📎 ' + a.name),
					h('span', { class: 'wm-muted' }, ' ' + formatSize(a.size) + ' '),
					kind ? h('button', { class: 'wm-small wm-preview-btn', onclick: open }, '👁 ' + _('Aperçu')) : null,
					h('button', { class: 'wm-small', onclick: () => saveAttachment(a) }, _('Enregistrer dans Fichiers')),
					a.isItem ? null : h('button', { class: 'wm-small wm-open-nc', title: _('Une copie dans Fichiers, ouverte avec l\'éditeur ou le visionneur de Nextcloud'), onclick: (e) => openInNextcloud(a, e.currentTarget) }, '↗ ' + _('Ouvrir dans Nextcloud'))))
			})
			detailEl.appendChild(list)
			// from now on, those of this sender put into a folder of Files by themselves
			const sender = m.from && m.from.email && !m.from.email.startsWith('/') ? m.from.email : ''
			if (sender && m.attachments.some((a) => !a.isItem && !a.isInline)) {
				const account = state.accounts.find((a) => a.id === state.accountId)
				detailEl.appendChild(h('button', { class: 'wm-small wm-autofile-from', title: _('Les prochaines pièces jointes de {from} rangées dans un dossier de Fichiers', { from: sender }), onclick: () => openAutoFile(account, sender) },
					'📂 ' + _('Ranger celles de cet expéditeur…')))
			}
			if (m.attachments.length > 1) {
				// every attachment (an attached e-mail as an .eml) in one .zip, named after the subject
				const zipQuery = new URLSearchParams({ name: m.subject || _('Pièces jointes') })
				m.attachments.forEach((a) => zipQuery.append('ids[]', a.id))
				detailEl.appendChild(h('div', { class: 'wm-attachments-all' },
					h('a', {
						class: 'button wm-small wm-zip-btn',
						href: OC.generateUrl('/apps/webmail/api/accounts/' + state.accountId + '/attachments/zip') + '?' + zipQuery,
						download: '',
						title: _('Toutes les pièces jointes dans un fichier .zip'),
					}, '⬇\uFE0E ' + _('Tout télécharger (.zip)')),
					m.attachments.filter((a) => !a.isItem).length > 1
						? h('button', { class: 'wm-small', onclick: () => saveAllAttachments(m.attachments.filter((a) => !a.isItem)) }, '💾 ' + _('Tout enregistrer dans Fichiers'))
						: null))
			}
		}

		detailEl.appendChild(bodyFrame(m.body))
		if (role !== 'drafts' && role !== 'outbox') detailEl.appendChild(quickReply(m))
	}

	/**
	 * A few lines under the message, sent without the full form (with the signature,
	 * the delay to undo, and the question about a forgotten attachment, as the form does).
	 * « Plus d'options » opens the full form with what was typed.
	 */
	/** message → what was typed in its quick reply, not sent yet */
	const quickTexts = new Map()

	function quickReply(m) {
		const sender = (m.replyTo && m.replyTo[0]) || m.from
		const me = ((state.accounts.find((a) => a.id === state.accountId) || {}).email || '').toLowerCase()
		const others = [m.from].concat(m.to || [], m.cc || []).filter((p) => p && p.email && p.email.toLowerCase() !== me)
		const text = h('textarea', {
			name: 'quickReply',
			class: 'wm-quick-text',
			rows: '1',
			placeholder: _('Répondre à {name}…', { name: personShort(sender) || _('l\'expéditeur') }),
			'aria-label': _('Réponse rapide'),
		})
		const all = others.length > 1 ? h('input', { type: 'checkbox', name: 'quickReplyAll' }) : null
		const send = h('button', { type: 'submit', class: 'primary', title: _('Envoyer (Ctrl+Entrée)') }, _('Envoyer'))
		const box = h('form', {
			class: 'wm-quick-reply',
			onsubmit: async (e) => {
				e.preventDefault()
				const typed = text.value.trim()
				if (!typed) { text.focus(); return }
				const probe = h('div', {}, typed)
				if (mentionsAttachment(probe) && !window.confirm(_('Le message parle d\'une pièce jointe, mais aucun fichier n\'est joint. Envoyer quand même ?'))) return
				const mode = all && all.checked ? 'replyAll' : 'reply'
				const account = state.accounts.find((a) => a.id === state.accountId)
				// from the shared mailbox the message is in, as the full form does
				const mailboxes = shared.accountId === state.accountId ? shared.list : []
				const fromMailbox = mailboxes.find((mb) => (mb.folders || []).some((f) => f.id === m.folderId))
				const html = '<div>' + escapeHtml(typed).replace(/\n/g, '<br>') + '</div>'
					+ (account && account.signature ? '<div><br></div><div class="wm-signature">-- <br>' + signatureHtml(account.signature) + '</div>' : '')
				const fd = new FormData()
				Object.entries({
					mode, to: '', cc: '', bcc: '',
					subject: /^(re|tr|fw|fwd)\s?:/i.test(m.subject || '') ? m.subject : _('RE : ') + (m.subject || ''),
					bodyHtml: html, importance: 'Normal', referenceId: m.id, draftId: '', from: fromMailbox ? fromMailbox.email : '',
				}).forEach(([k, v]) => fd.append(k, v))
				text.value = ''
				quickTexts.delete(msgKey(m.id))
				box.classList.remove('open')
				const delay = undoDelay()
				if (delay && !await undoCountdown(delay)) {
					text.value = typed
					box.classList.add('open')
					if (box.isConnected) text.focus()
					toast(_('Envoi annulé'))
					return
				}
				send.disabled = true
				try {
					await api('/accounts/' + state.accountId + '/send', { method: 'POST', body: fd })
					threadCache.clear()
					rememberRecipients(personLabel(sender))
					toast(_('Message envoyé'))
					if (folderRole() === 'sentitems') setTimeout(() => loadMessages(true), 1500)
				} catch (err) {
					toast(err.message, true)
					// nothing lost: the text is put back (in the message shown, if it is still this one)
					text.value = typed
					box.classList.add('open')
				} finally {
					send.disabled = false
				}
			},
		},
		text,
		h('div', { class: 'wm-quick-actions' },
			send,
			all ? h('label', { class: 'wm-radio' }, all, ' ' + _('Répondre à tous')) : null,
			h('button', {
				type: 'button',
				class: 'wm-quick-more',
				onclick: () => { quickTexts.delete(msgKey(m.id)); openCompose(all && all.checked ? 'replyAll' : 'reply', m, { body: text.value }) },
			}, _('Plus d\'options…'))))
		text.addEventListener('focus', () => box.classList.add('open'))
		text.addEventListener('keydown', (e) => {
			if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); box.requestSubmit() }
		})
		// what was typed survives a redraw of the message (images shown, flag…)
		const kept = quickTexts.get(msgKey(m.id))
		if (kept) { text.value = kept; box.classList.add('open') }
		text.addEventListener('input', () => { if (text.value) quickTexts.set(msgKey(m.id), text.value); else quickTexts.delete(msgKey(m.id)) })
		return box
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
				toggle.textContent = (open ? '▾ ' : '▸ ') + _('Conversation : {count} messages', { count: items.length })
				try { localStorage.setItem('webmail.threadOpen', open ? '1' : '0') } catch (e) { /* ignore */ }
			},
		}, '▸ ' + _('Conversation : {count} messages', { count: items.length }))
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
									h('button', { type: 'button', class: 'wm-small', onclick: () => openMessage(x.id) }, _('Ouvrir ce message')),
									h('button', { type: 'button', class: 'wm-small', onclick: () => openCompose('reply', full) }, '↩ ' + _('Répondre'))),
								bodyFrame(full.body))
						} catch (err) {
							clear(body)
							body.appendChild(h('div', { class: 'wm-form-error' }, err.message))
						}
					},
				},
				h('span', { class: 'wm-thread-from' }, personShort(x.from) || x.displayTo || _('(expéditeur inconnu)')),
				(() => {
					const tags = [own && own.id !== m.folderId ? own.name : null, isThis ? _('ce message') : null].filter(Boolean)
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
		toggle.textContent = (open ? '▾ ' : '▸ ') + _('Conversation : {count} messages', { count: items.length })
		toggle.title = others > 1
			? _('{count} autres messages de cette conversation, réponses envoyées comprises', { count: others })
			: _('{count} autre message de cette conversation, réponses envoyées comprises', { count: others })
		clear(box)
		box.append(toggle, list)
		box.hidden = false
	}

	/**
	 * A mailto: address (RFC 6068) as what a new message is filled with:
	 * « mailto:a@x.fr,b@x.fr?cc=c@x.fr&subject=Devis&body=Bonjour ».
	 */
	function parseMailto(uri) {
		const out = { to: '', cc: '', bcc: '', subject: '', body: '' }
		const m = /^mailto:([^?]*)(?:\?(.*))?$/is.exec(String(uri || '').trim())
		if (!m) return out
		const decode = (v) => { try { return decodeURIComponent(v.replace(/\+/g, '%20')) } catch (e) { return v } }
		const list = (v) => decode(v).split(/[,;]/).map((x) => x.trim()).filter(Boolean)
		const to = list(m[1])
		const cc = []
		const bcc = []
		;(m[2] || '').split('&').forEach((pair) => {
			const i = pair.indexOf('=')
			if (i < 1) return
			const key = pair.slice(0, i).toLowerCase()
			const value = pair.slice(i + 1)
			if (key === 'to') to.push(...list(value))
			else if (key === 'cc') cc.push(...list(value))
			else if (key === 'bcc') bcc.push(...list(value))
			else if (key === 'subject') out.subject = decode(value)
			else if (key === 'body') out.body = decode(value)
		})
		const field = (xs) => (xs.length ? xs.join(', ') + ', ' : '')
		out.to = field(to)
		out.cc = field(cc)
		out.bcc = field(bcc)
		return out
	}

	/** The (already cleaned) HTML of a message in a sandboxed frame, as tall as its content. */
	function bodyFrame(html) {
		const frame = h('iframe', {
			class: 'wm-body-frame',
			title: _('Contenu du message'),
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
			// an e-mail address in the message: a new message here, not another program
			try {
				frame.contentDocument.addEventListener('click', (e) => {
					const a = e.target.closest && e.target.closest('a[href]')
					if (!a || !/^mailto:/i.test(a.getAttribute('href'))) return
					e.preventDefault()
					openCompose('new', null, parseMailto(a.getAttribute('href')))
				})
			} catch (e) { /* ignore */ }
			try {
				frame.contentDocument.querySelectorAll('img').forEach((img) => img.addEventListener('load', resize))
			} catch (e) { /* ignore */ }
			setTimeout(resize, 500)
		})
		return h('div', { class: 'wm-body' }, frame)
	}

	/**
	 * The sender of a message in « Contacts » (the default address book), unless an
	 * own contact already has this address.
	 */
	async function addSenderToContacts(person, button) {
		const email = person.email.trim().toLowerCase()
		button.disabled = true
		try {
			const found = await api('/accounts/' + state.accountId + '/contacts', { query: { q: email, book: 'contacts', offset: 0, limit: 20 } })
			const known = (found.items || []).find((c) => [c.email].concat(c.emails || []).filter(Boolean).some((x) => x.toLowerCase() === email))
			if (known) { toast(_('{name} est déjà dans vos contacts', { name: known.displayName || email })); return }
			// « Jean Dupont » → first name and last name; an address alone stays the display name
			const name = (person.name || '').replace(/^"|"$/g, '').trim()
			const words = name && name.toLowerCase() !== email ? name.split(/\s+/) : []
			const contact = {
				displayName: name || email,
				givenName: words.length > 1 ? words.slice(0, -1).join(' ') : '',
				surname: words.length > 1 ? words[words.length - 1] : '',
				emails: [email],
			}
			await api('/accounts/' + state.accountId + '/contacts', { method: 'POST', body: { contact, book: 'contacts' } })
			toast(_('{name} ajouté à vos contacts', { name: contact.displayName }))
		} catch (e) {
			toast(e.message, true)
		} finally {
			button.disabled = false
		}
	}

	/** Print a message: its headers and its (already cleaned) content, in a page of its own. */
	/**
	 * The Internet headers of a message, read for a person: did the receiving server authenticate
	 * the sender (SPF, DKIM, DMARC), through which servers did it come, then every header (to copy for support).
	 */
	async function showHeaders(m) {
		if (document.querySelector('.wm-overlay')) return
		const opener = document.activeElement
		const overlay = h('div', { class: 'wm-overlay' })
		const close = () => { overlay.remove(); document.removeEventListener('keydown', onKey, true); if (opener && opener.isConnected) opener.focus() }
		const onKey = (e) => { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close() } }
		document.addEventListener('keydown', onKey, true)
		overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) close() })
		const body = h('div', { class: 'wm-headers-body' }, h('div', { class: 'wm-center' }, h('span', { class: 'icon-loading' })))
		const closeBtn = h('button', { class: 'wm-modal-close', 'aria-label': _('Fermer'), onclick: close }, '✕')
		overlay.appendChild(h('div', { class: 'wm-modal wm-headers', role: 'dialog', 'aria-modal': 'true', 'aria-label': _('En-têtes Internet') },
			h('div', { class: 'wm-modal-header' }, h('h2', {}, _('En-têtes Internet')), closeBtn), body))
		document.body.appendChild(overlay)
		closeBtn.focus()
		let r
		try {
			r = await api('/accounts/' + state.accountId + '/message/headers', { query: { itemId: m.id } })
		} catch (e) {
			clear(body)
			body.appendChild(h('p', { class: 'wm-form-error' }, e.message))
			return
		}
		clear(body)
		if (!r.headers.length) {
			body.appendChild(h('p', {}, _('Ce message n\'a pas d\'en-têtes Internet : il a été écrit dans cette boîte (brouillon, message envoyé…).')))
			return
		}
		// the checks of the receiving server
		const LABELS = { pass: _('réussi'), fail: _('échoué'), softfail: _('douteux'), neutral: _('neutre'), none: _('absent'), temperror: _('erreur'), permerror: _('erreur') }
		const checks = [['spf', 'SPF', _('Le serveur d\'envoi est autorisé par le domaine de l\'expéditeur')],
			['dkim', 'DKIM', _('Le message est signé par le domaine et n\'a pas été modifié en route')],
			['dmarc', 'DMARC', _('L\'adresse affichée dans « De » correspond au domaine vérifié')]]
		const failed = checks.some(([k]) => ['fail', 'softfail', 'permerror'].includes(r.auth[k]))
		body.appendChild(h('div', { class: 'wm-auth-checks' }, checks.map(([k, name, help]) => {
			const v = r.auth[k]
			const cls = v === 'pass' ? 'pass' : (['fail', 'softfail', 'permerror'].includes(v) ? 'fail' : 'unknown')
			return h('span', { class: 'wm-auth wm-auth-' + cls, dataset: { check: k }, title: help },
				(cls === 'pass' ? '✔ ' : cls === 'fail' ? '✖ ' : '? ') + name + ' : ' + (v ? (LABELS[v] || v) : _('non vérifié')))
		})))
		if (failed) {
			body.appendChild(h('p', { class: 'wm-auth-warning', role: 'alert' }, '⚠ ' + _('L\'expéditeur n\'a pas pu être authentifié : ce message peut être une usurpation. Méfiez-vous de ses liens et de ses pièces jointes.')))
		}
		// the way of the message, with the time taken by each step
		if (r.hops.length) {
			let prev = null
			body.appendChild(h('h3', {}, _('Trajet du message')))
			body.appendChild(h('ol', { class: 'wm-hops' }, r.hops.map((hop) => {
				const t = hop.date ? new Date(hop.date) : null
				const delay = t && prev ? Math.round((t - prev) / 1000) : null
				if (t) prev = t
				return h('li', {},
					h('span', { class: 'wm-hop-servers' }, (hop.from || '?') + ' → ' + (hop.by || '?')),
					t ? h('span', { class: 'wm-hop-date' }, ' · ' + t.toLocaleString(locale(), { dateStyle: 'short', timeStyle: 'medium' })) : null,
					delay !== null && delay >= 0 ? h('span', { class: 'wm-hop-delay' + (delay > 300 ? ' slow' : '') }, ' (+' + _('{n} s', { n: delay }) + ')') : null)
			})))
		}
		const copy = h('button', {
			type: 'button',
			class: 'wm-headers-copy',
			onclick: async () => {
				try {
					await navigator.clipboard.writeText(r.raw)
					toast(_('En-têtes copiés'))
				} catch (e) { toast(_('Copie impossible : sélectionnez le texte et copiez-le'), true) }
			},
		}, '📋 ' + _('Copier les en-têtes'))
		body.appendChild(h('details', { class: 'wm-headers-all' },
			h('summary', {}, _('Tous les en-têtes ({n})', { n: r.headers.length })),
			h('table', {}, h('tbody', {}, r.headers.map((x) => h('tr', {}, h('th', {}, x.name), h('td', {}, x.value)))))))
		body.appendChild(h('div', { class: 'wm-compose-actions' }, copy))
	}

	function printMessage(m) {
		const w = window.open('', '_blank')
		if (!w) { toast(_('Le navigateur a bloqué la fenêtre d\'impression'), true); return }
		const line = (label, value) => value ? '<tr><th>' + escapeHtml(label) + '</th><td>' + escapeHtml(value) + '</td></tr>' : ''
		const people = (list) => (list || []).map(personLabel).join(', ')
		w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + escapeHtml(m.subject || _('Message')) + '</title>'
			+ '<style>body{font-family:Calibri,Arial,sans-serif;font-size:11pt;margin:24px;color:#000}h1{font-size:16pt;margin:0 0 8px}'
			+ 'table{border-collapse:collapse;margin-bottom:12px}th{text-align:left;padding:2px 12px 2px 0;color:#555;font-weight:normal;vertical-align:top}'
			+ 'hr{border:0;border-top:1px solid #999;margin:12px 0}img{max-width:100%}</style></head><body>'
			+ '<h1>' + escapeHtml(m.subject || _('(sans objet)')) + '</h1><table>'
			+ line(_('De'), personLabel(m.from)) + line(_('À'), people(m.to)) + line('Cc', people(m.cc)) + line(_('Date'), formatDate(m.date, true))
			+ line(_('Pièces jointes'), (m.attachments || []).map((a) => a.name).join(', '))
			+ '</table><hr>' + (m.body || '') + '</body></html>')
		w.document.close()
		w.focus()
		setTimeout(() => w.print(), 300)
	}

	function formatRange(start, end, allDay) {
		if (!start) return ''
		const s = new Date(start)
		const e = new Date(end || start)
		const day = (d) => d.toLocaleDateString(locale(), { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
		const time = (d) => d.toLocaleTimeString(locale(), { hour: '2-digit', minute: '2-digit' })
		if (allDay) {
			const last = new Date(e.getTime() - 86400000)
			return last > s ? _('du {start} au {end}', { start: day(s), end: day(last) }) : _('{date} (journée entière)', { date: day(s) })
		}
		if (s.toDateString() === e.toDateString()) return day(s) + ', ' + time(s) + ' – ' + time(e)
		return day(s) + ' ' + time(s) + ' – ' + day(e) + ' ' + time(e)
	}

	const RESPONSE_LABELS = () => ({ Accept: _('Vous avez accepté'), Tentative: _('Vous avez répondu « provisoire »'), Decline: _('Vous avez refusé'), Organizer: _('Vous êtes l\'organisateur') })

	/** What a colleague (name) answered, for their delegate. */
	const DELEGATE_RESPONSE = (name) => ({ Accept: _('{name} a accepté', { name }), Tentative: _('{name} a répondu « provisoire »', { name }), Decline: _('{name} a refusé', { name }) })

	function meetingBanner(m) {
		const info = m.meeting
		// copy of a colleague's invitation received as their delegate
		const principal = info.onBehalfOf ? (info.onBehalfOf.name || info.onBehalfOf.email) : null
		const responseLine = principal
			? (DELEGATE_RESPONSE(principal)[info.response] || null)
			: RESPONSE_LABELS()[info.response]
		const box = h('div', { class: 'wm-meeting' + (info.cancelled ? ' cancelled' : '') + (principal ? ' delegate' : '') },
			h('div', { class: 'wm-meeting-title' }, info.cancelled ? '❌ ' + _('Réunion annulée')
				: '📅 ' + (principal ? _('Invitation reçue pour {name} (vous êtes son délégué)', { name: principal }) : _('Invitation à une réunion'))),
			h('div', {}, (() => { const r = formatRange(info.start, info.end, info.allDay); return r.charAt(0).toUpperCase() + r.slice(1) })()),
			info.location ? h('div', { class: 'wm-muted' }, '📍 ' + info.location) : null,
			responseLine ? h('div', { class: 'wm-muted' }, responseLine) : null)
		if (!info.cancelled) {
			const answer = async (response, label) => {
				if (principal && !window.confirm(_('Répondre « {answer} » au nom de {name} ? L\'organisateur recevra la réponse de votre part, au nom de {name}.', { answer: label, name: principal }))) return
				try {
					await api('/accounts/' + state.accountId + '/calendar/respond', { method: 'POST', body: { eventId: m.id, response } })
					toast(principal ? _('Réponse envoyée au nom de {name} : {answer}', { name: principal, answer: label }) : _('Réponse envoyée : {answer}', { answer: label }))
					// Exchange files the processed invitation in "Deleted Items"
					removeFromList([m.id])
					loadFolders()
				} catch (e) { toast(e.message, true) }
			}
			box.appendChild(h('div', { class: 'wm-meeting-actions' },
				principal ? h('span', { class: 'wm-muted' }, _('Répondre au nom de {name} :', { name: principal })) : null,
				h('button', { class: 'primary', onclick: () => answer('accept', _('accepté')) }, '✓ ' + _('Accepter')),
				h('button', { onclick: () => answer('tentative', _('provisoire')) }, '? ' + _('Provisoire')),
				h('button', { class: 'wm-danger', onclick: () => answer('decline', _('refusé')) }, '✗ ' + _('Refuser'))))
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
		ids.forEach((id) => noteEdit(id, { gone: true }))
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
			ids.forEach((id) => noteEdit(id, { gone: false }))
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
			title: notJunk ? _('Remettre dans la boîte de réception et faire confiance à l\'expéditeur') : _('Déplacer dans « Courrier indésirable » et bloquer l\'expéditeur'),
			onclick: () => markJunk(getIds ? getIds() : ids, !notJunk),
		}, notJunk ? '✔ ' + _('Pas indésirable') : '⛔ ' + _('Indésirable'))
	}

	async function markJunk(ids, junk) {
		ids.forEach((id) => state.checked.delete(id))
		const undo = removeFromList(ids)
		try {
			await api('/accounts/' + state.accountId + '/junk', { method: 'POST', body: { itemIds: ids, junk } })
			const count = ids.length
			toast(junk
				? (count > 1 ? _('{count} messages dans « Courrier indésirable » ; leurs expéditeurs sont bloqués', { count }) : _('Message dans « Courrier indésirable » ; l\'expéditeur est bloqué'))
				: (count > 1 ? _('{count} messages remis dans la boîte de réception ; leurs expéditeurs sont considérés comme fiables', { count }) : _('Message remis dans la boîte de réception ; l\'expéditeur est considéré comme fiable')))
			state.folders.filter((f) => f.role === (junk ? 'junkemail' : 'inbox')).forEach((f) => listCache.delete(state.accountId + '|' + f.id))
			loadFolders()
		} catch (e) {
			undo()
			toast(e.message, true)
		}
	}

	/** « demain à 08:00 », « lun. 16 nov. à 08:00 »: when a message put aside comes back. */
	function snoozeLabel(until) {
		const d = new Date(until)
		if (isNaN(d)) return ''
		const now = new Date()
		const day = (n) => ymdLocal(new Date(now.getFullYear(), now.getMonth(), now.getDate() + n))
		const time = d.toLocaleTimeString(locale(), { hour: '2-digit', minute: '2-digit' })
		if (ymdLocal(d) === day(0)) return _('aujourd\'hui à {time}', { time })
		if (ymdLocal(d) === day(1)) return _('demain à {time}', { time })
		return _('{day} à {time}', { day: d.toLocaleDateString(locale(), { weekday: 'short', day: 'numeric', month: 'short' }), time })
	}

	/** The times offered, as Outlook and Gmail do: later today, tomorrow morning, this weekend, next week. */
	function snoozeChoices() {
		const now = new Date()
		const at = (days, hour) => new Date(now.getFullYear(), now.getMonth(), now.getDate() + days, hour, 0, 0)
		const wd = now.getDay() // 0 = Sunday
		return [
			now.getHours() < 17 ? ['today', _('Plus tard aujourd\'hui'), at(0, 18)] : null,
			['tomorrow', _('Demain matin'), at(1, 8)],
			wd >= 1 && wd <= 4 ? ['weekend', _('Ce week-end'), at(6 - wd, 9)] : null,
			['nextweek', _('La semaine prochaine'), at(((8 - wd) % 7) || 7, 8)],
		].filter(Boolean)
	}

	function snoozeMenu(anchor, m) {
		document.querySelectorAll('.wm-cat-menu').forEach((x) => x.remove())
		const menu = h('div', { class: 'wm-cat-menu wm-flag-menu wm-snooze-menu', role: 'menu', 'aria-label': _('Reporter') })
		const close = () => { menu.remove(); document.removeEventListener('mousedown', outside, true); document.removeEventListener('keydown', esc, true); if (anchor.isConnected) anchor.focus() }
		const outside = (e) => { if (!menu.contains(e.target) && e.target !== anchor) close() }
		const esc = (e) => { if (e.key === 'Escape') { e.stopPropagation(); close() } }
		document.addEventListener('mousedown', outside, true)
		document.addEventListener('keydown', esc, true)
		const pick = (date) => { close(); snooze(m, date) }
		menu.appendChild(h('div', { class: 'wm-cat-title' }, _('Reporter jusqu\'à…')))
		snoozeChoices().forEach(([key, label, date]) => menu.appendChild(h('button', {
			type: 'button', role: 'menuitem', class: 'wm-flag-choice', dataset: { choice: key }, onclick: () => pick(date),
		}, h('span', {}, label), h('span', { class: 'wm-muted' }, snoozeLabel(date.toISOString())))))
		const pad = (n) => String(n).padStart(2, '0')
		const local = (d) => ymdLocal(d) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes())
		const tomorrow = new Date(Date.now() + 86400000)
		tomorrow.setHours(8, 0, 0, 0)
		const input = h('input', { type: 'datetime-local', name: 'snoozeUntil', 'aria-label': _('Date et heure'), min: local(new Date()), value: local(tomorrow) })
		menu.appendChild(h('form', {
			class: 'wm-flag-custom',
			onsubmit: (e) => { e.preventDefault(); const d = new Date(input.value); if (!isNaN(d)) pick(d) },
		}, input, h('button', { type: 'submit', class: 'primary' }, _('OK'))))
		document.body.appendChild(menu)
		const r = anchor.getBoundingClientRect()
		menu.style.top = Math.min(r.bottom + 4, window.innerHeight - menu.offsetHeight - 8) + 'px'
		menu.style.left = Math.max(8, Math.min(r.left, window.innerWidth - menu.offsetWidth - 8)) + 'px'
		const first = menu.querySelector('button')
		if (first) first.focus()
	}

	/** The message leaves the list now, for « Reportés », and comes back to the inbox (unread) at the time chosen. */
	async function snooze(m, date) {
		const undo = removeFromList([m.id])
		try {
			const r = await api('/accounts/' + state.accountId + '/snooze', { method: 'POST', body: { itemIds: [m.id], until: date.toISOString() } })
			state.snoozes.delete(m.id)
			r.ids.forEach((id) => state.snoozes.set(id, r.until))
			toast(_('Message reporté : il reviendra {date}', { date: snoozeLabel(r.until) }))
			loadFolders()
		} catch (e) {
			undo()
			toast(e.message, true)
		}
	}

	async function unsnooze(m) {
		const undo = removeFromList([m.id])
		try {
			await api('/accounts/' + state.accountId + '/snooze/back', { method: 'POST', body: { itemId: m.id } })
			state.snoozes.delete(m.id)
			msgCache.delete(msgKey(m.id))
			toast(_('Message remis dans la boîte de réception'))
			const inbox = state.folders.find((f) => f.role === 'inbox')
			if (inbox) listCache.delete(state.accountId + '|' + inbox.id)
			loadFolders()
		} catch (e) {
			undo()
			toast(e.message, true)
		}
	}

	/**
	 * « Annuler » after a move or a delete: the messages go back where each one was
	 * (with their new ids: Exchange gives an item another id in another folder).
	 * @param {Array<{id: ?string, from: string}>} moved
	 */
	function undoMove(moved) {
		const back = moved.filter((x) => x.id && x.from)
		if (!back.length) return null
		return {
			label: _('Annuler'),
			run: async () => {
				const byFolder = new Map()
				back.forEach((x) => byFolder.set(x.from, (byFolder.get(x.from) || []).concat(x.id)))
				try {
					for (const [folder, ids] of byFolder) {
						await api('/accounts/' + state.accountId + '/move', { method: 'POST', body: { itemIds: ids, target: folder } })
						listCache.delete(state.accountId + '|' + folder)
					}
					toast(_('Annulé'))
					if (byFolder.has(state.folderId) || isSearching()) loadMessages(true)
					loadFolders()
				} catch (e) { toast(e.message, true) }
			},
		}
	}

	/** Where each message was before it left the list (in a search of all folders, its own folder). */
	function originsOf(ids) {
		return ids.map((id) => {
			const m = state.messages.find((x) => x.id === id)
			return (m && m.folderId) || state.folderId
		})
	}

	async function moveMessages(ids, target, targetName) {
		const from = originsOf(ids)
		const undo = removeFromList(ids)
		try {
			const r = await api('/accounts/' + state.accountId + '/move', { method: 'POST', body: { itemIds: ids, target } })
			const name = targetName || folderName(target)
			const count = ids.length
			toast(name
				? (count > 1 ? _('{count} messages déplacés vers « {name} »', { count, name }) : _('Message déplacé vers « {name} »', { name }))
				: (count > 1 ? _('{count} messages déplacés', { count }) : _('Message déplacé')),
			false, undoMove(((r && r.ids) || []).map((id, i) => ({ id, from: from[i] }))))
			listCache.delete(state.accountId + '|' + target)
			loadFolders()
		} catch (e) {
			undo()
			toast(e.message, true)
		}
	}

	async function deleteMessages(ids, permanent) {
		if (permanent && !window.confirm(ids.length > 1 ? _('Supprimer définitivement ces {count} messages ?', { count: ids.length }) : _('Supprimer définitivement ce message ?'))) return
		ids.forEach((id) => state.checked.delete(id))
		const from = originsOf(ids)
		// the « Éléments supprimés » of the mailbox shown: the messages are moved there, so that it can be undone
		const trash = state.folders.find((f) => f.role === 'deleteditems')
		const undo = removeFromList(ids)
		try {
			let back = null
			if (!permanent && trash && !from.includes(trash.id)) {
				const r = await api('/accounts/' + state.accountId + '/move', { method: 'POST', body: { itemIds: ids, target: trash.id } })
				back = undoMove(((r && r.ids) || []).map((id, i) => ({ id, from: from[i] })))
			} else {
				await api('/accounts/' + state.accountId + '/delete', { method: 'POST', body: { itemIds: ids, permanent: !!permanent } })
			}
			const count = ids.length
			toast(permanent
				? (count > 1 ? _('{count} messages supprimés définitivement', { count }) : _('Message supprimé définitivement'))
				: (count > 1 ? _('{count} messages placés dans les éléments supprimés', { count }) : _('Message placé dans les éléments supprimés')),
			false, back)
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
		const download = h('a', { class: 'button', download: '' }, '⬇ ' + _('Télécharger'))
		const save = h('button', { type: 'button', onclick: () => saveAttachment(items[i]) }, _('Enregistrer dans Fichiers'))
		const prev = h('button', { type: 'button', class: 'wm-preview-nav', 'aria-label': _('Pièce jointe précédente'), onclick: () => go(-1) }, '‹')
		const next = h('button', { type: 'button', class: 'wm-preview-nav', 'aria-label': _('Pièce jointe suivante'), onclick: () => go(1) }, '›')
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
					if (!res.ok) throw new Error(_('Erreur {status}', { status: res.status }))
					const text = await res.text()
					if (mine !== seq) return
					clear(body)
					// shown as text only: nothing in the file is interpreted
					body.appendChild(h('pre', { class: 'wm-preview-text' }, text))
				} catch (e) {
					if (mine !== seq) return
					clear(body)
					body.appendChild(h('div', { class: 'wm-empty' }, _('Aperçu impossible : {error}', { error: e.message })))
				}
			}
		}
		overlay.addEventListener('mousedown', (e) => { if (e.target === overlay || e.target === body) close() })
		document.addEventListener('keydown', onKey, true)
		overlay.appendChild(h('div', { class: 'wm-preview-frame', role: 'dialog', 'aria-modal': 'true', 'aria-label': _('Aperçu de la pièce jointe') },
			h('div', { class: 'wm-preview-header' }, h('div', { class: 'wm-preview-names' }, title, counter),
				download, save, h('button', { type: 'button', class: 'wm-modal-close', 'aria-label': _('Fermer'), onclick: close }, '✕')),
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
				} catch (e) { toast(_('{name} : {error}', { name: a.name, error: e.message }), true) }
			}
			const folder = lastPath.replace(/\/[^/]*$/, '/')
			if (done) toast(done > 1 ? _('{count} pièces jointes enregistrées dans {path}', { count: done, path: folder }) : _('{count} pièce jointe enregistrée dans {path}', { count: done, path: folder }))
		}
		if (OC.dialogs && typeof OC.dialogs.filepicker === 'function') {
			OC.dialogs.filepicker(_('Choisir le dossier de destination'), (dir) => doSave(dir || '/'), false, 'httpd/unix-directory', true, OC.dialogs.FILEPICKER_TYPE_CHOOSE)
		} else {
			doSave('/' + _('Pièces jointes'))
		}
	}

	/** What an event or a task made from a message takes from it: its subject (without « RE : »), « Message de … du … : » and its text. */
	function messageSummary(m) {
		// the text of the message, one line per paragraph, line break or table row
		const doc = new DOMParser().parseFromString(m.body || '', 'text/html')
		doc.querySelectorAll('script, style, title').forEach((e) => e.remove())
		doc.querySelectorAll('br').forEach((e) => e.replaceWith('\n'))
		doc.querySelectorAll('p, div, li, tr, h1, h2, h3, h4, h5, h6, blockquote, pre, table').forEach((e) => e.append('\n'))
		const text = (doc.body.textContent || '').replace(/[ \t\u00a0]+/g, ' ').replace(/ ?\n ?/g, '\n').replace(/\n{3,}/g, '\n\n').trim()
		// the values put in afterwards: the translation would take « <address> » for an HTML tag and drop it
		const head = _('Message de {sender} du {date} :').replace('{sender}', personLabel(m.from)).replace('{date}', formatDate(m.date, true))
		return {
			subject: (m.subject || '').replace(/^((re|tr|fw|fwd)\s?:\s*)+/i, ''),
			body: head + '\n\n' + (text.length > 4000 ? text.slice(0, 4000) + '…' : text),
		}
	}

	/** A new event in the calendar, from the message: its subject, its text, and its people (not me) as attendees. */
	function eventFromMessage(m) {
		const me = ((state.accounts.find((a) => a.id === state.accountId) || {}).email || '').toLowerCase()
		const people = [m.from].concat(m.to || [], m.cc || [])
			.map((p) => (p && p.email ? p.email.trim().toLowerCase() : ''))
			.filter((e, i, all) => /@/.test(e) && e !== me && all.indexOf(e) === i)
		const prefill = Object.assign(messageSummary(m), { attendees: people })
		selectModule('calendar')
		window.WebmailModules.calendar.newFromMessage(core, prefill)
	}

	/** A new task, from the message: its subject as the title, its text in the notes (« flag for follow-up », as a real task). */
	function taskFromMessage(m) {
		const summary = messageSummary(m)
		selectModule('tasks')
		window.WebmailModules.tasks.newFromMessage(core, { subject: summary.subject, notes: summary.body })
	}

	/** The whole message as an .eml file, in a Files folder chosen by the user. */
	function saveMessageToFiles(m) {
		const doSave = async (dir) => {
			try {
				const res = await api('/accounts/' + state.accountId + '/message/eml/save', { method: 'POST', body: { itemId: m.id, path: dir } })
				toast(_('Enregistré dans {path}', { path: res.path }))
			} catch (e) { toast(e.message, true) }
		}
		if (OC.dialogs && typeof OC.dialogs.filepicker === 'function') {
			OC.dialogs.filepicker(_('Choisir le dossier de destination'), (dir) => doSave(dir || '/'), false, 'httpd/unix-directory', true, OC.dialogs.FILEPICKER_TYPE_CHOOSE)
		} else {
			doSave('/' + _('Pièces jointes'))
		}
	}

	/** A copy in Files (« Pièces jointes »), opened there: Office, Text, PDF, images… as this Nextcloud has them. */
	async function openInNextcloud(a, button) {
		// (opened at once, then sent to the file: a popup blocker lets it through)
		const tab = window.open('', '_blank')
		button.disabled = true
		try {
			const r = await api('/accounts/' + state.accountId + '/attachment/open', { method: 'POST', body: { attachmentId: a.id } })
			toast(_('Copié dans {path}', { path: r.path }))
			if (tab) tab.location = r.url; else window.open(r.url, '_blank', 'noopener')
		} catch (e) {
			if (tab) tab.close()
			toast(e.message, true)
		} finally { button.disabled = false }
	}

	/** The message as a note of Nextcloud Notes (a Markdown file in the Notes folder), with the link back. */
	async function messageToNote(m) {
		try {
			const r = await api('/accounts/' + state.accountId + '/message/note', { method: 'POST', body: { itemId: m.id } })
			toast(_('Note créée : {path}', { path: r.path }), false, {
				label: r.app === 'notes' ? _('Ouvrir dans Notes') : _('Ouvrir dans Fichiers'),
				run: () => window.open(r.url, '_blank', 'noopener'),
			})
		} catch (e) { toast(e.message, true) }
	}

	function saveAttachment(a) {
		const doSave = async (dir) => {
			try {
				const res = await api('/accounts/' + state.accountId + '/attachment/save', { method: 'POST', body: { attachmentId: a.id, path: dir } })
				toast(_('Enregistré dans {path}', { path: res.path }))
			} catch (e) { toast(e.message, true) }
		}
		if (OC.dialogs && typeof OC.dialogs.filepicker === 'function') {
			OC.dialogs.filepicker(_('Choisir le dossier de destination'), (dir) => doSave(dir || '/'), false, 'httpd/unix-directory', true, OC.dialogs.FILEPICKER_TYPE_CHOOSE)
		} else {
			doSave('/' + _('Pièces jointes'))
		}
	}

	// ------------------------------------------------------------------
	// compose
	// ------------------------------------------------------------------

	/**
	 * Address autocompletion (Exchange contacts + company directory) on a
	 * comma-separated recipients field.
	 */
	// ------------------------------------------------------------------
	// recent recipients: the people written to, first in the completion (this browser, per account)
	// ------------------------------------------------------------------

	const RECENT_MAX = 100
	const recentKey = () => 'webmail.recent.' + state.accountId

	function recentRecipients() {
		try {
			const list = JSON.parse(localStorage.getItem(recentKey()) || '[]')
			return Array.isArray(list) ? list.filter((r) => r && typeof r.email === 'string') : []
		} catch (e) { return [] }
	}

	/** « Jean Dupont <jean@x.fr>, marie@y.fr » → each address, with its name. */
	function parseAddresses(text) {
		return String(text || '').split(/[,;]/).map((part) => {
			const m = part.match(/^\s*"?([^"<]*?)"?\s*<([^<>\s]+@[^<>\s]+)>\s*$/)
			if (m) return { name: m[1].trim(), email: m[2].trim() }
			const e = part.trim()
			return /^[^\s@<>]+@[^\s@<>]+\.[^\s@<>]+$/.test(e) ? { name: '', email: e } : null
		}).filter(Boolean)
	}

	/** After a message is sent: its recipients counted, the latest first. */
	function rememberRecipients(text) {
		const people = parseAddresses(text)
		if (!people.length) return
		const me = ((state.accounts.find((a) => a.id === state.accountId) || {}).email || '').toLowerCase()
		const list = recentRecipients()
		const now = Date.now()
		people.forEach((p) => {
			const key = p.email.toLowerCase()
			if (key === me) return
			const known = list.find((r) => r.email.toLowerCase() === key)
			if (known) { known.n = (known.n || 0) + 1; known.t = now; if (p.name) known.name = p.name } else list.push({ name: p.name, email: p.email, n: 1, t: now })
		})
		list.sort((a, b) => (b.t || 0) - (a.t || 0))
		try { localStorage.setItem(recentKey(), JSON.stringify(list.slice(0, RECENT_MAX))) } catch (e) { /* private mode: not remembered */ }
	}

	/** The recent recipients matching what is typed: a name or address that starts with it first, then the most used. */
	function recentMatches(token) {
		const t = fold(token)
		if (!t) return []
		const words = (r) => fold(r.name + ' ' + r.email).split(/[\s@._<>-]+/)
		return recentRecipients()
			.filter((r) => fold(r.name + ' ' + r.email).includes(t))
			.map((r) => [words(r).some((w) => w.startsWith(t)) ? 0 : 1, -(r.n || 0), -(r.t || 0), r])
			.sort((a, b) => a[0] - b[0] || a[1] - b[1] || a[2] - b[2])
			.slice(0, 5)
			.map((x) => ({ name: x[3].name, email: x[3].email, source: 'recent' }))
	}

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
			h('span', { class: 'wm-muted' }, r.email + ({ directory: ' · ' + _('annuaire'), recent: ' · ' + _('récent'), nextcloud: ' · ' + _('contacts Nextcloud'), 'nextcloud-user': ' · ' + _('compte Nextcloud') }[r.source] || '')))))
			// a late answer must not reopen the menu once the field is left
			menu.hidden = items.length === 0 || document.activeElement !== input
		}
		input.setAttribute('autocomplete', 'off')
		input.addEventListener('input', () => {
			clearTimeout(timer)
			const { token } = lastToken()
			// the people written to recently: at once, from the first letter
			const recent = recentMatches(token)
			if (!token || (token.length < 2 && !recent.length)) { items = []; close(); return }
			items = recent
			active = items.length ? 0 : -1
			render()
			if (token.length < 2) return
			// an address already complete (« Nom <adresse> »): nothing to look for
			if (/<[^<>@\s]+@[^<>\s]+>\s*$/.test(token)) return
			timer = setTimeout(async () => {
				// the field closed or left meanwhile (message sent, another field): no request
				if (!input.isConnected || document.activeElement !== input) return
				const mine = ++seq
				try {
					const data = await api('/accounts/' + state.accountId + '/contacts/resolve', { query: { q: token } })
					if (mine !== seq) return
					// the recent ones first, then the contacts and the directory (each address once)
					const seen = new Set(recent.map((r) => r.email.toLowerCase()))
					items = recent.concat(data.results.filter((r) => !seen.has(String(r.email).toLowerCase())))
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
	function imageDataUrl(file, maxSide = IMAGE_MAX_SIDE) {
		const read = (blob) => new Promise((resolve, reject) => {
			const r = new FileReader()
			r.onload = () => resolve(r.result)
			r.onerror = () => reject(new Error(_('Image illisible : {name}', { name: file.name })))
			r.readAsDataURL(blob)
		})
		const tooBig = () => new Error(_('Image trop lourde ({size}) : joignez-la plutôt en pièce jointe', { size: formatSize(file.size) }))
		if (file.type === 'image/gif') {
			if (file.size > IMAGE_MAX_BYTES) return Promise.reject(tooBig())
			return read(file)
		}
		return read(file).then((url) => new Promise((resolve, reject) => {
			const img = new Image()
			img.onerror = () => reject(new Error(_('Image illisible : {name}', { name: file.name })))
			img.onload = () => {
				const scale = Math.min(1, maxSide / Math.max(img.naturalWidth, img.naturalHeight))
				if (scale === 1 && file.size <= (maxSide < IMAGE_MAX_SIDE ? 200 * 1024 : 1024 * 1024)) { resolve(url); return }
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

	/** A signature from the editor (HTML), or one written in plain text before. */
	function signatureHtml(sig) {
		if (!sig) return ''
		return /<(div|p|br|b|strong|i|em|u|a|img|span|font|ul|ol|li|table|blockquote)\b[^>]*>/i.test(sig) ? sig : escapeHtml(sig).replace(/\n/g, '<br>')
	}

	/**
	 * The signature editor of an account: bold, italic, underline, links and
	 * a logo (an image, reduced to 400 pixels), like in Outlook.
	 */
	function signatureEditor(value) {
		const ed = h('div', { class: 'wm-editor wm-signature-editor', contenteditable: 'true', role: 'textbox', 'aria-multiline': 'true', 'aria-label': _('Signature') })
		ed.innerHTML = signatureHtml(value)
		const exec = (cmd, v) => { ed.focus(); document.execCommand(cmd, false, v) }
		let range = null
		const picker = h('input', { type: 'file', accept: 'image/png,image/jpeg,image/gif,image/webp', hidden: true, name: 'signatureImage', 'aria-label': _('Ajouter une image à la signature') })
		const insert = async (list) => {
			for (const f of Array.from(list).filter((x) => /^image\/(png|jpeg|gif|webp)$/.test(x.type))) {
				try {
					const url = await imageDataUrl(f, 400)
					ed.focus()
					if (range) { const sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(range); range = null }
					exec('insertHTML', '<img src="' + url + '" alt="' + escapeHtml(f.name || 'logo') + '">')
				} catch (e) { toast(e.message, true) }
			}
		}
		picker.addEventListener('change', () => { insert(picker.files); picker.value = '' })
		ed.addEventListener('paste', (e) => {
			const list = Array.from((e.clipboardData && e.clipboardData.files) || [])
			if (list.some((f) => f.type.startsWith('image/')) && !(e.clipboardData.types || []).includes('text/html')) { e.preventDefault(); insert(list) }
		})
		const tool = (label, title, action) => h('button', { type: 'button', class: 'wm-editor-btn', title, 'aria-label': title, onmousedown: (e) => e.preventDefault(), onclick: action }, label)
		const bar = h('div', { class: 'wm-editor-bar', role: 'toolbar', 'aria-label': _('Mise en forme de la signature') },
			tool(_('G'), _('Gras'), () => exec('bold')),
			tool('I', _('Italique'), () => exec('italic')),
			tool(_('S'), _('Souligné'), () => exec('underline')),
			tool('🔗', _('Insérer un lien'), () => {
				const sel = window.getSelection()
				const r = sel && sel.rangeCount ? sel.getRangeAt(0) : null
				const url = (window.prompt(_('Adresse du lien (https://…)'), 'https://') || '').trim()
				if (!/^(https?:\/\/|mailto:)\S+$/i.test(url)) return
				ed.focus()
				if (r) { sel.removeAllRanges(); sel.addRange(r) }
				if (r && !r.collapsed) exec('createLink', url)
				else exec('insertHTML', '<a href="' + escapeHtml(url) + '">' + escapeHtml(url) + '</a>')
			}),
			tool('🖼', _('Ajouter une image (logo)'), () => {
				const sel = window.getSelection()
				range = sel && sel.rangeCount && ed.contains(sel.anchorNode) ? sel.getRangeAt(0) : null
				picker.click()
			}),
			tool('⌫', _('Effacer la mise en forme'), () => { exec('removeFormat'); exec('unlink') }),
			picker)
		return {
			el: h('div', { class: 'wm-editor-wrap wm-signature-wrap' }, bar, ed),
			// empty editor: no signature
			value: () => (ed.textContent.trim() || ed.querySelector('img') ? ed.innerHTML : ''),
		}
	}

	/**
	 * « Ignorer la conversation » (as in Outlook): the conversation leaves the folders for the
	 * deleted items, and its next messages go there directly. « Annuler » brings it back.
	 */
	async function ignoreConversation(m) {
		const accountId = state.accountId
		const send = (ignore) => api('/accounts/' + accountId + '/conversation/ignore', { method: 'POST', body: { conversationId: m.conversationId, ignore } })
		try {
			await send(true)
		} catch (e) { toast(e.message, true); return }
		if (accountId !== state.accountId) return
		removeFromList([m.id])
		toast(_('Conversation ignorée : ses messages, et les suivants, vont dans les éléments supprimés'), false, {
			label: _('Annuler'),
			run: async () => {
				try {
					await send(false)
					toast(_('La conversation n\'est plus ignorée'))
				} catch (e) { toast(e.message, true) }
				if (accountId === state.accountId) { loadFolders(); loadMessages(true) }
			},
		})
		// the other messages of the conversation shown in the list
		loadFolders()
		loadMessages(true)
	}

	/** At most this many messages forwarded at once as attachments. */
	const FORWARD_MAX = 20

	/**
	 * « Transférer en pièce jointe »: a new message with each message attached whole, as an .eml
	 * file (with its own attachments; Outlook, Thunderbird… open it as a message).
	 */
	async function forwardAsAttachment(messages) {
		const list = (messages || []).filter((m) => m && m.id && !m.isMeeting)
		if (!list.length) return
		if (list.length > FORWARD_MAX) { toast(_('{count} messages au plus à la fois', { count: FORWARD_MAX }), true); return }
		const accountId = state.accountId
		toast(list.length > 1 ? _('Préparation de {count} messages…', { count: list.length }) : _('Préparation du message…'))
		try {
			const used = new Set()
			const files = []
			for (const m of list) {
				const url = OC.generateUrl('/apps/webmail/api/accounts/' + accountId + '/message/eml') + '?' + new URLSearchParams({ itemId: m.id })
				const res = await fetch(url, { headers: { requesttoken: OC.requestToken }, credentials: 'same-origin' })
				if (!res.ok) throw new Error(_('Impossible de joindre « {subject} »', { subject: m.subject || _('(sans objet)') }))
				// a file name from the subject: no folder separators, unique
				let base = (m.subject || _('message')).replace(/[\\/:*?"<>|\x00-\x1f]+/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 100) || _('message')
				let name = base + '.eml'
				for (let n = 2; used.has(name.toLowerCase()); n++) name = base + ' (' + n + ').eml'
				used.add(name.toLowerCase())
				files.push(new File([await res.blob()], name, { type: 'message/rfc822' }))
			}
			if (accountId !== state.accountId) return
			const subject = list.length === 1
				? (/^(tr|fw|fwd)\s?:/i.test(list[0].subject || '') ? list[0].subject : _('TR : ') + (list[0].subject || ''))
				: _('TR : {count} messages', { count: list.length })
			openCompose('new', null, { subject, files })
		} catch (e) {
			toast(e.message, true)
		}
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
		const ncFiles = (prefill && prefill.ncFiles ? prefill.ncFiles.slice() : []) // paths from Nextcloud Files

		const titles = { new: _('Nouveau message'), reply: _('Répondre'), replyAll: _('Répondre à tous'), forward: _('Transférer'), draft: _('Brouillon') }
		// sender: the account, or one of its shared mailboxes (by default the one the original message is in)
		const mailboxes = shared.accountId === state.accountId ? shared.list : []
		const fromMailbox = original && mailboxes.find((mb) => (mb.folders || []).some((f) => f.id === original.folderId))
		const defaultFrom = (fromMailbox || (mode === 'new' && currentMailbox()) || null)
		const fromSelect = mailboxes.length ? h('select', { name: 'from', class: 'wm-from', 'aria-label': _('De') },
			h('option', { value: account ? account.email : '' }, account ? account.email : ''),
			mailboxes.map((mb) => h('option', { value: mb.email, selected: defaultFrom === mb }, mb.name !== mb.email ? mb.name + ' <' + mb.email + '>' : mb.email))) : null
		const fromField = fromSelect
			? h('label', { class: 'wm-muted wm-from-label' }, _('De :') + ' ', fromSelect)
			: h('span', { class: 'wm-muted' }, _('De :') + ' ' + (account ? account.email : ''))
		const isResponse = mode === 'reply' || mode === 'replyAll'
		// a draft opened from « Brouillons »: original is the draft itself
		const isDraft = mode === 'draft'
		let draftId = isDraft ? original.id : null

		const to = h('input', { type: 'text', name: 'to', placeholder: _('adresse@exemple.fr') + ', …', autocomplete: 'email', multiple: true })
		const cc = h('input', { type: 'text', name: 'cc', placeholder: '' })
		const bcc = h('input', { type: 'text', name: 'bcc', placeholder: '' })
		const subject = h('input', { type: 'text', name: 'subject' })
		const body = h('div', {
			class: 'wm-editor',
			contenteditable: 'true',
			role: 'textbox',
			'aria-multiline': 'true',
			'aria-label': _('Message'),
			spellcheck: 'true',
		})
		const files = h('input', { type: 'file', multiple: true, class: 'wm-file-input', 'aria-label': _('Joindre des fichiers') })
		const attachList = h('ul', { class: 'wm-compose-attachments' })
		const importance = h('select', { name: 'importance', 'aria-label': _('Importance') },
			h('option', { value: 'Normal' }, _('Importance normale')),
			h('option', { value: 'High' }, _('Importance haute')),
			h('option', { value: 'Low' }, _('Importance faible')))

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
				const prefix = mode === 'forward' ? _('TR : ') : _('RE : ')
				subject.value = /^(re|tr|fw|fwd)\s?:/i.test(original.subject || '') ? original.subject : prefix + (original.subject || '')
				subject.disabled = true
			}
			body.innerHTML = '<div><br></div>' + (account && account.signature ? '<div><br></div><div class="wm-signature">-- <br>' + signatureHtml(account.signature) + '</div>' : '')
			if (prefill) {
				if (prefill.to) to.value = prefill.to
				if (prefill.cc) cc.value = prefill.cc
				if (prefill.bcc) bcc.value = prefill.bcc
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
		const imagePicker = h('input', { type: 'file', accept: 'image/png,image/jpeg,image/gif,image/webp', multiple: true, hidden: true, name: 'inlineImages', 'aria-label': _('Insérer une image') })
		const editorBar = h('div', { class: 'wm-editor-bar', role: 'toolbar', 'aria-label': _('Mise en forme') },
			tool(_('G'), _('Gras (Ctrl+B)'), () => exec('bold')),
			tool('I', _('Italique (Ctrl+I)'), () => exec('italic')),
			tool(_('S'), _('Souligné (Ctrl+U)'), () => exec('underline')),
			tool('•', _('Liste à puces'), () => exec('insertUnorderedList')),
			tool('1.', _('Liste numérotée'), () => exec('insertOrderedList')),
			tool('🔗', _('Insérer un lien'), () => {
				const sel = window.getSelection()
				const range = sel && sel.rangeCount ? sel.getRangeAt(0) : null
				const url = (window.prompt(_('Adresse du lien (https://…)'), 'https://') || '').trim()
				if (!/^(https?:\/\/|mailto:)\S+$/i.test(url)) return
				body.focus()
				if (range) { sel.removeAllRanges(); sel.addRange(range) }
				if (range && !range.collapsed) exec('createLink', url)
				else exec('insertHTML', '<a href="' + escapeHtml(url) + '">' + escapeHtml(url) + '</a>')
			}),
			tool('❝', _('Citation'), () => exec('formatBlock', 'blockquote')),
			tool('🖼', _('Insérer une image dans le texte (ou coller une capture d\'écran)'), () => {
				const sel = window.getSelection()
				savedImageRange = sel && sel.rangeCount && body.contains(sel.anchorNode) ? sel.getRangeAt(0) : null
				imagePicker.click()
			}),
			tool('⌫', _('Effacer la mise en forme'), () => { exec('removeFormat'); exec('unlink') }),
			tool('📄', _('Modèles de réponse'), (e) => openTemplateMenu(e.currentTarget)),
			imagePicker)

		// ---- reply templates: insert one where the cursor is, or keep this text as one
		const editorText = () => {
			const copy = body.cloneNode(true)
			copy.querySelectorAll('.wm-signature').forEach((n) => n.remove())
			return copy.innerHTML
		}
		let templateMenu = null
		const closeTemplateMenu = () => {
			if (!templateMenu) return
			templateMenu.remove()
			templateMenu = null
			document.removeEventListener('mousedown', outsideMenu, true)
		}
		const outsideMenu = (e) => { if (templateMenu && !templateMenu.contains(e.target) && !e.target.closest('.wm-editor-btn')) closeTemplateMenu() }
		async function openTemplateMenu(button) {
			if (templateMenu) { closeTemplateMenu(); return }
			const sel = window.getSelection()
			const range = sel && sel.rangeCount && body.contains(sel.anchorNode) ? sel.getRangeAt(0) : null
			const menu = templateMenu = h('div', { class: 'wm-template-menu', role: 'menu', 'aria-label': _('Modèles de réponse') },
				h('div', { class: 'wm-center' }, h('span', { class: 'icon-loading-small' })))
			menu.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.stopPropagation(); closeTemplateMenu(); button.focus() } })
			button.parentNode.appendChild(menu)
			document.addEventListener('mousedown', outsideMenu, true)
			let list = []
			try { list = await loadTemplates() } catch (e) { toast(e.message, true) }
			if (templateMenu !== menu) return
			clear(menu)
			if (!list.length) menu.appendChild(h('p', { class: 'wm-muted' }, _('Aucun modèle pour l\'instant : écrivez un texte, puis « Enregistrer ce texte comme modèle ».')))
			list.forEach((t) => menu.appendChild(h('button', {
				type: 'button',
				role: 'menuitem',
				class: 'wm-template-item',
				title: _('Insérer « {name} »', { name: t.name }),
				onclick: () => {
					closeTemplateMenu()
					body.focus()
					if (range) { const s2 = window.getSelection(); s2.removeAllRanges(); s2.addRange(range) }
					document.execCommand('insertHTML', false, t.html)
					changed()
				},
			}, t.name)))
			menu.appendChild(h('hr'))
			menu.appendChild(h('button', {
				type: 'button',
				role: 'menuitem',
				class: 'wm-template-save',
				onclick: async () => {
					const html = editorText()
					if (!html.replace(/<[^>]*>|&nbsp;|\s/g, '') && !/<img/i.test(html)) { toast(_('Écrivez d\'abord le texte du modèle'), true); return }
					const name = (window.prompt(_('Nom du modèle'), subject.value || '') || '').trim()
					if (!name) return
					closeTemplateMenu()
					try {
						await saveTemplate({ name, html })
						toast(_('Modèle « {name} » enregistré', { name }))
					} catch (e) { toast(e.message, true) }
				},
			}, '＋ ' + _('Enregistrer ce texte comme modèle')))
			menu.appendChild(h('button', { type: 'button', role: 'menuitem', class: 'wm-template-manage', onclick: () => { closeTemplateMenu(); openAccountSettings() } }, '⚙️ ' + _('Gérer les modèles')))
			const first = menu.querySelector('button')
			if (first) first.focus()
		}
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
				persistFiles()
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
				+ (l.expires ? ' — ' + _('lien valable jusqu\'au {date}', { date: new Date(l.expires + 'T12:00:00').toLocaleDateString(locale(), { day: 'numeric', month: 'long', year: 'numeric' }) }) : '')
				+ (l.password ? ' — ' + _('protégé par un mot de passe') : '')
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
		// protection of the links made: an expiry, a password (passed on another way, never in the message)
		const linkExpiry = h('select', { name: 'linkExpiry', 'aria-label': _('Expiration des liens') },
			h('option', { value: '' }, _('Sans expiration')),
			[7, 30, 90].map((n) => h('option', { value: String(n) }, _('Expire dans {count} jours', { count: n }))))
		const linkPassword = h('input', { type: 'checkbox', name: 'linkPassword' })
		const linkOptions = h('details', { class: 'wm-link-options' },
			h('summary', {}, '🔒 ' + _('Protéger les liens')),
			linkExpiry, h('label', { class: 'wm-radio' }, linkPassword, ' ' + _('Mot de passe')))
		const expiryDate = () => {
			if (!linkExpiry.value) return ''
			const d = new Date(Date.now() + parseInt(linkExpiry.value, 10) * 86400000)
			return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0')
		}
		const showPassword = (l) => {
			const copy = () => { try { navigator.clipboard.writeText(l.password) } catch (e) { /* shown anyway */ } }
			toast(_('Mot de passe du lien « {name} » : {password} — à transmettre autrement (téléphone, Matrix…)', { name: l.name, password: l.password }), false, { label: _('Copier'), run: copy })
		}
		const shareLinks = async (paths) => {
			const links = []
			for (const path of paths) {
				try {
					links.push(await api('/share-link', { method: 'POST', body: { path, expires: expiryDate(), password: linkPassword.checked } }))
				} catch (e) {
					toast(e.message, true)
				}
			}
			if (links.length) {
				insertLinks(links)
				toast(links.length > 1 ? _('{count} liens ajoutés au message', { count: links.length }) : _('Lien ajouté au message'))
				links.filter((l) => l.password).forEach(showPassword)
			}
			return links.length === paths.length
		}
		const pickLinks = () => {
			if (!(OC.dialogs && OC.dialogs.filepicker)) { toast(_('Sélecteur de fichiers indisponible'), true); return }
			OC.dialogs.filepicker(_('Envoyer un lien Nextcloud'), (paths) => {
				shareLinks((Array.isArray(paths) ? paths : [paths]).filter(Boolean))
			}, true, null, true, OC.dialogs.FILEPICKER_TYPE_CHOOSE)
		}
		// a file of the computer: put in Files (« Pièces jointes envoyées »), then its link
		const LINK_FOLDER = _('Pièces jointes envoyées')
		const uploadAsLink = async (file, button) => {
			button.disabled = true
			button.textContent = _('Envoi dans Fichiers…')
			try {
				const base = OC.linkToRemote('dav') + '/files/' + encodeURIComponent(OC.getCurrentUser().uid) + '/' + encodeURIComponent(LINK_FOLDER)
				const headers = { requesttoken: OC.requestToken }
				await fetch(base, { method: 'MKCOL', headers }) // 405 when it exists already
				const name = file.name.replace(/[\\/]/g, '_')
				const put = await fetch(base + '/' + encodeURIComponent(name), { method: 'PUT', headers, body: file })
				if (!put.ok) throw new Error(_('Dépôt dans Fichiers impossible ({status})', { status: put.status }))
				if (await shareLinks(['/' + LINK_FOLDER + '/' + name])) {
					const dt = new DataTransfer()
					Array.from(files.files).filter((f) => f !== file).forEach((f) => dt.items.add(f))
					files.files = dt.files
					renderAttachList()
				}
			} catch (e) {
				toast(e.message, true)
				button.disabled = false
				button.textContent = '🔗 ' + _('Envoyer en lien')
			}
		}
		// Exchange (OVH) refuses messages over about 25 MB once encoded
		const HEAVY = 15 * 1024 * 1024

		// files kept in Exchange with the draft (a new message, or a draft opened from « Brouillons »);
		// a reply or a forward is created again at each save: its files are sent with it
		const keepsFiles = mode === 'new' || isDraft
		let savedAtts = isDraft ? (original.attachments || []).filter((a) => !a.isInline && !a.isItem).map((a) => ({ id: a.id, name: a.name, size: a.size })) : []
		let persisting = Promise.resolve()
		const persistFiles = () => {
			persisting = persisting.then(async () => {
				if (!keepsFiles || closed) return
				// heavy files stay here: they may still be sent as a Nextcloud link
				const local = Array.from(files.files).filter((f) => f.size < HEAVY)
				const paths = ncFiles.slice()
				if (!local.length && !paths.length) return
				if (!draftId) { dirty = true; await saveDraft() }
				if (!draftId) return
				const fd = new FormData()
				fd.append('draftId', draftId)
				local.forEach((f) => fd.append('files[]', f, f.name))
				paths.forEach((p) => fd.append('filePaths[]', p))
				draftStatus.textContent = _('Enregistrement des pièces jointes…')
				try {
					const r = await api('/accounts/' + state.accountId + '/draft/attachments', { method: 'POST', body: fd })
					savedAtts = r.attachments
					const dt = new DataTransfer()
					Array.from(files.files).filter((f) => !local.includes(f)).forEach((f) => dt.items.add(f))
					files.files = dt.files
					ncFiles.splice(0, ncFiles.length, ...ncFiles.filter((p) => !paths.includes(p)))
					draftStatus.textContent = _('Pièces jointes enregistrées dans le brouillon')
				} catch (e) {
					draftStatus.textContent = _('Pièces jointes non enregistrées : {error} (elles partiront avec le message)', { error: e.message })
				}
				renderAttachList()
			})
			return persisting
		}

		const renderAttachList = () => {
			clear(attachList)
			savedAtts.forEach((a) => attachList.appendChild(h('li', { class: 'wm-draft-att', dataset: { id: a.id } },
				'📎 ' + a.name + (a.size ? ' (' + formatSize(a.size) + ')' : '') + ' ',
				h('span', { class: 'wm-muted' }, '· ' + _('dans le brouillon') + ' '),
				h('button', {
					type: 'button',
					class: 'wm-small',
					onclick: async (e) => {
						e.currentTarget.disabled = true
						try {
							savedAtts = (await api('/accounts/' + state.accountId + '/draft/attachments/delete', { method: 'POST', body: { draftId, attachmentId: a.id } })).attachments
						} catch (err) { toast(err.message, true) }
						renderAttachList()
					},
				}, _('retirer')))))
			const local = Array.from(files.files)
			const total = local.reduce((n, f) => n + f.size, 0)
			local.forEach((f) => {
				const toLink = h('button', { type: 'button', class: 'wm-small wm-to-link', onclick: (e) => uploadAsLink(f, e.currentTarget) }, '🔗 ' + _('Envoyer en lien'))
				attachList.appendChild(h('li', { class: f.size >= HEAVY ? 'wm-heavy' : '' }, '📎 ' + f.name + ' (' + formatSize(f.size) + ') ', toLink))
			})
			ncFiles.forEach((p, i) => attachList.appendChild(h('li', {}, '☁️ ' + p + ' ',
				h('button', { type: 'button', class: 'wm-small wm-to-link', onclick: async () => { if (await shareLinks([p])) { ncFiles.splice(ncFiles.indexOf(p), 1); renderAttachList() } } }, '🔗 ' + _('en lien')),
				' ',
				h('button', { type: 'button', class: 'wm-small', onclick: () => { ncFiles.splice(i, 1); renderAttachList() } }, _('retirer')))))
			if (total >= HEAVY) {
				attachList.appendChild(h('li', { class: 'wm-warning wm-heavy-warning' },
					_('Pièces jointes lourdes ({size}) : Exchange peut refuser le message. Préférez « 🔗 Envoyer en lien » : le fichier est déposé dans vos Fichiers et le destinataire reçoit un lien de téléchargement.', { size: formatSize(total) })))
			}
		}
		files.addEventListener('change', () => { renderAttachList(); persistFiles() })
		if (prefill && prefill.files && prefill.files.length) {
			// files given with the new message (messages forwarded as attachments)
			const dt = new DataTransfer()
			Array.from(files.files).concat(prefill.files).forEach((f) => dt.items.add(f))
			files.files = dt.files
			persistFiles()
		}
		renderAttachList()

		const pickFromFiles = () => {
			if (!(OC.dialogs && OC.dialogs.filepicker)) { toast(_('Sélecteur de fichiers indisponible'), true); return }
			OC.dialogs.filepicker(_('Joindre depuis Fichiers'), (paths) => {
				(Array.isArray(paths) ? paths : [paths]).forEach((p) => { if (p && !ncFiles.includes(p)) ncFiles.push(p) })
				renderAttachList()
				persistFiles()
			}, true, null, true, OC.dialogs.FILEPICKER_TYPE_CHOOSE)
		}

		// ---- drafts: saved a few seconds after the last change, and when leaving
		const draftStatus = h('span', { class: 'wm-draft-status wm-muted', 'aria-live': 'polite' })
		const deleteDraftBtn = h('button', { type: 'button', class: 'wm-danger', hidden: !draftId, onclick: () => discardDraft() }, '🗑 ' + _('Supprimer le brouillon'))
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
				draftStatus.textContent = _('Enregistrement du brouillon…')
				try {
					const r = await api('/accounts/' + state.accountId + '/draft', { method: 'POST', body: fields() })
					draftId = r.draftId
					deleteDraftBtn.hidden = false
					draftStatus.textContent = _('Brouillon enregistré à {time}', { time: new Date().toLocaleTimeString(locale(), { hour: '2-digit', minute: '2-digit' }) })
					const drafts = state.folders.find((f) => f.role === 'drafts')
					if (drafts) listCache.delete(state.accountId + '|' + drafts.id)
				} catch (err) {
					dirty = true
					draftStatus.textContent = _('Brouillon non enregistré : {error}', { error: err.message })
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
			// while the draft was being saved, another message or a new one was opened: leave it
			if (!form.isConnected) return
			if (state.current && !isDraft) renderMessage(); else renderEmptyDetail()
			setView(state.current && !isDraft ? 'detail' : 'list')
		}
		const cancel = async () => {
			if (dirty || saving) {
				await saveDraft()
				if (draftId && !dirty) toast(_('Brouillon enregistré dans « Brouillons »'))
			}
			leave()
		}
		const discardDraft = async () => {
			if (!window.confirm(_('Supprimer ce brouillon ?'))) return
			closed = true
			clearTimeout(draftTimer)
			await saving
			try {
				if (draftId) await api('/accounts/' + state.accountId + '/draft/delete', { method: 'POST', body: { draftId } })
				toast(_('Brouillon supprimé'))
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
			sendBtn.textContent = later.checked ? _('Programmer l\'envoi') : _('Envoyer')
		})
		// « Me rappeler si pas de réponse »: the bell of Nextcloud says so after the days chosen
		const followUp = h('input', { type: 'checkbox', name: 'followUp' })
		const followDays = h('select', { name: 'followUpDays', 'aria-label': _('Délai de réponse') },
			[1, 2, 3, 5, 7, 14].map((n) => h('option', { value: String(n), selected: n === 3 }, n > 1 ? _('{count} jours', { count: n }) : _('{count} jour', { count: n }))))
		const sendOptions = h('div', { class: 'wm-send-options' },
			h('label', { class: 'wm-radio' }, followUp, ' ' + _('Me rappeler si pas de réponse sous')), followDays,
			h('label', { class: 'wm-radio' }, receipt, ' ' + _('Demander un accusé de lecture')),
			h('label', { class: 'wm-radio' }, later, ' ' + _('Envoyer plus tard')), laterAt)
		const sendBtn = h('button', { type: 'submit', class: 'primary', title: _('Envoyer (Ctrl+Entrée)') }, _('Envoyer'))
		const form = h('form', {
			class: 'wm-compose',
			onsubmit: async (e) => {
				e.preventDefault()
				// a save in progress must end first: the draft it creates is deleted after sending
				clearTimeout(draftTimer)
				await persisting
				await saving
				const fd = new FormData()
				Object.entries(fields()).forEach(([k, v]) => fd.append(k, v))
				// a new message saved with its files: the draft itself is sent
				if (mode === 'new' && draftId && savedAtts.length) fd.set('mode', 'draft')
				if (receipt.checked) fd.append('readReceipt', '1')
				if (later.checked) {
					const at = new Date(laterAt.value)
					if (!laterAt.value || isNaN(at) || at <= new Date()) { toast(_('Choisissez une date et une heure d\'envoi à venir'), true); return }
					fd.append('sendAt', at.toISOString())
				}
				Array.from(files.files).forEach((f) => fd.append('files[]', f, f.name))
				ncFiles.forEach((p) => fd.append('filePaths[]', p))
				// « ci-joint » in the text, but nothing attached: one question before it goes
				if (mode !== 'forward' && !files.files.length && !ncFiles.length && !savedAtts.length && mentionsAttachment(body)
					&& !window.confirm(_('Le message parle d\'une pièce jointe, mais aucun fichier n\'est joint. Envoyer quand même ?'))) {
					body.focus()
					return
				}
				// a few seconds to undo (not for a later delivery: it can be cancelled in « Boîte d'envoi »)
				const delay = later.checked ? 0 : undoDelay()
				if (delay) {
					closed = true
					form.remove()
					if (state.current && !isDraft) renderMessage(); else renderEmptyDetail()
					if (!await undoCountdown(delay)) {
						// back to the message, as it was
						closed = false
						clear(detailEl)
						detailEl.appendChild(form)
						setView('detail')
						body.focus()
						toast(_('Envoi annulé : le message est de nouveau ouvert'))
						return
					}
				}
				sendBtn.disabled = true
				sendBtn.textContent = _('Envoi…')
				closed = true
				try {
					const sent = await api('/accounts/' + state.accountId + '/send', { method: 'POST', body: fd })
					threadCache.clear() // the reply now belongs to its conversation
					if (followUp.checked) {
						try {
							const r = await api('/accounts/' + state.accountId + '/followups', { method: 'POST', body: {
								subject: subject.value || (original && original.subject) || '',
								to: [to.value, cc.value].concat(isResponse ? [personLabel((original.replyTo && original.replyTo[0]) || original.from)] : []).join(', '),
								days: parseInt(followDays.value, 10),
							} })
							toast(_('Sans réponse d\'ici le {date}, Nextcloud vous le rappellera', { date: new Date(r.until).toLocaleDateString(locale(), { weekday: 'long', day: 'numeric', month: 'long' }) }))
						} catch (err) { toast(err.message, true) }
					}
					rememberRecipients([to.value, cc.value, bcc.value].concat(isResponse ? [personLabel((original.replyTo && original.replyTo[0]) || original.from)] : []).join(','))
					toast(sent && sent.sendAt
						? _('Envoi programmé le {date} (dans « Boîte d\'envoi » d\'ici là)', { date: new Date(sent.sendAt).toLocaleString(locale(), { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' }) })
						: _('Message envoyé'))
					if (isDraft) { state.selectedId = null; removeFromList([draftId]) }
					// meanwhile another message may have been opened or started: leave it
					if (form.isConnected) { if (state.current && !isDraft) renderMessage(); else renderEmptyDetail() }
					if (folderRole() === 'sentitems') setTimeout(() => loadMessages(true), 1500)
				} catch (err) {
					closed = false
					toast(err.message, true)
					sendBtn.disabled = false
					sendBtn.textContent = _('Envoyer')
					// put away during the undo delay: back on screen, nothing is lost
					if (!form.isConnected) { clear(detailEl); detailEl.appendChild(form); setView('detail') }
				}
			},
		},
		h('div', { class: 'wm-compose-title' },
			h('button', { type: 'button', class: 'wm-back wm-mobile-only', onclick: cancel, 'aria-label': _('Retour') }, '←'),
			h('h2', {}, titles[mode]),
			fromField,
			draftStatus),
		isResponse
			? h('div', { class: 'wm-info' }, mode === 'reply'
				? _('Réponse à : {names}', { names: personLabel((original.replyTo && original.replyTo[0]) || original.from) })
				: _('Réponse à : {names}', { names: [original.from].concat(original.to || [], original.cc || []).filter(Boolean).map(personShort).join(', ') }))
			: h('label', { class: 'wm-field' }, h('span', {}, _('À')), attachAutocomplete(to)),
		h('label', { class: 'wm-field' }, h('span', {}, isResponse ? _('Ajouter en Cc') : 'Cc'), attachAutocomplete(cc)),
		h('label', { class: 'wm-field' }, h('span', {}, _('Cci')), attachAutocomplete(bcc)),
		h('label', { class: 'wm-field' }, h('span', {}, _('Objet')), subject),
		h('div', { class: 'wm-editor-wrap' }, editorBar, body),
		original && !isDraft ? h('div', { class: 'wm-info wm-muted' }, mode === 'forward'
			? _('Le message d\'origine et ses pièces jointes seront ajoutés automatiquement sous votre texte.')
			: _('Le message d\'origine sera cité automatiquement sous votre réponse.')) : null,
		attachList,
		sendOptions,
		h('div', { class: 'wm-compose-actions' },
			sendBtn,
			h('label', { class: 'wm-file-label button' }, '📎 ' + _('Joindre'), files),
			h('button', { type: 'button', onclick: pickFromFiles }, '☁️ ' + _('Depuis Fichiers')),
			h('button', { type: 'button', class: 'wm-link-btn', onclick: pickLinks, title: _('Un lien de téléchargement Nextcloud dans le message, au lieu d\'une pièce jointe') }, '🔗 ' + _('Lien Nextcloud')),
			linkOptions,
			importance,
			h('button', { type: 'button', onclick: cancel, title: _('Fermer (le brouillon est gardé)') }, _('Annuler')),
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
		const box = h('div', { class: 'wm-shared-editor' }, h('span', { class: 'wm-shared-editor-title' }, '📮 ' + _('Boîtes partagées')))
		const list = h('ul', { class: 'wm-shared-list' })
		const email = h('input', { type: 'email', name: 'sharedMailbox', placeholder: _('contact@exemple.fr') })
		const err = h('div', { class: 'wm-form-error' })
		const add = h('button', { type: 'button', class: 'wm-small' }, _('Ajouter'))
		const show = (mailboxes) => {
			clear(list)
			mailboxes.forEach((m) => list.appendChild(h('li', {}, m.name !== m.email ? m.name + ' <' + m.email + '>' : m.email, ' ',
				m.delegate ? h('span', { class: 'wm-muted wm-delegate-tag', title: _('Vous êtes délégué : seuls les dossiers qu\'on vous a partagés sont affichés') }, _('(délégué)') + ' ') : null,
				h('button', {
					type: 'button',
					class: 'wm-small wm-icon-btn',
					'aria-label': _('Retirer {name}', { name: m.email }),
					title: _('Retirer cette boîte du webmail (rien n\'est supprimé dans Exchange)'),
					onclick: async () => {
						try {
							show((await api('/accounts/' + a.id + '/mailboxes/delete', { method: 'POST', body: { email: m.email } })).mailboxes)
							if (a.id === state.accountId) loadShared(a.id)
						} catch (e) { toast(e.message, true) }
					},
				}, '✕'))))
			if (!mailboxes.length) list.appendChild(h('li', { class: 'wm-muted' }, _('Aucune : ajoutez une boîte commune (contact@, accueil@…) dont l\'administrateur Exchange vous a donné l\'accès, ou celle d\'un collègue qui vous a fait délégué de sa boîte de réception dans Outlook.')))
		}
		add.addEventListener('click', async () => {
			err.textContent = ''
			if (!email.value.trim()) return
			add.disabled = true
			try {
				show((await api('/accounts/' + a.id + '/mailboxes', { method: 'POST', body: { email: email.value.trim() } })).mailboxes)
				toast(_('Boîte partagée ajoutée : ses dossiers sont sous les vôtres'))
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

	// ------------------------------------------------------------------
	// Matrix (Element): « Envoyer sur Matrix » with the user's own Matrix account
	// ------------------------------------------------------------------

	const matrix = { settings: null, rooms: null }

	async function matrixSettingsLoad(force) {
		if (!matrix.settings || force) matrix.settings = await api('/matrix')
		return matrix.settings
	}

	/** The card of « Comptes et signature »: homeserver, access token (kept encrypted), default room. */
	function matrixSettings() {
		const card = h('fieldset', { class: 'wm-account-card wm-matrix-settings' }, h('legend', {}, '💬 ' + _('Matrix (Element)')))
		// (text: « matrix.example.org » alone is accepted, https is added by the server)
		const homeserver = h('input', { type: 'text', inputmode: 'url', autocapitalize: 'off', spellcheck: 'false', name: 'matrixHomeserver', placeholder: 'https://matrix.example.org' })
		const token = h('input', { type: 'password', name: 'matrixToken', autocomplete: 'off' })
		const room = h('input', { type: 'text', name: 'matrixRoom', placeholder: '#equipe:example.org', list: 'wm-matrix-rooms' })
		const roomList = h('datalist', { id: 'wm-matrix-rooms' })
		const status = h('p', { class: 'wm-muted wm-matrix-status' })
		const forget = h('button', { type: 'button', class: 'wm-small wm-danger', hidden: true }, _('Déconnecter'))
		// (the saved values arriving after the person started typing do not replace what they typed)
		const typed = new Set()
		// alerts of new messages in the default room (sent by Nextcloud, even with no webmail open)
		const alerts = h('select', {
			name: 'matrixAlerts',
			onchange: async () => {
				typed.add(alerts)
				try {
					matrix.settings = await api('/matrix/alerts', { method: 'POST', body: { alerts: alerts.value } })
					toast(alerts.value === 'off' ? _('Plus d\'alertes sur Matrix') : _('Les nouveaux messages seront signalés dans votre salon Matrix'))
				} catch (ex) {
					toast(ex.message, true)
					alerts.value = (matrix.settings && matrix.settings.alerts) || 'off'
				}
			},
		},
		h('option', { value: 'off' }, _('Aucune')),
		h('option', { value: 'important' }, _('Messages importants seulement')),
		h('option', { value: 'all' }, _('Tous les nouveaux messages')))
		const alertsRow = h('label', { class: 'wm-field', hidden: true }, h('span', {}, _('Alertes des nouveaux messages')), alerts)
		;[homeserver, token, room].forEach((i) => i.addEventListener('input', () => typed.add(i)))
		const show = (st, initial) => {
			if (!initial || !typed.has(alerts)) alerts.value = st.alerts || 'off'
			alertsRow.hidden = !st.hasToken
			const set = (i, v) => { if (!initial || !typed.has(i)) i.value = v }
			set(homeserver, st.homeserver || '')
			set(room, st.room || '')
			set(token, '')
			if (!initial) typed.clear()
			token.placeholder = st.hasToken ? _('Enregistré (laisser vide pour le garder)') : _('Jeton d\'accès')
			forget.hidden = !st.hasToken
			status.textContent = st.user ? _('Connecté en tant que {user}', { user: st.user }) : (st.hasToken ? _('Compte Matrix enregistré') : '')
		}
		const fillRooms = async () => {
			try {
				matrix.rooms = (await api('/matrix/rooms')).rooms
				clear(roomList)
				matrix.rooms.forEach((r) => roomList.appendChild(h('option', { value: r.id }, r.name)))
			} catch (e) { /* the list is a help only */ }
		}
		const save = h('button', { type: 'submit', class: 'primary' }, _('Connecter'))
		const form = h('form', {
			class: 'wm-matrix-form',
			onsubmit: async (e) => {
				e.preventDefault()
				save.disabled = true
				try {
					const st = await api('/matrix', { method: 'POST', body: { homeserver: homeserver.value, token: token.value, room: room.value } })
					matrix.settings = st
					show(st)
					toast(_('Compte Matrix enregistré'))
					fillRooms()
				} catch (ex) { toast(ex.message, true) } finally { save.disabled = false }
			},
		},
		h('label', { class: 'wm-field' }, h('span', {}, _('Serveur')), homeserver),
		h('label', { class: 'wm-field' }, h('span', {}, _('Jeton d\'accès')), token),
		h('label', { class: 'wm-field' }, h('span', {}, _('Salon par défaut')), room, roomList),
		alertsRow,
		h('p', { class: 'wm-muted' }, _('Le jeton se trouve dans Element : Paramètres › Aide et à propos › Avancé › Jeton d\'accès. Il est gardé chiffré et ne sert qu\'à poster ce que vous envoyez sur Matrix.')),
		status,
		h('div', { class: 'wm-compose-actions' }, save, forget))
		forget.onclick = async () => {
			try {
				show(matrix.settings = await api('/matrix/forget', { method: 'POST' }))
				matrix.rooms = null
				toast(_('Compte Matrix déconnecté'))
			} catch (ex) { toast(ex.message, true) }
		}
		card.appendChild(form)
		matrixSettingsLoad(true).then((st) => { show(st, true); if (st.hasToken) fillRooms() }, () => {})
		return card
	}

	/** The text of a message, for Matrix: subject, sender, date, the beginning of the text (its paragraphs kept). */
	function mailSummaryForMatrix(m) {
		const doc = new DOMParser().parseFromString(m.body || '', 'text/html')
		doc.querySelectorAll('script, style, title').forEach((e) => e.remove())
		doc.querySelectorAll('br').forEach((e) => e.replaceWith('\n'))
		doc.querySelectorAll('p, div, li, tr, h1, h2, h3, h4, h5, h6, blockquote, pre, table').forEach((e) => e.append('\n'))
		const text = (doc.body.textContent || '').replace(/[ \t\u00a0]+/g, ' ').replace(/ ?\n ?/g, '\n').replace(/\n{3,}/g, '\n\n').trim()
		const from = m.from ? (m.from.name && m.from.name !== m.from.email ? m.from.name + ' <' + m.from.email + '>' : m.from.email) : ''
		const date = m.date ? new Date(m.date).toLocaleString(locale(), { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : ''
		// (the address put in after the translation: it would take « <address> » for an HTML tag and drop it)
		return '✉️ ' + (m.subject || _('(sans objet)')) + '\n'
			+ _('De : {from}').replace('{from}', from) + (date ? ' · ' + date : '') + '\n\n'
			+ (text.length > 600 ? text.slice(0, 600).trim() + '…' : text)
	}

	/**
	 * « Envoyer sur Matrix »: the text (to change if need be) and the room, then posted.
	 * Also used by the calendar (an event).
	 */
	async function sendToMatrix(text) {
		let st
		try { st = await matrixSettingsLoad() } catch (e) { toast(e.message, true); return }
		const overlay = h('div', { class: 'wm-overlay' })
		const close = () => { overlay.remove(); document.removeEventListener('keydown', onKey) }
		const onKey = (e) => { if (e.key === 'Escape') close() }
		document.addEventListener('keydown', onKey)
		overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) close() })
		const body = h('div', { class: 'wm-modal-body' })
		overlay.appendChild(h('div', { class: 'wm-modal wm-matrix-send', role: 'dialog', 'aria-modal': 'true', 'aria-label': _('Envoyer sur Matrix') },
			h('div', { class: 'wm-modal-header' }, h('h2', {}, '💬 ' + _('Envoyer sur Matrix')), h('button', { class: 'wm-modal-close', 'aria-label': _('Fermer'), onclick: close }, '✕')),
			body))
		document.body.appendChild(overlay)
		if (!st.hasToken) {
			body.appendChild(h('p', {}, _('Connectez d\'abord votre compte Matrix : serveur et jeton d\'accès d\'Element.')))
			body.appendChild(h('button', { class: 'primary', onclick: () => { close(); openAccountSettings() } }, _('Connecter mon compte Matrix')))
			return
		}
		const roomSel = h('select', { name: 'matrixSendRoom', 'aria-label': _('Salon') }, h('option', { value: st.room || '' }, st.room || _('Choisissez un salon')))
		const textarea = h('textarea', { name: 'matrixText', rows: '9' })
		textarea.value = text
		const send = h('button', { type: 'submit', class: 'primary' }, _('Envoyer'))
		body.appendChild(h('form', {
			onsubmit: async (e) => {
				e.preventDefault()
				send.disabled = true
				try {
					await api('/matrix/send', { method: 'POST', body: { room: roomSel.value, text: textarea.value } })
					close()
					const r = (matrix.rooms || []).find((x) => x.id === roomSel.value)
					toast(_('Envoyé sur Matrix dans {room}', { room: r ? r.name : roomSel.value }))
				} catch (ex) { toast(ex.message, true); send.disabled = false }
			},
		},
		h('label', { class: 'wm-field' }, h('span', {}, _('Salon')), roomSel),
		h('label', { class: 'wm-field wm-field-top' }, h('span', {}, _('Message')), textarea),
		h('div', { class: 'wm-compose-actions' }, send, h('button', { type: 'button', onclick: close }, _('Annuler')))))
		textarea.focus()
		// the joined rooms, the default one chosen
		try {
			if (!matrix.rooms) matrix.rooms = (await api('/matrix/rooms')).rooms
			clear(roomSel)
			const known = matrix.rooms.some((r) => r.id === st.room)
			if (st.room && !known) roomSel.appendChild(h('option', { value: st.room }, st.room))
			matrix.rooms.forEach((r) => roomSel.appendChild(h('option', { value: r.id, selected: r.id === st.room }, r.name)))
		} catch (e) { /* the default room only */ }
	}

	function openAccountSettings() {
		setView('detail')
		clear(detailEl)
		const wrap = h('div', { class: 'wm-settings' },
			h('button', { class: 'wm-back wm-mobile-only', onclick: () => setView('list'), 'aria-label': _('Retour') }, '←'),
			h('h2', {}, _('Comptes Exchange')))
		// this browser's settings
		const undo = h('select', { name: 'undoSend' }, UNDO_DELAYS.map((n) => h('option', { value: String(n), selected: n === undoDelay() },
			n ? _('{seconds} secondes', { seconds: n }) : _('Aucun (envoi immédiat)'))))
		undo.addEventListener('change', () => {
			try { localStorage.setItem('webmail.undoSend', undo.value) } catch (e) { /* not kept */ }
			toast(_('Réglage enregistré pour ce navigateur'))
		})
		// the browser's mail program: mailto: links of every site open a new message here
		const mailtoBtn = typeof navigator.registerProtocolHandler === 'function' ? h('button', {
			type: 'button',
			class: 'wm-mailto-register',
			onclick: () => {
				try {
					navigator.registerProtocolHandler('mailto', window.location.origin + OC.generateUrl('/apps/webmail/') + '?mailto=%s')
					toast(_('Le navigateur vous demande de confirmer'))
				} catch (e) { toast(_('Ce navigateur refuse : {error}', { error: e.message }), true) }
			},
		}, '✉️ ' + _('Ouvrir les liens « mailto: » avec ce webmail')) : null
		const densitySelect = h('select', {
			name: 'density',
			onchange: () => {
				try { localStorage.setItem('webmail.density', densitySelect.value) } catch (e) { /* not kept */ }
				applyDensity()
				toast(_('Affichage enregistré'))
			},
		}, DENSITIES.map(([v, label]) => h('option', { value: v, selected: v === density() }, label())))
		wrap.appendChild(h('fieldset', { class: 'wm-account-card wm-display-settings' },
			h('legend', {}, _('Affichage')),
			h('label', { class: 'wm-field' }, h('span', {}, _('Liste des messages')), densitySelect),
			h('p', { class: 'wm-muted' }, _('Plus compact, plus de messages tiennent à l\'écran.'))))
		wrap.appendChild(h('fieldset', { class: 'wm-account-card wm-send-settings' },
			h('legend', {}, _('Envoi')),
			h('label', { class: 'wm-field' }, h('span', {}, _('Délai pour annuler l\'envoi')), undo),
			h('p', { class: 'wm-muted' }, _('Pendant ce délai, « Annuler » rouvre le message au lieu de l\'envoyer.')),
			mailtoBtn,
			mailtoBtn ? h('p', { class: 'wm-muted' }, _('Les adresses e-mail cliquées sur les autres sites ouvriront un nouveau message ici (le navigateur demande confirmation). Dans les messages reçus, c\'est déjà le cas.')) : null))

		// reply templates: renamed, removed (they are written from a message, button 📄)
		const templateList = h('ul', { class: 'wm-template-list' })
		const showTemplates = (list) => {
			clear(templateList)
			if (!list.length) templateList.appendChild(h('li', { class: 'wm-muted' }, _('Aucun modèle. Dans un message, le bouton 📄 enregistre le texte écrit comme modèle.')))
			list.forEach((t) => {
				// its text, as an excerpt (an inert document: nothing in it runs or loads)
				const preview = (new DOMParser().parseFromString(t.html, 'text/html').body.textContent || '').replace(/\s+/g, ' ').trim()
				templateList.appendChild(h('li', { dataset: { id: t.id } },
					h('strong', {}, t.name),
					h('span', { class: 'wm-muted wm-template-preview' }, preview.slice(0, 80) + (preview.length > 80 ? '…' : '')),
					h('button', { type: 'button', class: 'wm-small', onclick: async () => {
						const name = (window.prompt(_('Nouveau nom du modèle'), t.name) || '').trim()
						if (!name || name === t.name) return
						try { showTemplates(await saveTemplate({ id: t.id, name, html: t.html })) } catch (e) { toast(e.message, true) }
					} }, _('Renommer')),
					h('button', { type: 'button', class: 'wm-small wm-danger', onclick: async () => {
						if (!window.confirm(_('Supprimer le modèle « {name} » ?', { name: t.name }))) return
						try { showTemplates(await deleteTemplate(t.id)); toast(_('Modèle supprimé')) } catch (e) { toast(e.message, true) }
					} }, _('Supprimer'))))
			})
		}
		wrap.appendChild(h('fieldset', { class: 'wm-account-card wm-template-settings' },
			h('legend', {}, _('Modèles de réponse')), templateList))
		loadTemplates(true).then(showTemplates, (e) => toast(e.message, true))
		// (last: the account cards stay first)
		wrap.appendChild(matrixSettings())

		// senders whose remote images show at once
		const trustedList = h('ul', { class: 'wm-trusted-list' })
		const showTrusted = (list) => {
			clear(trustedList)
			if (!list.length) trustedList.appendChild(h('li', { class: 'wm-muted' }, _('Aucun. Sur un message aux images bloquées, « Toujours pour… » ajoute son expéditeur (ou son domaine).')))
			list.forEach((entry) => trustedList.appendChild(h('li', { dataset: { entry } },
				h('span', {}, entry.startsWith('@') ? _('Tout le domaine {domain}', { domain: entry.slice(1) }) : entry),
				h('button', { type: 'button', class: 'wm-small wm-danger', 'aria-label': _('Retirer {who}', { who: entry }), onclick: async () => {
					try {
						showTrusted((await api('/trusted-images/remove', { method: 'POST', body: { entry } })).trusted)
						msgCache.clear()
					} catch (e) { toast(e.message, true) }
				} }, '✕'))))
		}
		wrap.appendChild(h('fieldset', { class: 'wm-account-card wm-trusted-settings' },
			h('legend', {}, _('Images des expéditeurs de confiance')),
			h('p', { class: 'wm-muted' }, _('Leurs messages affichent leurs images distantes directement ; pour les autres, elles restent bloquées (elles peuvent servir à savoir que vous avez lu le message).')),
			trustedList))
		api('/trusted-images').then((r) => showTrusted(r.trusted), (e) => toast(e.message, true))

		state.accounts.forEach((a) => {
			const name = h('input', { type: 'text', value: a.displayName === a.email ? '' : a.displayName, placeholder: _('Nom affiché (facultatif)') })
			const sig = signatureEditor(a.signature || '')
			const pwd = h('input', { type: 'password', placeholder: _('Nouveau mot de passe (si changé)'), autocomplete: 'new-password' })
			const save = h('button', { class: 'primary' }, _('Enregistrer'))
			save.addEventListener('click', async () => {
				save.disabled = true
				try {
					const updated = await api('/accounts/' + a.id, { method: 'PUT', body: { displayName: name.value, signature: sig.value(), password: pwd.value || null } })
					Object.assign(a, updated)
					pwd.value = ''
					toast(_('Compte mis à jour'))
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
						toast(bell.checked ? _('Les nouveaux messages seront signalés dans Nextcloud') : _('Les nouveaux messages ne seront plus signalés dans Nextcloud'))
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
						toast(remind.checked ? _('Les rappels des rendez-vous arriveront dans Nextcloud') : _('Les rappels des rendez-vous n\'arriveront plus dans Nextcloud'))
					} catch (e) {
						remind.checked = !remind.checked
						toast(e.message, true)
					}
				},
			})
			const del = h('button', { class: 'wm-danger' }, _('Retirer ce compte'))
			del.addEventListener('click', async () => {
				if (!window.confirm(_('Retirer le compte {email} du webmail ? (aucun e-mail n\'est supprimé)', { email: a.email }))) return
				try {
					await api('/accounts/' + a.id, { method: 'DELETE' })
					forgetAccount(a.id)
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
				h('label', { class: 'wm-field' }, h('span', {}, _('Nom')), name),
				h('label', { class: 'wm-field' }, h('span', {}, _('Mot de passe')), pwd),
				h('div', { class: 'wm-field wm-field-top' }, h('span', {}, _('Signature')), sig.el),
				sharedMailboxesEditor(a),
				h('label', { class: 'wm-radio wm-bell' }, bell, ' 🔔 ' + _('Signaler chaque nouveau message dans les notifications Nextcloud, même webmail fermé (et sur le téléphone avec l\'application Nextcloud)')),
				h('label', { class: 'wm-radio wm-bell' }, remind, ' ⏰ ' + _('Rappels des rendez-vous de l\'agenda dans les notifications Nextcloud (au moment choisi pour chaque rendez-vous : 15 minutes avant…)')),
				h('div', { class: 'wm-compose-actions' }, save,
					h('button', { type: 'button', class: 'wm-oof-btn', onclick: () => openOof(a) }, '🌴 ' + _('Réponse automatique')),
					h('button', { type: 'button', class: 'wm-autofile-btn', title: _('Les pièces jointes de certains expéditeurs rangées d\'elles-mêmes dans un dossier de Fichiers'), onclick: () => openAutoFile(a) }, '📂 ' + _('Pièces jointes → Fichiers')),
					h('button', { type: 'button', class: 'wm-rules-btn', onclick: () => openRules(a) }, '📋 ' + _('Règles')),
					h('button', {
						type: 'button',
						class: 'wm-delegates-btn',
						onclick: () => (window.WebmailDelegates ? window.WebmailDelegates.open(core, a) : toast(_('Module indisponible'), true)),
					}, '👥 ' + _('Délégués')),
					del)))
		})

		wrap.appendChild(buildAddAccountForm())
		detailEl.appendChild(wrap)
	}

	function buildAddAccountForm(firstRun) {
		const email = h('input', { type: 'email', required: true, placeholder: _('prenom.nom@{domain}', { domain: state.domains[0] || _('exemple.fr') }), autocomplete: 'email' })
		const pwd = h('input', { type: 'password', required: true, autocomplete: 'current-password' })
		const name = h('input', { type: 'text', placeholder: _('Facultatif') })
		const btn = h('button', { type: 'submit', class: 'primary' }, _('Connecter'))
		const err = h('div', { class: 'wm-form-error', role: 'alert' })

		const domainsHint = state.domains.length || state.autodiscover
			? h('p', { class: 'wm-muted wm-domains-hint' }, (state.domains.length ? _('Domaines pris en charge : {domains}.', { domains: state.domains.join(', ') }) + ' ' : '')
				+ (state.autodiscover ? (state.domains.length ? _('Pour les autres, le serveur est cherché automatiquement.') : _('Le serveur de votre adresse est cherché automatiquement.')) : ''))
			: h('p', { class: 'wm-warning' }, _('Aucun serveur Exchange n\'est encore configuré.') + ' ',
				state.isAdmin
					? h('a', { href: OC.generateUrl('/settings/admin/webmail') }, _('Configurer les serveurs (administration)'))
					: _('Contactez votre administrateur.'))

		return h('form', {
			class: 'wm-account-card wm-add-account',
			onsubmit: async (e) => {
				e.preventDefault()
				err.textContent = ''
				btn.disabled = true
				btn.textContent = _('Connexion à Exchange…')
				try {
					const acc = await api('/accounts', { method: 'POST', body: { email: email.value, password: pwd.value, displayName: name.value } })
					state.accounts.push(acc)
					toast(_('Compte ajouté'))
					if (!navEl || !root.contains(navEl)) buildLayout()
					await selectAccount(acc.id)
					startBackground()
					// unless another account or a message was opened meanwhile
					if (state.accountId === acc.id && !state.selectedId && state.module === 'mail') {
						renderEmptyDetail()
						setView('list')
					}
				} catch (ex) {
					err.textContent = ex.message
				} finally {
					btn.disabled = false
					btn.textContent = _('Connecter')
				}
			},
		},
		h('h3', {}, firstRun ? _('Connectez votre boîte Exchange') : _('Ajouter un compte')),
		domainsHint,
		h('label', { class: 'wm-field' }, h('span', {}, _('Adresse e-mail')), email),
		h('label', { class: 'wm-field' }, h('span', {}, _('Mot de passe')), pwd),
		h('label', { class: 'wm-field' }, h('span', {}, _('Nom affiché')), name),
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
		sendToMatrix,
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

	// ------------------------------------------------------------------
	// dialogs and the keyboard (all modules): Tab stays in the open dialog, and
	// when it closes, the focus goes back to what opened it
	// ------------------------------------------------------------------

	const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"]), [contenteditable="true"]'
	/** the dialog in front, if any */
	const openDialog = () => { const all = document.querySelectorAll('.wm-overlay'); return all.length ? all[all.length - 1] : null }
	const visibleIn = (el) => [...el.querySelectorAll(FOCUSABLE)].filter((x) => x.getClientRects().length > 0)

	function keepFocusInDialogs() {
		let opener = null // last element focused outside the dialogs
		document.addEventListener('focusin', (e) => { if (!e.target.closest('.wm-overlay')) opener = e.target }, true)
		document.addEventListener('keydown', (e) => {
			const dialog = e.key === 'Tab' ? openDialog() : null
			if (!dialog) return
			const items = visibleIn(dialog)
			if (!items.length) { e.preventDefault(); return }
			const first = items[0]
			const last = items[items.length - 1]
			const inside = dialog.contains(document.activeElement)
			if (e.shiftKey && (document.activeElement === first || !inside)) { e.preventDefault(); last.focus() }
			else if (!e.shiftKey && (document.activeElement === last || !inside)) { e.preventDefault(); first.focus() }
		}, true)
		new MutationObserver((changes) => {
			changes.forEach((c) => {
				c.addedNodes.forEach((n) => {
					// a dialog that does not focus anything itself: its first field or button
					if (n.nodeType === 1 && n.classList.contains('wm-overlay')) {
						setTimeout(() => { if (n.isConnected && !n.contains(document.activeElement) && visibleIn(n).length) visibleIn(n)[0].focus() }, 0)
					}
				})
				c.removedNodes.forEach((n) => {
					if (n.nodeType !== 1 || !n.classList.contains('wm-overlay') || openDialog()) return
					const lost = !document.activeElement || document.activeElement === document.body || !document.activeElement.isConnected
					if (!lost || !opener) return
					// the opener itself, or the one that replaced it when its pane was redrawn meanwhile
					const same = opener.isConnected ? opener : [...document.querySelectorAll(opener.tagName)]
						.find((x) => x.className === opener.className && x.textContent === opener.textContent && x.getClientRects().length > 0)
					if (same) same.focus()
				})
			})
		}).observe(document.body, { childList: true })
	}

	/** « Affichage de la liste »: with the preview (the default), compact (no preview), one line per message. */
	const DENSITIES = [['comfortable', () => _('Confortable (avec aperçu)')], ['compact', () => _('Compact (sans aperçu)')], ['line', () => _('Une ligne par message')]]
	function density() {
		let v = null
		try { v = localStorage.getItem('webmail.density') } catch (e) { /* default */ }
		return DENSITIES.some(([d]) => d === v) ? v : 'comfortable'
	}
	function applyDensity() {
		if (!root) return
		DENSITIES.forEach(([d]) => root.classList.toggle('wm-density-' + d, d === density()))
	}

	async function init() {
		root = document.getElementById('webmail-app')
		if (!root) return
		keepFocusInDialogs()
		try { state.domains = JSON.parse(root.dataset.domains || '[]') } catch (e) { state.domains = [] }
		state.autodiscover = root.dataset.autodiscover === '1'
		state.isAdmin = root.dataset.isAdmin === '1'
		restoreTab()
		window.addEventListener('pagehide', writeTab)
		applyDensity()

		try {
			// given with the page (no first request); asked for if not
			let given = null
			try { given = window.OCP && OCP.InitialState ? OCP.InitialState.loadState('webmail', 'accounts', null) : null } catch (e) { given = null }
			state.accounts = Array.isArray(given) ? given : await api('/accounts')
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
		else if (link.get('message') || link.get('mailto') || link.get('file')) state.module = 'mail'
		const linkMessage = link.get('message')
		const linkMailto = link.get('mailto')
		const linkEvent = link.get('event')
		const linkTask = link.get('task')
		// ?file=/Documents/devis.pdf: « Envoyer par e-mail » in the Files app
		const linkFiles = link.getAll('file').filter((p) => p.startsWith('/') && !p.split('/').includes('..')).slice(0, 20)
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
		// ?mailto=…: a mailto: link of another site, the webmail being the browser's mail program
		if (linkMailto && state.module === 'mail') openCompose('new', null, parseMailto(linkMailto))
		if (linkFiles.length && state.module === 'mail') {
			const names = linkFiles.map((p) => p.split('/').pop())
			openCompose('new', null, { ncFiles: linkFiles, subject: names.length === 1 ? names[0] : '' })
		}
		if (linkEvent && state.module === 'calendar' && window.WebmailModules.calendar.openLinked) window.WebmailModules.calendar.openLinked(core, linkEvent)
		if (linkTask && state.module === 'tasks' && window.WebmailModules.tasks.openLinked) window.WebmailModules.tasks.openLinked(core, linkTask)
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
		document.addEventListener('keydown', onPaletteKey)
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init)
	} else {
		init()
	}
})()
