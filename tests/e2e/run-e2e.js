/*
 * End-to-end test of the webmail app in a real Nextcloud, against fake_ews.py.
 *
 *   NC_URL=http://localhost:8080 NC_USER=admin NC_PASS=... SHOTS=/tmp/shots node tests/e2e/run-e2e.js
 *
 * OCC: how to run occ in that Nextcloud (to run the background job of the
 * new message notifications), by default "docker exec -u www-data nc php occ".
 *
 * The Nextcloud must have the app enabled, verify_tls=no and the domain
 * societe-a.fr mapped to the fake server (see tests/e2e/README.md).
 */
const { chromium } = require('playwright')
const assert = require('assert')
const fs = require('fs')
const path = require('path')
const { execSync } = require('child_process')

const NC = process.env.NC_URL || 'http://localhost:8080'
const SHOTS = process.env.SHOTS || '/tmp/webmail-shots'
const OCC = process.env.OCC || 'docker exec -u www-data nc php occ'
fs.mkdirSync(SHOTS, { recursive: true })

async function main() {
	const browser = await chromium.launch()
	const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR', timezoneId: 'Europe/Paris' })
	const page = await context.newPage()
	/** every list request of the run: [offset, limit] */
	const listRequests = []
	page.on('request', (r) => {
		const u = new URL(r.url())
		if (/\/apps\/webmail\/api\/accounts\/\d+\/(messages|conversations)$/.test(u.pathname)) listRequests.push([Number(u.searchParams.get('offset')), Number(u.searchParams.get('limit'))])
	})
	const errors = []
	page.on('pageerror', (e) => errors.push('pageerror: ' + e.message))
	// with the address of the resource, to tell the errors of other Nextcloud apps from ours
	page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text() + (m.location().url ? ' @ ' + m.location().url : '')) })

	const step = async (name, fn) => {
		process.stdout.write('• ' + name + ' … ')
		try {
			await fn()
		} catch (e) {
			// what the page looked like, to understand the failure
			await page.screenshot({ path: path.join(SHOTS, 'FAILED.png') }).catch(() => {})
			throw e
		}
		console.log('ok')
	}
	const shot = (name) => page.screenshot({ path: path.join(SHOTS, name + '.png') })
	/**
	 * Send the message being written and wait until Exchange has it. Not just the
	 * "Message envoyé" toast: the one of a message sent a moment before may still
	 * be shown, and a check for new messages started then can run before this one
	 * is delivered (then only announced at the next check, a minute later).
	 */
	const submitCompose = async () => {
		const [sent] = await Promise.all([
			page.waitForResponse((r) => r.url().endsWith('/send') && r.request().method() === 'POST'),
			page.click('.wm-compose button[type=submit]'),
		])
		assert(sent.ok(), 'send: HTTP ' + sent.status() + ' ' + await sent.text())
		await page.waitForSelector('.wm-toast:has-text("Message envoyé")')
	}

	await step('login', async () => {
		await page.goto(NC + '/login')
		await page.fill('#user', process.env.NC_USER || 'admin')
		await page.fill('#password', process.env.NC_PASS || 'admin-Pass-123')
		await page.click('button[type=submit]')
		await page.waitForURL(/apps/)
		// messages are sent at once in the other steps: the undo delay has its own step
		await page.evaluate(() => localStorage.setItem('webmail.undoSend', '0'))
	})

	await step('admin settings page lists both servers', async () => {
		await page.goto(NC + '/settings/admin/webmail')
		await page.waitForSelector('#webmail-admin')
		const domains = await page.$$eval('.wm-domain', (els) => els.map((e) => e.value))
		assert.deepStrictEqual(domains, ['societe-a.fr', 'societe-b.fr'])
		await page.click('#webmail-save')
		await page.waitForSelector('#webmail-admin-msg.success')
		await shot('01-admin')
	})

	await step('first run: unknown domain is rejected', async () => {
		await page.goto(NC + '/apps/webmail/')
		await page.waitForSelector('.wm-add-account')
		await shot('02-first-run')
		await page.fill('.wm-add-account input[type=email]', 'someone@inconnu.fr')
		await page.fill('.wm-add-account input[type=password]', 'secret')
		await page.click('.wm-add-account button[type=submit]')
		await page.waitForFunction(() => document.querySelector('.wm-form-error').textContent.includes('Aucun serveur'))
	})

	await step('first run: wrong password is rejected', async () => {
		await page.fill('.wm-add-account input[type=email]', 'user@societe-a.fr')
		await page.fill('.wm-add-account input[type=password]', 'wrong')
		await page.click('.wm-add-account button[type=submit]')
		await page.waitForFunction(() => document.querySelector('.wm-form-error').textContent.includes('mot de passe'))
	})

	await step('first run: account is added and inbox loads', async () => {
		await page.fill('.wm-add-account input[type=password]', 'secret')
		await page.click('.wm-add-account button[type=submit]')
		await page.waitForSelector('.wm-message')
		await page.waitForSelector('.wm-folder.active')
		const folders = await page.$$eval('.wm-folder-name', (els) => els.map((e) => e.textContent))
		assert.deepStrictEqual(folders.slice(0, 6), ['Boîte de réception', 'Clients', 'Brouillons', 'Éléments envoyés', 'Courrier indésirable', 'Éléments supprimés'])
		assert(!folders.includes('Calendrier'), 'calendar folder must be hidden')
		const count = await page.$$eval('.wm-message', (els) => els.length)
		assert.strictEqual(count, 50)
		assert.strictEqual(await page.textContent('.wm-folder.active .wm-badge'), '3')
		// space used by the mailbox, against the limit set in Exchange
		await page.waitForSelector('.wm-quota-text')
		assert.strictEqual(await page.textContent('.wm-quota-text'), '1,2 Go sur 2 Go (60 %)')
		assert.strictEqual(await page.getAttribute('.wm-quota-bar', 'aria-valuenow'), '60')
		assert(await page.$('.wm-quota.wm-quota-ok'))
	})

	await step('pagination: load more', async () => {
		await page.click('text=Charger plus')
		await page.waitForFunction(() => document.querySelectorAll('.wm-message').length === 62)
	})

	await step('open message: sanitised body, inline image, attachments, remote images blocked', async () => {
		await page.click('.wm-message >> nth=0')
		await page.waitForSelector('.wm-body-frame')
		const frame = page.frameLocator('.wm-body-frame')
		await frame.locator('text=maintenance 2027').waitFor()
		const bodyHtml = await frame.locator('body').innerHTML()
		assert(!bodyHtml.includes('<script'), 'script removed')
		assert(!bodyHtml.includes('onclick'), 'handlers removed')
		assert(!bodyHtml.includes('tracker.example'), 'remote image blocked')
		assert(bodyHtml.includes('/apps/webmail/api/accounts/'), 'cid image rewritten')
		// images as they come into view (this one is in view: it loads, below)
		assert(bodyHtml.includes('loading="lazy"') && bodyHtml.includes('decoding="async"'), 'images loaded lazily')
		// (checked from the parent page: no script can run inside the sandboxed iframe)
		const imgLoaded = await page.waitForFunction(() => {
			const img = document.querySelector('.wm-body-frame').contentDocument.querySelector('img[alt=logo]')
			return img && img.complete && img.naturalWidth > 0
		}, null, { timeout: 10000 }).then(() => true, () => false)
		assert(imgLoaded, 'inline image loaded')
		const atts = await page.$$eval('.wm-attachment-name', (els) => els.map((e) => e.textContent))
		assert.deepStrictEqual(atts, ['📎 devis-2027.pdf'])
		await page.waitForSelector('.wm-remote-banner')
		// read state propagated
		assert.strictEqual(await page.textContent('.wm-folder.active .wm-badge'), '2')
		assert(!(await page.$('.wm-message.selected.unread')))
		await shot('03-message')
	})

	await step('download attachment', async () => {
		const [download] = await Promise.all([page.waitForEvent('download'), page.click('.wm-attachment-name')])
		const p = await download.path()
		assert(fs.readFileSync(p, 'utf8').startsWith('%PDF'), 'pdf content')
		assert.strictEqual(download.suggestedFilename(), 'devis-2027.pdf')
	})

	await step('show remote images', async () => {
		await page.click('.wm-remote-banner button')
		await page.frameLocator('.wm-body-frame').locator('img[src*="tracker.example"]').waitFor({ state: 'attached' })
		assert(!(await page.$('.wm-remote-banner')))
	})

	await step('save attachment to Files', async () => {
		// The file picker is a Vue dialog; fall back to the default folder if absent
		await page.evaluate(() => { OC.dialogs.filepicker = (t, cb) => cb('/Documents') })
		await page.click('.wm-attachment button:has-text("Enregistrer dans Fichiers")')
		await page.waitForSelector('.wm-toast:has-text("Enregistré dans /Documents/devis-2027.pdf")')
	})

	await step('flag and mark unread', async () => {
		await page.click('.wm-toolbar .wm-flag-btn')
		await page.waitForSelector('.wm-message.selected .wm-flag.on')
		const id = await page.getAttribute('.wm-message.selected', 'data-id')
		await page.click('.wm-toolbar button:has-text("Non lu")')
		await page.waitForSelector(`.wm-message.unread[data-id="${id}"]`)
	})

	await step('attachment previews and save all', async () => {
		await page.click('.wm-folder:has-text("Clients")')
		await page.click('.wm-message:has-text("Photos du chantier")')
		await page.waitForSelector('.wm-attachment-thumb img')
		assert.strictEqual(await page.$$eval('.wm-attachment-thumb', (els) => els.length), 2, 'thumbnails for the pictures')
		assert.strictEqual(await page.$$eval('.wm-preview-btn', (els) => els.length), 4, 'no preview for the zip')
		await page.waitForFunction(() => [...document.querySelectorAll('.wm-attachment-thumb img')].every((i) => i.complete && i.naturalWidth === 320))

		await page.click('.wm-attachment-thumb >> nth=0')
		await page.waitForSelector('.wm-preview-title:has-text("facade.png")')
		await page.waitForFunction(() => { const i = document.querySelector('.wm-preview-image'); return i && i.complete && i.naturalWidth === 320 })
		assert.strictEqual(await page.textContent('.wm-preview-names .wm-muted'), '1 / 4')
		await shot('33-attachment-preview')
		await page.keyboard.press('ArrowRight')
		await page.waitForSelector('.wm-preview-title:has-text("toiture.png")')
		await page.keyboard.press('ArrowRight')
		await page.waitForSelector('.wm-preview-text')
		// plain text is shown as text: the <b> is not interpreted
		assert((await page.textContent('.wm-preview-text')).includes('Toiture : <b>58 m²</b>'))
		assert(!(await page.$('.wm-preview-text b')))
		await page.click('.wm-preview-nav[aria-label="Pièce jointe suivante"]')
		await page.waitForSelector('.wm-preview-pdf')
		const pdf = await page.evaluate(async () => {
			const r = await fetch(document.querySelector('.wm-preview-pdf').src)
			return [r.headers.get('content-type'), r.headers.get('content-disposition').split(';')[0]]
		})
		assert.deepStrictEqual(pdf, ['application/pdf', 'inline'])
		await page.keyboard.press('ArrowRight')
		await page.waitForSelector('.wm-preview-title:has-text("facade.png")')
		await page.keyboard.press('Escape')
		await page.waitForSelector('.wm-preview', { state: 'detached' })
		assert(await page.$('.wm-message-header'), 'Escape only closed the preview')

		// all of them in one .zip, named after the subject
		const zipRes = await page.request.get(await page.$eval('.wm-zip-btn', (a) => a.href))
		assert.strictEqual(zipRes.status(), 200)
		assert.strictEqual(zipRes.headers()['content-type'].split(';')[0], 'application/zip')
		assert.ok(zipRes.headers()['content-disposition'].includes('Photos du chantier.zip'), zipRes.headers()['content-disposition'])
		const zipBytes = await zipRes.body()
		const zipFiles = {}
		// the local file headers of the .zip: « PK\x03\x04 », sizes at 18, name length at 26, extra length at 28, name at 30
		for (let i = zipBytes.indexOf('PK\x03\x04'); i >= 0; i = zipBytes.indexOf('PK\x03\x04', i + 4)) {
			const nameLen = zipBytes.readUInt16LE(i + 26)
			zipFiles[zipBytes.toString('utf8', i + 30, i + 30 + nameLen)] = zipBytes.readUInt32LE(i + 22)
		}
		assert.deepStrictEqual(Object.keys(zipFiles).sort(), ['archive.zip', 'facade.png', 'plan.pdf', 'releve.txt', 'toiture.png'])
		assert.strictEqual(zipFiles['plan.pdf'], 16, 'plan.pdf whole')

		await page.evaluate(() => { OC.dialogs.filepicker = (t, cb) => cb('/Documents') })
		await page.click('button:has-text("Tout enregistrer dans Fichiers")')
		await page.waitForSelector('.wm-toast:has-text("5 pièces jointes enregistrées dans /Documents/")')
		await page.click('.wm-folder:has-text("Boîte de réception")')
		await page.waitForSelector('.wm-message:has-text("Réunion de lundi")')
	})

	await step('search', async () => {
		await page.fill('.wm-search', 'réunion')
		await page.press('.wm-search', 'Enter')
		await page.waitForFunction(() => document.querySelectorAll('.wm-message').length === 1)
		assert.strictEqual(await page.textContent('.wm-message-subject'), 'Réunion de lundi')
		await page.click('.wm-search-info a')
		// back to the folder as it was, with the page loaded before
		await page.waitForFunction(() => document.querySelectorAll('.wm-message').length === 62)
	})

	await step('search while typing: results after a short pause, one request for the word typed, the folder back when emptied', async () => {
		const searches = []
		const onRequest = (r) => { const u = new URL(r.url()); if (/\/api\/accounts\/\d+\/(messages|conversations)$/.test(u.pathname) && u.searchParams.get('q')) searches.push(u.searchParams.get('q')) }
		page.on('request', onRequest)
		try {
			await page.click('.wm-search')
			// typed at an ordinary pace: no request for « r », « ré »… only for the word
			await page.keyboard.type('réunion', { delay: 60 })
			await page.waitForFunction(() => document.querySelectorAll('.wm-message').length === 1)
			assert.strictEqual(await page.textContent('.wm-message-subject'), 'Réunion de lundi')
			assert.deepStrictEqual(searches, ['réunion'])
			// still where one types
			assert.ok(await page.evaluate(() => document.activeElement && document.activeElement.classList.contains('wm-search')), 'focus kept')
			// one letter: not searched yet
			await page.fill('.wm-search', 'z')
			await page.waitForTimeout(800)
			assert.deepStrictEqual(searches, ['réunion'])
			// emptied: the folder again
			await page.fill('.wm-search', '')
			await page.waitForFunction(() => document.querySelectorAll('.wm-message').length === 62)
		} finally {
			page.off('request', onRequest)
		}
	})

	await step('list headings: « Aujourd\'hui », « Hier », « Cette semaine »… where the period changes', async () => {
		const periods = await page.$$eval('.wm-messages > .wm-list-period', (els) => els.map((e) => e.textContent))
		assert.strictEqual(periods[0], 'Aujourd\'hui', periods.join(' | '))
		assert.strictEqual(new Set(periods).size, periods.length, 'each period once: ' + periods.join(' | '))
		// the order of the periods, newest first; then months (« Octobre 2026 »)
		const order = ['Aujourd\'hui', 'Hier', 'Cette semaine', 'La semaine dernière', 'Ce mois-ci']
		const known = periods.filter((p) => order.includes(p)).map((p) => order.indexOf(p))
		assert.deepStrictEqual(known, known.slice().sort((a, b) => a - b), periods.join(' | '))
		periods.filter((p) => !order.includes(p)).forEach((p) => assert.match(p, /^[A-ZÀ-Ü][a-zà-ü]+ \d{4}$/))
		// not a message: keyboard and counts unchanged
		assert.strictEqual(await page.locator('.wm-message').count(), 62)
	})

	await step('search filters and search in all folders', async () => {
		const subjects = () => page.$$eval('.wm-message-subject', (els) => els.map((e) => e.textContent))
		const settle = () => page.waitForFunction(() => !document.querySelector('.wm-list .icon-loading') && document.querySelector('.wm-search-info, .wm-message'))
		await page.click('.wm-filter-toggle')
		await page.waitForSelector('.wm-filters')
		// unread only: as many as the folder's badge
		await page.click('.wm-chip[data-filter="unread"]')
		await page.waitForSelector('.wm-search-info:has-text("Messages filtrés dans Boîte de réception")')
		await settle()
		const badge = parseInt(await page.textContent('.wm-folder.active .wm-badge'), 10)
		await page.waitForFunction((n) => document.querySelectorAll('.wm-message.unread').length === n && document.querySelectorAll('.wm-message').length === n, badge)
		// and with attachments
		await page.click('.wm-chip[data-filter="attachments"]')
		await page.waitForFunction(() => document.querySelectorAll('.wm-message').length === 1)
		assert.deepStrictEqual(await subjects(), ['Devis pour la maintenance annuelle'])
		assert.strictEqual(await page.textContent('.wm-filter-toggle'), '⏷ 2')

		// all folders: attachments everywhere, with the folder of each result
		await page.click('.wm-chip[data-filter="unread"]')
		await page.check('.wm-filters input[name=allFolders]')
		await page.waitForSelector('.wm-message:has-text("Photos du chantier") .wm-message-folder:has-text("Clients")')
		await page.waitForSelector('.wm-message:has-text("Devis pour la maintenance") .wm-message-folder:has-text("Boîte de réception")')
		await shot('34-search-filters')
		// words + all folders
		await page.click('.wm-chip[data-filter="attachments"]')
		await page.fill('.wm-search', 'devis')
		await page.press('.wm-search', 'Enter')
		await page.waitForSelector('.wm-search-info:has-text("Résultats pour « devis » dans tous les dossiers (2)")')
		assert.deepStrictEqual((await subjects()).sort(), ['Commande 4521', 'Devis pour la maintenance annuelle'])
		// a result from another folder opens normally
		await page.click('.wm-message:has-text("Commande 4521")')
		await page.frameLocator('.wm-body-frame').locator('text=le devis est signé').waitFor()

		// period: the old newsletters go
		await page.fill('.wm-search', '')
		await page.press('.wm-search', 'Enter')
		await page.selectOption('.wm-filters select[name=since]', '30')
		await page.waitForSelector('.wm-search-info:has-text("Messages filtrés dans tous les dossiers")')
		await settle()
		await page.waitForFunction(() => !document.querySelector('.wm-list .icon-loading'))
		const recent = await subjects()
		assert(recent.includes('Newsletter n°1') && !recent.includes('Newsletter n°40'), recent.join('|'))

		// clear: back to the folder, filters gone
		await page.click('.wm-search-info a')
		await page.waitForFunction(() => document.querySelectorAll('.wm-message').length === 62 && !document.querySelector('.wm-filters'))
	})

	await step('fluid navigation: prefetch, keyboard, folder cache, instant delete, Back button', async () => {
		const fetched = []
		const onRequest = (r) => { if (r.url().includes('/message?')) fetched.push(new URL(r.url()).searchParams) }
		page.on('request', onRequest)
		// start with empty message caches (the lists are kept for the tab)
		await page.reload()
		await page.waitForSelector('.wm-message')
		const ids = await page.$$eval('.wm-message', (els) => els.slice(0, 3).map((e) => e.dataset.id))
		await page.click('.wm-message >> nth=0')
		await page.waitForSelector('.wm-body-frame')
		// the next message is loaded in advance, without being marked as read
		for (let i = 0; i < 50 && !fetched.some((q) => q.get('itemId') === ids[1]); i++) await page.waitForTimeout(100)
		const pre = fetched.find((q) => q.get('itemId') === ids[1])
		assert(pre && pre.get('markRead') === '0', 'next message prefetched without marking it read')
		assert(await page.$(`.wm-message.unread[data-id="${ids[1]}"]`), 'still unread until opened')
		// and the one after it (reading down the list)
		for (let i = 0; i < 50 && !fetched.some((q) => q.get('itemId') === ids[2]); i++) await page.waitForTimeout(100)
		assert(fetched.some((q) => q.get('itemId') === ids[2] && q.get('markRead') === '0'), 'second next message prefetched too')

		// j / k: next and previous message, the prefetched one opens without asking the server again
		const before = fetched.length
		await page.keyboard.press('j')
		await page.waitForSelector(`.wm-message.selected[data-id="${ids[1]}"]`)
		await page.waitForSelector('.wm-message-header h2:has-text("Réunion de lundi")')
		await page.waitForSelector(`.wm-message:not(.unread)[data-id="${ids[1]}"]`)
		assert.strictEqual(fetched.slice(before).filter((q) => q.get('itemId') === ids[1]).length, 0, 'opened from the prefetch')
		await page.keyboard.press('ArrowDown')
		await page.waitForSelector(`.wm-message.selected[data-id="${ids[2]}"]`)
		await page.keyboard.press('k')
		await page.waitForSelector(`.wm-message.selected[data-id="${ids[1]}"]`)
		// s: follow-up flag, at once
		await page.keyboard.press('s')
		await page.waitForSelector(`.wm-message.selected .wm-flag.on`)
		await page.keyboard.press('s')
		await page.waitForSelector(`.wm-message.selected .wm-flag:not(.on)`)
		// ?: the list of shortcuts
		await page.keyboard.press('?')
		await page.waitForSelector('.wm-shortcuts td:has-text("Message suivant")')
		await shot('32-shortcuts')
		await page.keyboard.press('Escape')
		await page.waitForSelector('.wm-shortcuts', { state: 'detached' })
		// r: reply, shortcuts are off while writing
		await page.keyboard.press('r')
		await page.waitForSelector('.wm-info:has-text("Réponse à : Marie Martin")')
		await page.keyboard.press('j')
		assert(await page.$('.wm-compose'), 'typing in the reply does not navigate')
		await page.click('.wm-compose button:has-text("Annuler")')
		page.off('request', onRequest)

		// a folder already seen is shown at once, scrolled where it was left
		await page.$eval('.wm-list', (el) => { el.scrollTop = 600 })
		const shown = await page.$$eval('.wm-message', (els) => els.length)
		await page.click('.wm-folder:has-text("Clients")')
		await page.waitForSelector('.wm-message:has-text("Commande 4521")')
		const instant = await page.evaluate(() => {
			document.querySelector('.wm-folder[data-folder-id]').click()
			return { count: document.querySelectorAll('.wm-message').length, spinner: !!document.querySelector('.wm-list .icon-loading'), top: document.querySelector('.wm-list').scrollTop }
		})
		assert.deepStrictEqual(instant, { count: shown, spinner: false, top: 600 }, JSON.stringify(instant))

		// delete: gone at once, the next message opens
		await page.click('.wm-message:has(.wm-message-subject:text-is("Newsletter n°3"))')
		await page.waitForSelector('.wm-message-header h2:has-text("Newsletter n°3")')
		await page.keyboard.press('Delete')
		await page.waitForSelector('.wm-message-header h2:has-text("Newsletter n°4")')
		const subjects = await page.$$eval('.wm-message-subject', (els) => els.map((e) => e.textContent))
		assert(!subjects.includes('Newsletter n°3') && subjects.includes('Newsletter n°4'), subjects.slice(0, 8).join('|'))
		await page.waitForSelector('.wm-toast:has-text("éléments supprimés")')

		// phone: Back closes the message instead of leaving the app
		await page.setViewportSize({ width: 390, height: 844 })
		await page.keyboard.press('Escape')
		await page.waitForSelector('#webmail-app[data-view="list"]')
		const url = page.url()
		await page.click('.wm-message:has(.wm-message-subject:text-is("Newsletter n°5"))')
		await page.waitForSelector('#webmail-app[data-view="detail"]')
		await page.goBack()
		await page.waitForSelector('#webmail-app[data-view="list"]')
		assert.strictEqual(page.url(), url)
		// the ← button also goes back to the list, without leaving a history entry behind
		await page.click('.wm-message:has(.wm-message-subject:text-is("Newsletter n°6"))')
		await page.waitForSelector('#webmail-app[data-view="detail"]')
		await page.click('.wm-detail .wm-back')
		await page.waitForSelector('#webmail-app[data-view="list"]')
		assert.strictEqual(await page.evaluate(() => history.state && history.state.webmail), null)
		await page.setViewportSize({ width: 1440, height: 900 })
	})

	await step('compose new message with attachment', async () => {
		await page.click('.wm-compose-btn')
		await page.fill('input[name=to]', 'Client <client@exemple.fr>, autre@exemple.fr')
		await page.fill('input[name=subject]', 'Test depuis Nextcloud')
		await page.fill('.wm-editor', 'Bonjour,\nceci est un test.\nhttps://nextcloud.com')
		await page.setInputFiles('.wm-file-input', { name: 'note.txt', mimeType: 'text/plain', buffer: Buffer.from('contenu') })
		await page.waitForSelector('.wm-compose-attachments li:has-text("note.txt")')
		await shot('04-compose')
		await page.click('.wm-compose button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Message envoyé")')
	})

	await step('sent message is in Sent Items with attachment', async () => {
		await page.click('.wm-folder:has-text("Éléments envoyés")')
		await page.waitForSelector('.wm-message:has-text("Test depuis Nextcloud")')
		assert(await page.$('.wm-message:has-text("Test depuis Nextcloud") .wm-attach-icon'))
		assert((await page.textContent('.wm-message:has-text("Test depuis Nextcloud") .wm-message-from')).startsWith('À : client'))
		await page.click('.wm-message:has-text("Test depuis Nextcloud")')
		await page.frameLocator('.wm-body-frame').locator('a[href="https://nextcloud.com"]').waitFor()
	})

	await step('reply with Files attachment', async () => {
		await page.click('.wm-folder:has-text("Boîte de réception")')
		await page.click('.wm-message:has-text("Réunion de lundi")')
		await page.waitForSelector('.wm-body-frame')
		await page.click('.wm-toolbar button:has-text("↩ Répondre")')
		await page.waitForSelector('.wm-info:has-text("Réponse à : Marie Martin")')
		assert.strictEqual(await page.inputValue('input[name=subject]'), 'RE : Réunion de lundi')
		await page.evaluate(() => { OC.dialogs.filepicker = (t, cb) => cb(['/Documents/devis-2027.pdf']) })
		await page.click('button:has-text("Depuis Fichiers")')
		await page.fill('.wm-editor', 'Oui, 14h me va.')
		await page.click('.wm-compose button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Message envoyé")')
	})

	await step('Nextcloud share links instead of heavy attachments', async () => {
		await page.click('.wm-compose-btn')
		await page.fill('input[name=to]', 'client@exemple.fr')
		await page.fill('input[name=subject]', 'Plans du chantier')
		await page.fill('.wm-editor', 'Bonjour, voici les documents.')
		// a file of Files, as a link in the text
		await page.evaluate(() => { OC.dialogs.filepicker = (t, cb) => cb(['/Documents/devis-2027.pdf']) })
		await page.click('.wm-link-btn')
		const link = '.wm-editor a:has-text("devis-2027.pdf")'
		await page.waitForSelector(link)
		const href = await page.getAttribute(link, 'href')
		assert(/\/s\/[A-Za-z0-9]+$/.test(href), href)
		assert((await page.textContent('.wm-editor')).includes('devis-2027.pdf ('), 'with its size')
		// the same file again: the same link (not a second share)
		await page.click('.wm-link-btn')
		await page.waitForFunction(() => document.querySelectorAll('.wm-editor a').length === 2)
		const hrefs = await page.$$eval('.wm-editor a', (els) => els.map((e) => e.getAttribute('href')))
		assert.strictEqual(hrefs[0], hrefs[1])
		// a heavy file of the computer: a warning, then put in Files and replaced by its link
		await page.setInputFiles('.wm-file-input', { name: 'plan-chantier.pdf', mimeType: 'application/pdf', buffer: Buffer.alloc(16 * 1024 * 1024, 37) })
		await page.waitForSelector('.wm-compose-attachments li.wm-heavy:has-text("plan-chantier.pdf")')
		await page.waitForSelector('.wm-heavy-warning:has-text("Exchange peut refuser le message")')
		await shot('50-share-link')
		await page.click('.wm-compose-attachments li:has-text("plan-chantier.pdf") .wm-to-link')
		await page.waitForSelector('.wm-editor a:has-text("plan-chantier.pdf")', { timeout: 60000 })
		await page.waitForSelector('.wm-heavy-warning', { state: 'detached' })
		assert(!(await page.$('.wm-compose-attachments li:has-text("plan-chantier.pdf")')), 'no longer attached')
		// anybody with the link can download
		const visitor = await browser.newContext()
		const big = await page.getAttribute('.wm-editor a:has-text("plan-chantier.pdf")', 'href')
		const res = await visitor.request.get(big + '/download')
		assert.strictEqual(res.status(), 200)
		assert.strictEqual((await res.body()).length, 16 * 1024 * 1024)
		await visitor.close()
		await page.click('.wm-compose button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Message envoyé")')
	})

	await step('conversation: the replies sent shown with the message', async () => {
		await page.click('.wm-folder:has-text("Boîte de réception")')
		await page.click('.wm-message:has-text("Réunion de lundi")')
		await page.waitForSelector('.wm-message-header h2:has-text("Réunion de lundi")')
		await page.waitForSelector('.wm-thread-toggle:has-text("Conversation : 2 messages")')
		if (await page.isHidden('.wm-thread-list')) await page.click('.wm-thread-toggle')
		const rows = await page.$$eval('.wm-thread-item', (els) => els.map((e) => e.textContent))
		assert.strictEqual(rows.length, 2, rows.join(' | '))
		assert(rows[0].includes('Marie Martin') && rows[0].includes('ce message'), rows[0])
		assert(rows[1].includes('Éléments envoyés') && rows[1].includes('Oui, 14h me va.'), rows[1])
		// the reply opens in place
		await page.click('.wm-thread-item:has-text("Éléments envoyés") .wm-thread-head')
		await page.waitForSelector('.wm-thread-item:has-text("Éléments envoyés") .wm-thread-body .wm-body-frame')
		const frame = await (await page.$('.wm-thread-item:has-text("Éléments envoyés") .wm-body-frame')).contentFrame()
		await frame.waitForSelector('text=Oui, 14h me va.')
		await shot('53-conversation')
		// a message alone has no conversation part
		await page.click('.wm-message:has-text("Newsletter n°1")')
		await page.waitForSelector('.wm-message-header h2:has-text("Newsletter n°1")')
		await page.waitForTimeout(800)
		assert(await page.isHidden('.wm-thread'))
		await page.click('.wm-message:has-text("Réunion de lundi")')
		await page.waitForSelector('.wm-message-header h2:has-text("Réunion de lundi")')
	})

	await step('list grouped by conversation', async () => {
		await page.click('.wm-folder:has-text("Clients")')
		await page.waitForSelector('.wm-message:has-text("Photos du chantier")')
		// three messages of the same order: three rows
		assert.strictEqual(await page.$$eval('.wm-message', (els) => els.filter((e) => e.textContent.includes('Commande 4521')).length), 3)
		await page.click('.wm-group-toggle')
		// one row per conversation, with its number of messages; the newest one is unread
		const conv = '.wm-conversations .wm-message:has-text("Commande 4521")'
		await page.waitForSelector(conv + ' .wm-thread-count:text-is("3")')
		await page.waitForSelector(conv + '.unread')
		assert((await page.textContent(conv + ' .wm-message-from')).includes('Client B, Utilisateur'))
		assert.strictEqual(await page.$$eval('.wm-conversations .wm-message', (els) => els.length), 2)
		assert(!(await page.$('.wm-conversations .wm-check')), 'no ticks for conversations')
		await shot('67-grouped-list')
		// opened on its newest message, with the whole conversation
		await page.click(conv)
		await page.waitForSelector('.wm-body-frame')
		await page.frameLocator('.wm-body-frame >> nth=0').locator('text=Facture bien reçue.').waitFor()
		await page.waitForSelector('.wm-thread-toggle:has-text("Conversation : 3 messages")')
		// remembered, and searching shows the messages again
		await page.reload()
		await page.click('.wm-folder:has-text("Clients")')
		await page.waitForSelector('.wm-group-toggle.active')
		await page.waitForSelector('.wm-conversations .wm-message:has-text("Commande 4521")')
		await page.fill('.wm-list .wm-search', 'Commande')
		await page.press('.wm-list .wm-search', 'Enter')
		await page.waitForFunction(() => document.querySelectorAll('.wm-message').length === 3 && !document.querySelector('.wm-conversations'))
		await page.click('.wm-search-info a:has-text("effacer")')
		// back to one row per message
		await page.click('.wm-group-toggle')
		await page.waitForFunction(() => !document.querySelector('.wm-conversations') && document.querySelectorAll('.wm-message').length >= 4)
		await page.click('.wm-folder:has-text("Boîte de réception")')
		await page.click('.wm-message:has-text("Réunion de lundi")')
		await page.waitForSelector('.wm-message-header h2:has-text("Réunion de lundi")')
	})

	await step('forward', async () => {
		await page.click('.wm-toolbar button:has-text("Transférer")')
		await page.click('.wm-compose button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Indiquez au moins un destinataire")')
		await page.fill('input[name=to]', 'collegue@societe-a.fr')
		await page.click('.wm-compose button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Message envoyé")')
	})

	await step('move to folder', async () => {
		await page.selectOption('.wm-move', { label: 'Clients' })
		await page.waitForSelector('.wm-toast:has-text("Message déplacé")')
		assert(!(await page.$('.wm-message:has-text("Réunion de lundi")')))
		await page.click('.wm-folder:has-text("Clients")')
		await page.waitForSelector('.wm-message:has-text("Réunion de lundi")')
	})

	await step('delete then delete permanently', async () => {
		await page.click('.wm-message:has-text("Réunion de lundi")')
		await page.click('.wm-toolbar button:has-text("Supprimer")')
		await page.waitForSelector('.wm-toast:has-text("éléments supprimés")')
		await page.click('.wm-folder:has-text("Éléments supprimés")')
		await page.click('.wm-message:has-text("Réunion de lundi")')
		page.once('dialog', (d) => d.accept())
		await page.click('.wm-toolbar button:has-text("Supprimer définitivement")')
		await page.waitForSelector('.wm-toast:has-text("supprimé définitivement")')
		// the next one in the trash (deleted earlier) opens
		await page.waitForSelector('.wm-message-header h2:has-text("Newsletter n°3")')
		assert(!(await page.$('.wm-message:has-text("Réunion de lundi")')))
	})

	await step('several messages: ticks, grouped actions, drag and drop', async () => {
		const msg = (n) => '.wm-message:has(.wm-message-subject:text-is("Newsletter n°' + n + '"))'
		await page.click('.wm-folder:has-text("Boîte de réception")')
		await page.waitForSelector(msg(7))
		// tick 7, then Shift+click 9: 7, 8 and 9
		await page.click(msg(7) + ' .wm-check')
		await page.waitForSelector('.wm-bulk:has-text("1 sélectionné")')
		await page.click(msg(9) + ' .wm-check', { modifiers: ['Shift'] })
		await page.waitForSelector('.wm-bulk:has-text("3 sélectionnés")')
		assert.strictEqual(await page.$$eval('.wm-message.checked', (els) => els.length), 3)
		await shot('38-several-messages')
		await page.click('.wm-bulk button:has-text("Non lu")')
		await page.waitForSelector('.wm-toast:has-text("3 messages marqués comme non lus")')
		for (const n of [7, 8, 9]) await page.waitForSelector(msg(n) + '.unread')
		assert(!(await page.$('.wm-bulk')), 'ticks cleared after the action')

		// Ctrl+click ticks without opening, then move them all
		await page.click(msg(7), { modifiers: ['Control'] })
		await page.click(msg(8), { modifiers: ['Control'] })
		await page.click(msg(9), { modifiers: ['Control'] })
		await page.waitForSelector('.wm-bulk:has-text("3 sélectionnés")')
		await page.selectOption('.wm-bulk select[name=bulkMove]', { label: 'Factures' })
		await page.waitForSelector('.wm-toast:has-text("3 messages déplacés vers « Factures »")')
		for (const n of [7, 8, 9]) assert(!(await page.$(msg(n))))

		// Escape drops the ticks
		await page.click(msg(11) + ' .wm-check')
		await page.waitForSelector('.wm-bulk')
		await page.keyboard.press('Escape')
		await page.waitForSelector('.wm-bulk', { state: 'detached' })

		// drag a message onto a folder (the browser's drag events, fired here:
		// Playwright's drag emulation hangs with this headless Chromium)
		const accepted = await page.evaluate(() => {
			const li = [...document.querySelectorAll('.wm-message')].find((e) => e.querySelector('.wm-message-subject').textContent === 'Newsletter n°10')
			const folder = [...document.querySelectorAll('.wm-folder')].find((e) => e.textContent.includes('Factures'))
			const dt = new DataTransfer()
			li.dispatchEvent(new DragEvent('dragstart', { bubbles: true, cancelable: true, dataTransfer: dt }))
			const over = new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer: dt })
			folder.dispatchEvent(over)
			folder.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: dt }))
			li.dispatchEvent(new DragEvent('dragend', { bubbles: true, dataTransfer: dt }))
			return over.defaultPrevented
		})
		assert(accepted, 'the folder accepts the drop')
		await page.waitForSelector('.wm-toast:has-text("Message déplacé vers « Factures »")')
		assert(!(await page.$(msg(10))))
		await page.click('.wm-folder:has-text("Factures")')
		for (const n of [7, 8, 9, 10]) await page.waitForSelector(msg(n))
		await page.click('.wm-folder:has-text("Boîte de réception")')
		await page.waitForSelector(msg(11))
	})

	await step('managing folders: create, sub-folder, rename, move, delete', async () => {
		const row = (name) => '.wm-folder-row:has(.wm-folder-name:text-is("' + name + '"))'
		const menu = async (name, action) => {
			await page.hover(row(name))
			await page.click(row(name) + ' .wm-folder-menu-btn')
			await page.click('.wm-folder-menu button:has-text("' + action + '")')
		}
		const ask = async (value, button) => {
			await page.fill('.wm-modal input[name=folderName]', value)
			await page.click('.wm-modal button:has-text("' + button + '")')
		}
		await page.click('.wm-new-folder')
		await ask('Projets', 'Créer')
		await page.waitForSelector('.wm-toast:has-text("Dossier « Projets » créé")')
		await page.waitForSelector(row('Projets'))
		// same name at the same place: refused by Exchange
		await page.click('.wm-new-folder')
		await ask('Projets', 'Créer')
		await page.waitForSelector('.wm-toast:has-text("Un dossier porte déjà ce nom")')

		await menu('Projets', 'Nouveau sous-dossier')
		await page.waitForSelector('.wm-modal h2:has-text("Nouveau dossier dans « Projets »")')
		await ask('Chantier Nice', 'Créer')
		await page.waitForSelector(row('Chantier Nice'))
		const indent = async (name) => parseInt(await page.$eval(row(name) + ' .wm-folder', (a) => getComputedStyle(a).paddingInlineStart), 10)
		assert((await indent('Chantier Nice')) > (await indent('Projets')), 'shown inside « Projets »')
		await menu('Chantier Nice', 'Renommer')
		await ask('Chantier Antibes', 'Renommer')
		await page.waitForSelector('.wm-toast:has-text("Dossier renommé en « Chantier Antibes »")')
		await page.waitForSelector(row('Chantier Antibes'))
		await shot('39-folders')

		// a message into it, then the folder to the top of the mailbox
		await page.click('.wm-message:has(.wm-message-subject:text-is("Newsletter n°11")) .wm-check')
		await page.selectOption('.wm-bulk select[name=bulkMove]', { label: 'Chantier Antibes' })
		await page.waitForSelector('.wm-toast:has-text("Message déplacé vers « Chantier Antibes »")')
		await menu('Chantier Antibes', 'Déplacer')
		await page.selectOption('.wm-modal select[name=folderChoice]', '')
		await page.click('.wm-modal button:has-text("Déplacer")')
		await page.waitForSelector('.wm-toast:has-text("déplacé en haut de la boîte aux lettres")')
		await page.waitForFunction(() => { const a = [...document.querySelectorAll('.wm-folder')].find((x) => x.textContent.includes('Chantier Antibes')); return a && parseInt(getComputedStyle(a).paddingInlineStart, 10) === 12 })
		await page.click(row('Chantier Antibes') + ' .wm-folder')
		await page.waitForSelector('.wm-message:has-text("Newsletter n°11")')

		// delete: into « Éléments supprimés », with what it contains
		page.once('dialog', (d) => d.accept())
		await menu('Projets', 'Supprimer')
		await page.waitForSelector('.wm-toast:has-text("Dossier « Projets » placé dans les éléments supprimés")')
		// Exchange's own folders cannot be renamed, moved or deleted
		await page.hover(row('Boîte de réception'))
		await page.click(row('Boîte de réception') + ' .wm-folder-menu-btn')
		const items = await page.$$eval('.wm-folder-menu button', (els) => els.map((e) => e.textContent))
		assert.deepStrictEqual(items, ['＋ Nouveau sous-dossier', '📥 Importer des messages (.eml)…'])
		await page.keyboard.press('Escape')
		await page.click(row('Boîte de réception') + ' .wm-folder')
		await page.waitForSelector('.wm-message')
	})

	await step('junk e-mail: junk and not junk', async () => {
		const msg = (n) => '.wm-message:has(.wm-message-subject:text-is("Newsletter n°' + n + '"))'
		await page.click('.wm-folder:has-text("Boîte de réception")')
		await page.click(msg(12))
		await page.waitForSelector('.wm-message-header h2:has-text("Newsletter n°12")')
		await page.click('.wm-toolbar .wm-junk-btn:has-text("Indésirable")')
		await page.waitForSelector('.wm-toast:has-text("Message dans « Courrier indésirable » ; l\'expéditeur est bloqué")')
		assert(!(await page.$(msg(12))))
		// several at once
		await page.click(msg(13) + ' .wm-check')
		await page.click(msg(14) + ' .wm-check')
		await page.click('.wm-bulk .wm-junk-btn')
		await page.waitForSelector('.wm-toast:has-text("2 messages dans « Courrier indésirable » ; leurs expéditeurs sont bloqués")')
		// from the junk folder: not junk, back to the inbox
		await page.click('.wm-folder:has-text("Courrier indésirable")')
		await page.click(msg(12))
		await page.waitForSelector('.wm-toolbar .wm-junk-btn:has-text("Pas indésirable")')
		await shot('42-junk')
		await page.click('.wm-toolbar .wm-junk-btn')
		await page.waitForSelector('.wm-toast:has-text("Message remis dans la boîte de réception ; l\'expéditeur est considéré comme fiable")')
		await page.click('.wm-folder:has-text("Boîte de réception")')
		await page.waitForSelector(msg(12))
	})

	await step('send options: later delivery, read receipt; printing', async () => {
		await page.click('.wm-compose-btn')
		await page.fill('input[name=to]', 'client@exemple.fr')
		await page.fill('input[name=subject]', 'Rapport trimestriel')
		await page.fill('.wm-editor', 'Bonjour, voici le rapport.')
		await page.check('.wm-send-options input[name=readReceipt]')
		await page.check('.wm-send-options input[name=sendLater]')
		// tomorrow 8:00 is proposed
		assert(/T08:00$/.test(await page.inputValue('.wm-send-options input[name=sendAt]')))
		await page.waitForSelector('.wm-compose button[type=submit]:has-text("Programmer l\'envoi")')
		await shot('43-send-later')
		await page.click('.wm-compose button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Envoi programmé le")')
		// waiting in the Outbox, not in Sent Items
		await page.click('.wm-folder:has-text("Boîte d\'envoi")')
		await page.waitForSelector('.wm-message:has-text("Rapport trimestriel")')
		await page.click('.wm-folder:has-text("Éléments envoyés")')
		await page.waitForSelector('.wm-message')
		assert(!(await page.$('.wm-message:has-text("Rapport trimestriel")')))

		// print a message: its own page with the headers
		await page.click('.wm-message:has-text("Test depuis Nextcloud")')
		await page.waitForSelector('.wm-message-header h2:has-text("Test depuis Nextcloud")')
		const [popup] = await Promise.all([page.context().waitForEvent('page'), page.click('.wm-print-btn')])
		await popup.waitForLoadState()
		assert.strictEqual(await popup.title(), 'Test depuis Nextcloud')
		const printed = await popup.textContent('body')
		assert(printed.includes('De') && printed.includes('client@exemple.fr') && printed.includes('note.txt'), printed.slice(0, 200))
		await popup.close()
		await page.click('.wm-folder:has-text("Boîte de réception")')
		await page.waitForSelector('.wm-message')
	})

	await step('account settings: signature', async () => {
		await page.click('text=Comptes et signature')
		// a formatted signature: a name, a bold company and a logo
		const ed = '.wm-account-card .wm-signature-editor >> nth=0'
		await page.click(ed)
		await page.keyboard.type('Utilisateur')
		await page.keyboard.press('Enter')
		await page.click('.wm-account-card .wm-editor-btn[title="Gras"] >> nth=0')
		await page.keyboard.type('Société A')
		await page.click('.wm-account-card .wm-editor-btn[title="Gras"] >> nth=0')
		await page.setInputFiles('.wm-account-card input[name=signatureImage] >> nth=0', { name: 'logo.png', mimeType: 'image/png',
			buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64') })
		await page.waitForSelector('.wm-account-card .wm-signature-editor img')
		await shot('61-signature')
		await page.click('.wm-account-card button:has-text("Enregistrer") >> nth=0')
		await page.waitForSelector('.wm-toast:has-text("Compte mis à jour")')
		// kept by the server, formatted, after a reload
		await page.reload()
		await page.waitForSelector('.wm-message')
		await page.click('.wm-compose-btn')
		const signed = await page.innerText('.wm-editor')
		assert(/--\s*\n+Utilisateur\s*\n+Société A/.test(signed), signed)
		await shot('62-signature-in-message')
		assert(await page.$('.wm-editor .wm-signature b:has-text("Société A")'), 'bold kept')
		assert(await page.$('.wm-editor .wm-signature img[src^="data:image/png"]'), 'logo')
	})

	await step('automatic replies (out of office)', async () => {
		await page.click('text=Comptes et signature')
		await page.click('.wm-account-card:has-text("user@societe-a.fr") .wm-oof-btn')
		await page.waitForSelector('.wm-modal input[name=oofState][value=Disabled]:checked')
		assert(!(await page.isVisible('.wm-modal textarea[name=oofInternal]')), 'nothing to fill while disabled')
		await page.check('.wm-modal input[name=oofState][value=Scheduled]')
		const local = (d) => { const p = (n) => String(n).padStart(2, '0'); return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes()) }
		const end = new Date(Date.now() + 3 * 86400000)
		await page.fill('.wm-modal input[name=oofStart]', local(new Date(Date.now() - 3600000)))
		await page.fill('.wm-modal input[name=oofEnd]', local(end))
		await page.fill('.wm-modal textarea[name=oofInternal]', 'Bonjour,\nje suis absent. Pour toute urgence : Paul Durand.')
		await page.selectOption('.wm-modal select[name=oofAudience]', 'Known')
		await shot('37-out-of-office')
		await page.click('.wm-modal button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Réponse automatique programmée du")')
		// reminder in the folder pane
		await page.waitForSelector('.wm-oof-banner:has-text("Réponse automatique activée jusqu\'au")')
		// saved as set
		await page.click('.wm-oof-banner a:has-text("Modifier")')
		await page.waitForSelector('.wm-modal input[name=oofState][value=Scheduled]:checked')
		assert.strictEqual(await page.inputValue('.wm-modal textarea[name=oofInternal]'), 'Bonjour,\nje suis absent. Pour toute urgence : Paul Durand.')
		assert.strictEqual(await page.inputValue('.wm-modal select[name=oofAudience]'), 'Known')
		assert.strictEqual(await page.inputValue('.wm-modal input[name=oofEnd]'), local(end))
		await page.click('.wm-modal-close')
		// switched off from the reminder
		await page.click('.wm-oof-banner a:has-text("Désactiver")')
		await page.waitForSelector('.wm-toast:has-text("Réponse automatique désactivée")')
		await page.waitForSelector('.wm-oof-banner', { state: 'detached' })
	})

	await step('inbox rules: create, order, switch off, run on arrival, from a message', async () => {
		await page.click('text=Comptes et signature')
		await page.click('.wm-account-card:has-text("user@societe-a.fr") .wm-rules-btn')
		// a rule made in Outlook: shown, can be switched off, not edited here
		const outlook = '.wm-rule:has-text("Grosses pièces jointes")'
		await page.waitForSelector(outlook + ' .wm-rule-tag:has-text("créée dans Outlook")')
		assert(!(await page.$(outlook + ' .wm-rule-edit')), 'no edit button')
		// a new rule
		await page.click('.wm-rule-new')
		await page.fill('input[name=ruleName]', 'Relevés bancaires')
		await page.fill('input[name=ruleSubject]', 'relevé, extrait de compte')
		await page.selectOption('select[name=ruleMove]', { label: 'Factures' })
		await page.check('input[name=ruleMarkRead]')
		await page.check('input[name=ruleStop]')
		await page.click('.wm-rule-form button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Règle créée")')
		const mine = '.wm-rule:has-text("Relevés bancaires")'
		const summary = await page.textContent(mine + ' .wm-rule-summary')
		assert(summary.includes('Si l\'objet contient « relevé » ou « extrait de compte » → déplacer vers Factures, marquer comme lu, puis ne pas appliquer les règles suivantes'), summary)
		// created last, moved up
		let names = await page.$$eval('.wm-rule-name', (els) => els.map((e) => e.textContent))
		assert.deepStrictEqual(names, ['Grosses pièces jointes', 'Relevés bancaires'])
		await page.click(mine + ' .wm-rule-up')
		await page.waitForFunction(() => document.querySelector('.wm-rule-name').textContent === 'Relevés bancaires')
		// the Outlook rule switched off, sent back to Exchange untouched otherwise
		await page.uncheck(outlook + ' .wm-rule-enabled')
		await page.waitForSelector('.wm-toast:has-text("Règle désactivée")')
		await page.waitForSelector('.wm-rule.off:has-text("Grosses pièces jointes") .wm-rule-tag')
		await shot('45-inbox-rules')
		// edited
		await page.click(mine + ' .wm-rule-edit')
		assert.strictEqual(await page.inputValue('input[name=ruleSubject]'), 'relevé, extrait de compte')
		assert.strictEqual(await page.inputValue('select[name=ruleMove]') !== '', true)
		await page.fill('input[name=ruleName]', 'Relevés de la banque')
		await page.click('.wm-rule-form button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Règle enregistrée")')
		await page.waitForSelector('.wm-rule-name:text-is("Relevés de la banque")')
		// a message that matches arrives: Exchange files it, read, in Factures
		await page.click('.wm-compose-btn')
		await page.fill('input[name=to]', 'user@societe-a.fr')
		await page.fill('input[name=subject]', 'Votre relevé de novembre')
		await page.fill('.wm-editor', 'Bonjour')
		await page.click('.wm-compose button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Message envoyé")')
		await page.click('.wm-folder:has-text("Factures")')
		await page.waitForSelector('.wm-message:not(.unread):has-text("Votre relevé de novembre")')
		// a rule from a message: its sender is filled in
		await page.click('.wm-message:has-text("Votre relevé de novembre")')
		await page.waitForSelector('.wm-message-header h2:has-text("Votre relevé de novembre")')
		await page.click('.wm-rule-from-msg')
		await page.waitForSelector('.wm-rule-form h3:has-text("Nouvelle règle")')
		assert.strictEqual(await page.inputValue('input[name=ruleFrom]'), 'user@societe-a.fr')
		// it must do something
		await page.click('.wm-rule-form button[type=submit]')
		await page.waitForSelector('.wm-rule-form .wm-form-error:has-text("Choisissez ce que la règle doit faire")')
		await page.click('.wm-rule-form button:has-text("Annuler")')
		// deleted
		page.once('dialog', (d) => d.accept())
		await page.click('.wm-rule:has-text("Relevés de la banque") .wm-rule-delete')
		await page.waitForSelector('.wm-toast:has-text("Règle supprimée")')
		names = await page.$$eval('.wm-rule-name', (els) => els.map((e) => e.textContent))
		assert.deepStrictEqual(names, ['Grosses pièces jointes'])
		await page.click('.wm-folder:has-text("Boîte de réception")')
		await page.waitForSelector('.wm-message')
	})

	await step('Outlook categories: colours, tick, create, filter, several messages', async () => {
		await page.click('.wm-folder:has-text("Boîte de réception")')
		const devis = '.wm-message:has-text("Devis pour la maintenance annuelle")'
		await page.click(devis)
		await page.waitForSelector('.wm-message-header h2:has-text("Devis pour la maintenance annuelle")')
		// the mailbox's list, with Outlook's colours
		await page.click('.wm-toolbar .wm-cat-btn')
		await page.waitForSelector('.wm-cat-menu .wm-cat-choice:has-text("Clients")')
		await page.check('.wm-cat-menu .wm-cat-choice:has-text("Clients") input')
		const pill = '.wm-header-cats .wm-cat:text-is("Clients")'
		await page.waitForSelector(pill)
		assert.strictEqual(await page.$eval(pill, (e) => getComputedStyle(e).backgroundColor), 'rgb(157, 183, 232)')
		await page.waitForSelector(devis + ' .wm-cat:text-is("Clients")')
		// a new one, with its colour
		await page.fill('.wm-cat-menu input[name=newCategory]', 'Fournisseurs')
		await page.selectOption('.wm-cat-menu select[name=newCategoryColor]', { label: 'Vert foncé' })
		await page.click('.wm-cat-menu button:has-text("Créer")')
		await page.waitForSelector('.wm-header-cats .wm-cat:text-is("Fournisseurs")')
		assert.strictEqual(await page.$eval('.wm-header-cats .wm-cat:text-is("Fournisseurs")', (e) => getComputedStyle(e).color), 'rgb(255, 255, 255)', 'white text on a dark colour')
		await page.waitForSelector('.wm-cat-menu .wm-cat-choice:has-text("Fournisseurs") input:checked')
		await shot('48-categories')
		await page.keyboard.press('Escape')
		await page.waitForSelector('.wm-cat-menu', { state: 'detached' })
		// saved in Exchange: still there once reloaded
		await page.reload()
		await page.waitForSelector(devis + ' .wm-cat:text-is("Fournisseurs")')
		// several messages at once
		await page.click('.wm-message:has-text("Newsletter n°1") .wm-check')
		await page.click('.wm-message:has-text("Newsletter n°2") .wm-check')
		await page.click('.wm-bulk .wm-cat-btn')
		await page.check('.wm-cat-menu .wm-cat-choice:has-text("Clients") input')
		await page.waitForSelector('.wm-message:has-text("Newsletter n°2") .wm-cat:text-is("Clients")')
		await page.waitForSelector('.wm-message:has-text("Newsletter n°1") .wm-cat:text-is("Clients")')
		await page.keyboard.press('Escape')
		await page.keyboard.press('Escape')
		// a click on a pill: every message of that category, in all folders
		await page.click(devis)
		await page.click('.wm-header-cats .wm-cat:text-is("Clients")')
		await page.waitForFunction(() => document.querySelectorAll('.wm-message').length === 3)
		// removed from the newsletters, from the menu with them ticked
		await page.click('.wm-message:has-text("Newsletter n°1") .wm-check')
		await page.click('.wm-message:has-text("Newsletter n°2") .wm-check')
		await page.click('.wm-bulk .wm-cat-btn')
		await page.uncheck('.wm-cat-menu .wm-cat-choice:has-text("Clients") input')
		await page.waitForFunction(() => ![...document.querySelectorAll('.wm-message')].some((e) => /Newsletter/.test(e.textContent) && e.querySelector('.wm-cat')))
		await page.keyboard.press('Escape')
		await page.keyboard.press('Escape')
		await page.click('.wm-folder:has-text("Boîte de réception")')
		await page.waitForSelector(devis + ' .wm-cat:text-is("Clients")')
	})

	await step('Nextcloud notifications (bell) for new messages, webmail closed', async () => {
		const occ = (args) => execSync(OCC + ' ' + args, { encoding: 'utf8' })
		const jobId = (occ("background-job:list --class='OCA\\Webmail\\BackgroundJob\\NewMailJob'").match(/\|\s*(\d+)\s*\|\s*OCA/) || [])[1]
		assert(jobId, 'background job registered')
		const runJob = () => occ('background-job:execute ' + jobId + ' --force-execute')
		const bell = () => page.evaluate(async () => {
			const r = await fetch('/ocs/v2.php/apps/notifications/api/v2/notifications?format=json', { headers: { 'OCS-APIREQUEST': 'true' } })
			return (await r.json()).ocs.data.filter((n) => n.app === 'webmail')
		})
		const sendToSelf = async (subject) => {
			await page.click('.wm-compose-btn')
			await page.fill('input[name=to]', 'user@societe-a.fr')
			await page.fill('input[name=subject]', subject)
			await page.fill('.wm-editor', 'Bonjour, point rapide sur le budget.')
			await submitCompose()
		}
		// on by default, for each account
		await page.click('text=Comptes et signature')
		const box = '.wm-account-card:has-text("user@societe-a.fr") input[name=bell]'
		assert(await page.isChecked(box), 'on by default')
		// the first check only notes what is there: old messages are not announced
		runJob()
		assert.deepStrictEqual(await bell(), [])
		await sendToSelf('Budget du chantier')
		runJob()
		let mine = (await bell()).filter((n) => n.message.startsWith('« Budget du chantier »'))
		assert.strictEqual(mine.length, 1, JSON.stringify(await bell()))
		assert.strictEqual(mine[0].subject, 'Nouveau message d\'Utilisateur')
		assert.strictEqual(mine[0].message, '« Budget du chantier » — Bonjour, point rapide sur le budget. · user@societe-a.fr')
		// not announced twice
		runJob()
		assert.strictEqual((await bell()).filter((n) => n.message.startsWith('« Budget du chantier »')).length, 1)
		// through the subscription to the inbox; Exchange forgot it (no check for a long while): announced all the same,
		// by the dates, and subscribed again
		const accountId = await page.evaluate(async () => {
			const r = await fetch(OC.generateUrl('/apps/webmail/api/accounts'), { headers: { requesttoken: OC.requestToken } })
			return (await r.json()).find((a) => a.email === 'user@societe-a.fr').id
		})
		const subKey = 'bell_sub_' + accountId
		assert(/SUB-\d+/.test(occ('user:setting admin webmail ' + subKey)), 'subscribed')
		occ('user:setting admin webmail ' + subKey + ' \'{"id":"SUB-gone","watermark":"W0"}\'')
		await sendToSelf('Devis après une coupure')
		runJob()
		assert.strictEqual((await bell()).filter((n) => n.message.startsWith('« Devis après une coupure »')).length, 1)
		assert(!occ('user:setting admin webmail ' + subKey).includes('SUB-gone'), 'subscribed again')
		runJob()
		assert.strictEqual((await bell()).filter((n) => n.message.startsWith('« Devis après une coupure »')).length, 1, 'once')
		// in Nextcloud's bell
		await page.reload()
		await page.click('.notifications-button')
		await page.waitForSelector('.notification:has-text("Budget du chantier")')
		await shot('46-bell-notification')
		// its link opens the message, which leaves the bell
		await page.goto(mine[0].link)
		await page.waitForSelector('.wm-message-header h2:has-text("Budget du chantier")')
		const left = async () => (await bell()).filter((n) => n.message.startsWith('« Budget du chantier »')).length
		for (let i = 0; i < 20 && await left(); i++) await page.waitForTimeout(250)
		assert.strictEqual(await left(), 0)
		// (the other one read as well)
		await page.click('.wm-message:has-text("Devis après une coupure")')
		await page.waitForSelector('.wm-message-header h2:has-text("Devis après une coupure")')
		for (let i = 0; i < 20 && (await bell()).length; i++) await page.waitForTimeout(250)
		assert.deepStrictEqual(await bell(), [])
		// switched off for this account
		await page.click('text=Comptes et signature')
		await page.uncheck(box)
		await page.waitForSelector('.wm-toast:has-text("ne seront plus signalés")')
		await sendToSelf('Sans notification')
		runJob()
		assert.deepStrictEqual(await bell(), [])
		await page.click('text=Comptes et signature')
		await page.check(box)
		await page.waitForSelector('.wm-toast:has-text("seront signalés dans Nextcloud")')
		// read, so that the next steps find the inbox as before
		await page.click('.wm-folder:has-text("Boîte de réception")')
		await page.click('.wm-message:has-text("Sans notification")')
		await page.waitForSelector('.wm-message-header h2:has-text("Sans notification")')
	})

	await step('shared mailbox: add, read, reply as it, move, remove', async () => {
		await page.click('text=Comptes et signature')
		const card = '.wm-account-card:has-text("user@societe-a.fr")'
		// no access: a clear message
		await page.fill(card + ' input[name=sharedMailbox]', 'secretariat@societe-a.fr')
		await page.click(card + ' .wm-shared-editor button:has-text("Ajouter")')
		await page.waitForSelector(card + ' .wm-shared-editor .wm-form-error:has-text("Vous n\'avez pas accès à la boîte secretariat@societe-a.fr")')
		await page.fill(card + ' input[name=sharedMailbox]', 'contact@societe-a.fr')
		await page.click(card + ' .wm-shared-editor button:has-text("Ajouter")')
		await page.waitForSelector('.wm-toast:has-text("Boîte partagée ajoutée")')
		await page.waitForSelector(card + ' .wm-shared-list li:has-text("contact@societe-a.fr")')
		// its folders under the account's own
		const mb = '.wm-shared-mailbox[data-mailbox="contact@societe-a.fr"]'
		await page.waitForSelector(mb + ' .wm-folder:has-text("Boîte de réception") .wm-badge:text-is("1")')
		await page.waitForSelector(mb + ' .wm-folder:has-text("Demandes de devis")')
		await page.click(mb + ' .wm-folder:has-text("Boîte de réception")')
		await page.waitForSelector('.wm-message:has-text("Demande de devis cuisine")')
		assert(await page.$('.wm-message:has-text("Horaires d\'ouverture")'))
		await page.click('.wm-message:has-text("Demande de devis cuisine")')
		await page.waitForSelector('.wm-message-header h2:has-text("Demande de devis cuisine")')
		await shot('55-shared-mailbox')
		// moved within that mailbox only
		const targets = await page.$$eval('.wm-toolbar select.wm-move option', (els) => els.map((e) => e.textContent))
		assert(targets.includes('Demandes de devis') && !targets.includes('Factures'), targets.join(', '))
		// answered as the shared mailbox
		await page.click('.wm-toolbar button:has-text("↩ Répondre")')
		assert.strictEqual(await page.inputValue('.wm-compose select[name=from]'), 'contact@societe-a.fr')
		await page.fill('.wm-editor', 'Bonjour Madame, nous vous rappelons demain.')
		await page.click('.wm-compose button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Message envoyé")')
		// the account's own folders come first
		await page.click('.wm-folder:has-text("Éléments envoyés") >> nth=0')
		await page.click('.wm-message:has-text("RE: Demande de devis cuisine")')
		await page.waitForSelector('.wm-message-header:has-text("Contact Société A <contact@societe-a.fr>")')
		// a message moved to a folder of the shared mailbox
		await page.click(mb + ' .wm-folder:has-text("Boîte de réception")')
		await page.click('.wm-message:has-text("Horaires d\'ouverture")')
		await page.waitForSelector('.wm-message-header h2:has-text("Horaires d\'ouverture")')
		await page.selectOption('.wm-toolbar select.wm-move', { label: 'Demandes de devis' })
		await page.waitForSelector('.wm-toast:has-text("Demandes de devis")')
		await page.click(mb + ' .wm-folder:has-text("Demandes de devis")')
		await page.waitForSelector('.wm-message:has-text("Horaires d\'ouverture")')
		// removed from the webmail: nothing deleted in Exchange
		await page.click('text=Comptes et signature')
		await page.click(card + ' .wm-shared-list li:has-text("contact@societe-a.fr") button')
		await page.waitForSelector(card + ' .wm-shared-list li:has-text("Aucune")')
		await page.waitForSelector(mb, { state: 'detached' })
		await page.click('.wm-folder:has-text("Boîte de réception")')
		await page.waitForSelector('.wm-message')
	})

	await step('mail of a colleague who made us their delegate (inbox only)', async () => {
		await page.click('text=Comptes et signature')
		const card = '.wm-account-card:has-text("user@societe-a.fr")'
		await page.fill(card + ' input[name=sharedMailbox]', 'direction@societe-a.fr')
		await page.click(card + ' .wm-shared-editor button:has-text("Ajouter")')
		await page.waitForSelector('.wm-toast:has-text("Boîte partagée ajoutée")')
		await page.waitForSelector(card + ' .wm-shared-list li:has-text("direction@societe-a.fr") .wm-delegate-tag')
		// only the folders shared with us: the inbox and its sub-folder, nothing else
		const mb = '.wm-shared-mailbox[data-mailbox="direction@societe-a.fr"]'
		await page.waitForSelector(mb + ' .wm-shared-mailbox-title:has-text("👥 Sophie Direction")')
		await page.waitForSelector(mb + ' .wm-folder:has-text("Boîte de réception") .wm-badge:text-is("1")')
		await page.waitForSelector(mb + ' .wm-folder:has-text("Rapports mensuels")')
		assert(!(await page.$(mb + ' .wm-folder:has-text("Éléments envoyés")')), 'not shared with the delegate')
		await page.click(mb + ' .wm-folder:has-text("Boîte de réception")')
		await page.click('.wm-message:has-text("Point budget du trimestre")')
		await page.waitForSelector('.wm-message-header h2:has-text("Point budget du trimestre")')
		await shot('64-delegate-mailbox')
		// answered on her behalf
		await page.click('.wm-toolbar button:has-text("↩ Répondre")')
		assert.strictEqual(await page.inputValue('.wm-compose select[name=from]'), 'direction@societe-a.fr')
		await page.fill('.wm-editor', 'Bonjour Paul, c\'est validé.')
		await page.click('.wm-compose button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Message envoyé")')
		await page.click(mb + ' .wm-folder:has-text("Rapports mensuels")')
		await page.waitForSelector('.wm-message:has-text("Rapport mensuel d\'octobre")')
		// removed
		await page.click('text=Comptes et signature')
		await page.click(card + ' .wm-shared-list li:has-text("direction@societe-a.fr") button')
		await page.waitForSelector(card + ' .wm-shared-list li:has-text("Aucune")')
		await page.waitForSelector(mb, { state: 'detached' })
		await page.click('.wm-folder:has-text("Boîte de réception")')
		await page.waitForSelector('.wm-message')
	})

	await step('calendar reminders in Nextcloud notifications', async () => {
		const occ = (args) => execSync(OCC + ' ' + args, { encoding: 'utf8' })
		const jobId = (occ("background-job:list --class='OCA\\Webmail\\BackgroundJob\\ReminderJob'").match(/\|\s*(\d+)\s*\|\s*OCA/) || [])[1]
		assert(jobId, 'background job registered')
		const runJob = () => occ('background-job:execute ' + jobId + ' --force-execute')
		const reminders = () => page.evaluate(async () => {
			const r = await fetch('/ocs/v2.php/apps/notifications/api/v2/notifications?format=json', { headers: { 'OCS-APIREQUEST': 'true' } })
			return (await r.json()).ocs.data.filter((n) => n.app === 'webmail' && n.subject.startsWith('Rappel'))
		})
		// an appointment in 10 minutes, reminder 15 minutes before: due now
		const created = await page.evaluate(async () => {
			const accounts = await (await fetch('/apps/webmail/api/accounts', { headers: { requesttoken: OC.requestToken } })).json()
			const id = accounts.find((a) => a.email === 'user@societe-a.fr').id
			const iso = (min) => new Date(Date.now() + min * 60000).toISOString().slice(0, 16)
			const r = await fetch('/apps/webmail/api/accounts/' + id + '/calendar/events?tz=UTC', {
				method: 'POST',
				headers: { requesttoken: OC.requestToken, 'Content-Type': 'application/json' },
				body: JSON.stringify({ event: { subject: 'Appel fournisseur', start: iso(10), end: iso(40), location: 'Bureau 2', reminder: 15 } }),
			})
			if (!r.ok) throw new Error('event not created: ' + r.status)
			return { account: id, event: (await r.json()).id }
		})
		runJob()
		let mine = (await reminders()).filter((n) => n.subject === 'Rappel : Appel fournisseur')
		assert.strictEqual(mine.length, 1, JSON.stringify(await reminders()))
		assert(/^Aujourd'hui \d\d:\d\d · Bureau 2 · user@societe-a\.fr$/.test(mine[0].message), mine[0].message)
		// once only
		runJob()
		assert.strictEqual((await reminders()).filter((n) => n.subject === 'Rappel : Appel fournisseur').length, 1)
		await page.reload()
		await page.click('.notifications-button')
		await page.waitForSelector('.notification:has-text("Appel fournisseur")')
		await shot('49-reminder')
		// its link: the calendar, the appointment open
		await page.goto(mine[0].link)
		await page.waitForSelector('.wm-modal h2:has-text("Appel fournisseur")')
		await page.click('.wm-modal-close')
		await page.click('.wm-tab[data-module="mail"]')
		await page.waitForSelector('.wm-message')
		// can be switched off per account
		await page.click('text=Comptes et signature')
		const box = '.wm-account-card:has-text("user@societe-a.fr") input[name=reminders]'
		assert(await page.isChecked(box))
		await page.uncheck(box)
		await page.waitForSelector('.wm-toast:has-text("n\'arriveront plus dans Nextcloud")')
		await page.check(box)
		await page.waitForSelector('.wm-toast:has-text("arriveront dans Nextcloud")')
		// the next steps start from an empty bell and the calendar as before
		await page.evaluate(async (c) => {
			await fetch('/ocs/v2.php/apps/notifications/api/v2/notifications', { method: 'DELETE', headers: { 'OCS-APIREQUEST': 'true', requesttoken: OC.requestToken } })
			await fetch('/apps/webmail/api/accounts/' + c.account + '/calendar/delete', {
				method: 'POST',
				headers: { requesttoken: OC.requestToken, 'Content-Type': 'application/json' },
				body: JSON.stringify({ eventId: c.event }),
			})
		}, created)
	})

	await step('rich text editor and drafts', async () => {
		// a new message with formatting, saved as a draft while writing
		await page.click('.wm-compose-btn')
		await page.fill('input[name=to]', 'client@exemple.fr')
		await page.fill('input[name=subject]', 'Proposition commerciale')
		await page.click('.wm-editor')
		await page.keyboard.press('Control+Home')
		await page.keyboard.type('Bonjour, notre prix : ')
		await page.click('.wm-editor-btn[title^="Gras"]')
		await page.keyboard.type('1 200 €')
		await page.click('.wm-editor-btn[title^="Gras"]')
		await page.keyboard.type(' HT.')
		await page.waitForSelector('.wm-draft-status:has-text("Brouillon enregistré à")')
		assert(await page.$('.wm-editor b:has-text("1 200 €")'), 'bold text')
		await shot('36-editor')
		await page.click('.wm-compose button:has-text("Annuler")')
		await page.waitForSelector('.wm-compose', { state: 'detached' })

		// it is in « Brouillons » and opens in the editor
		await page.click('.wm-folder:has-text("Brouillons")')
		await page.click('.wm-message:has-text("Proposition commerciale")')
		await page.waitForSelector('.wm-compose h2:has-text("Brouillon")')
		assert.strictEqual(await page.inputValue('input[name=to]'), 'client <client@exemple.fr>')
		assert(await page.$('.wm-editor b:has-text("1 200 €")'), 'formatting kept in the draft')
		await page.fill('input[name=subject]', 'Proposition commerciale (v2)')
		await page.waitForSelector('.wm-draft-status:has-text("Brouillon enregistré à")')
		await page.click('.wm-compose button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Message envoyé")')
		await page.waitForFunction(() => ![...document.querySelectorAll('.wm-message')].some((e) => e.textContent.includes('Proposition commerciale')))
		// sent once, with its formatting
		await page.click('.wm-folder:has-text("Éléments envoyés")')
		await page.click('.wm-message:has-text("Proposition commerciale (v2)")')
		await page.frameLocator('.wm-body-frame').locator('b:has-text("1 200 €")').waitFor()
		assert.strictEqual(await page.$$eval('.wm-message', (els) => els.filter((e) => e.textContent.includes('Proposition commerciale')).length), 1)

		// a reply draft, then thrown away
		await page.click('.wm-folder:has-text("Boîte de réception")')
		await page.click('.wm-message:has-text("Votre facture OVH")')
		await page.click('.wm-toolbar button:has-text("↩ Répondre")')
		await page.keyboard.type('Merci, je regarde.')
		await page.waitForSelector('.wm-draft-status:has-text("Brouillon enregistré à")')
		page.once('dialog', (d) => d.accept())
		await page.click('.wm-compose button:has-text("Supprimer le brouillon")')
		await page.waitForSelector('.wm-toast:has-text("Brouillon supprimé")')
		await page.click('.wm-folder:has-text("Brouillons")')
		await page.waitForSelector('.wm-list .wm-search')
		await page.waitForFunction(() => !document.querySelector('.wm-list .icon-loading'))
		assert(!(await page.$('.wm-message:has-text("Votre facture OVH")')), 'reply draft deleted')
		await page.click('.wm-folder:has-text("Boîte de réception")')
	})

	await step('attachments kept with the draft', async () => {
		await page.click('.wm-compose-btn')
		await page.fill('input[name=to]', 'client@exemple.fr')
		await page.fill('input[name=subject]', 'Dossier de subvention')
		await page.setInputFiles('.wm-file-input', [
			{ name: 'formulaire.txt', mimeType: 'text/plain', buffer: Buffer.from('formulaire rempli') },
			{ name: 'brouillon-budget.txt', mimeType: 'text/plain', buffer: Buffer.from('budget provisoire') },
		])
		// saved in Exchange with the draft at once
		await page.waitForSelector('.wm-draft-att:has-text("formulaire.txt")')
		await page.waitForSelector('.wm-draft-att:has-text("brouillon-budget.txt")')
		await page.waitForSelector('.wm-draft-status:has-text("Pièces jointes enregistrées dans le brouillon")')
		await shot('63-draft-attachments')
		// the window closed: nothing lost
		await page.click('.wm-compose button:has-text("Annuler")')
		await page.waitForSelector('.wm-compose', { state: 'detached' })
		await page.click('.wm-folder:has-text("Brouillons")')
		const draft = '.wm-message:has-text("Dossier de subvention")'
		await page.waitForSelector(draft + ' .wm-attach-icon')
		await page.click(draft)
		await page.waitForSelector('.wm-compose h2:has-text("Brouillon")')
		await page.waitForSelector('.wm-draft-att:has-text("formulaire.txt")')
		// one removed, one added (from Files)
		await page.click('.wm-draft-att:has-text("brouillon-budget.txt") button:has-text("retirer")')
		await page.waitForSelector('.wm-draft-att:has-text("brouillon-budget.txt")', { state: 'detached' })
		await page.evaluate(() => { OC.dialogs.filepicker = (t, cb) => cb(['/Documents/devis-2027.pdf']) })
		await page.click('.wm-compose button:has-text("Depuis Fichiers")')
		await page.waitForSelector('.wm-draft-att:has-text("devis-2027.pdf")')
		assert.strictEqual(await page.$$eval('.wm-draft-att', (els) => els.length), 2)
		await page.click('.wm-compose button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Message envoyé")')
		await page.waitForSelector(draft, { state: 'detached' })
		// sent once, with these two files
		await page.click('.wm-folder:has-text("Éléments envoyés")')
		await page.click('.wm-message:has-text("Dossier de subvention")')
		await page.waitForSelector('.wm-attachment-name')
		const atts = await page.$$eval('.wm-attachment-name', (els) => els.map((e) => e.textContent).sort())
		assert.deepStrictEqual(atts, ['📎 devis-2027.pdf', '📎 formulaire.txt'])
		assert.strictEqual(await page.$$eval('.wm-message', (els) => els.filter((e) => e.textContent.includes('Dossier de subvention')).length), 1)
		await page.click('.wm-folder:has-text("Boîte de réception")')
	})

	await step('images in the text: pasted screenshot, inserted image, sent inline', async () => {
		await page.click('.wm-compose-btn')
		await page.fill('input[name=to]', 'client@exemple.fr')
		await page.fill('input[name=subject]', 'Capture du problème')
		await page.click('.wm-editor')
		await page.keyboard.press('Control+Home')
		await page.keyboard.type('Voici la capture : ')
		// a big screenshot pasted (2400 px wide): reduced to 1600 px
		await page.evaluate(async () => {
			const c = document.createElement('canvas')
			c.width = 2400
			c.height = 1200
			const ctx = c.getContext('2d')
			ctx.fillStyle = '#0082c9'
			ctx.fillRect(0, 0, 2400, 1200)
			ctx.fillStyle = '#fff'
			ctx.font = 'bold 160px sans-serif'
			ctx.fillText('Erreur 500', 300, 650)
			const blob = await new Promise((resolve) => c.toBlob(resolve, 'image/png'))
			const dt = new DataTransfer()
			dt.items.add(new File([blob], 'image.png', { type: 'image/png' }))
			document.querySelector('.wm-editor').dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }))
		})
		await page.waitForFunction(() => { const i = document.querySelector('.wm-editor img:not(.wm-signature *)'); return i && i.complete && i.naturalWidth === 1600 })
		// an image chosen with the 🖼 button
		await page.setInputFiles('input[name=inlineImages]', { name: 'logo.png', mimeType: 'image/png',
			buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64') })
		// (the logo of the signature is a third one)
		await page.waitForFunction(() => document.querySelectorAll('.wm-editor img:not(.wm-signature *)').length === 2)
		assert.strictEqual(await page.$eval('.wm-editor img[alt="logo.png"]', (i) => i.src.slice(0, 22)), 'data:image/png;base64,')
		// not shown as attachments: they are in the text
		assert.strictEqual(await page.$$eval('.wm-compose-attachments li', (els) => els.length), 0)
		await page.waitForSelector('.wm-draft-status:has-text("Brouillon enregistré à")')
		await shot('60-inline-images')
		await page.click('.wm-compose button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Message envoyé")')
		// sent with the images in the text (cid:), not as attachments
		await page.click('.wm-folder:has-text("Éléments envoyés")')
		await page.waitForSelector('.wm-message:has-text("Capture du problème")')
		assert(!(await page.$('.wm-message:has-text("Capture du problème") .wm-attach-icon')), 'no paper clip for inline images')
		await page.click('.wm-message:has-text("Capture du problème")')
		await page.waitForSelector('.wm-body-frame')
		const loaded = await page.waitForFunction(() => {
			const imgs = [...document.querySelector('.wm-body-frame').contentDocument.querySelectorAll('img')]
			return imgs.length === 3 && imgs.every((i) => i.complete && i.naturalWidth > 0 && i.getAttribute('src').includes('/apps/webmail/api/accounts/'))
		}, null, { timeout: 10000 }).then(() => true, () => false)
		assert(loaded, 'inline images shown')
		assert.strictEqual(await page.$$eval('.wm-attachment-name', (els) => els.length), 0)
		await page.click('.wm-folder:has-text("Boîte de réception")')
	})

	await step('second account on the other server', async () => {
		await page.click('text=Comptes et signature')
		await page.fill('.wm-add-account input[type=email]', 'compta@societe-b.fr')
		await page.fill('.wm-add-account input[type=password]', 'secret')
		await page.click('.wm-add-account button[type=submit]')
		await page.waitForSelector('.wm-account-select')
		const opts = await page.$$eval('.wm-account-select option', (els) => els.map((e) => e.textContent))
		assert.deepStrictEqual(opts, ['user@societe-a.fr', 'compta@societe-b.fr'])
		await page.selectOption('.wm-account-select', { label: 'user@societe-a.fr' })
		await page.waitForSelector('.wm-message')
		await page.click('.wm-message >> nth=0')
		await page.waitForSelector('.wm-body-frame')
		await shot('05-inbox')
	})

	await step('Autodiscover: the server of a domain missing from the table', async () => {
		const occ = (args) => execSync(OCC + ' ' + args, { encoding: 'utf8' })
		// (the fake servers are on the local network: allowed here only)
		occ('config:system:set allow_local_remote_servers --value=true --type=boolean')
		const admin = async (enabled, hosts) => {
			await page.goto(NC + '/settings/admin/webmail')
			await page.waitForSelector('#webmail-admin')
			// (Nextcloud's styled checkbox: the input itself is hidden behind its label)
			if (await page.isChecked('#webmail-autodiscover') !== enabled) await page.click('label[for="webmail-autodiscover"]')
			await page.fill('#webmail-autodiscover-hosts', hosts)
			await page.click('#webmail-save')
			await page.waitForSelector('#webmail-admin-msg.success')
		}
		const add = async () => {
			await page.goto(NC + '/apps/webmail/')
			await page.click('text=Comptes et signature')
			await page.fill('.wm-add-account input[type=email]', 'jean@societe-c.fr')
			await page.fill('.wm-add-account input[type=password]', 'secret')
			await page.click('.wm-add-account button[type=submit]')
		}
		try {
			// off (the default): the usual message
			await add()
			await page.waitForSelector('.wm-add-account .wm-form-error:has-text("Aucun serveur Exchange n\'est configuré pour le domaine « societe-c.fr »")')
			// on, but the server found is not among those allowed
			await admin(true, 'mail.ovh.net')
			await add()
			await page.waitForSelector('.wm-add-account .wm-form-error:has-text("n\'est pas parmi ceux autorisés")')
			// allowed: the account works, the server is remembered for the domain
			await admin(true, 'fake.test')
			await add()
			await page.waitForFunction(() => [...document.querySelectorAll('.wm-account-select option')].some((o) => o.textContent === 'jean@societe-c.fr'))
			await page.click('text=Comptes et signature')
			await page.waitForSelector('.wm-domains-hint:has-text("Pour les autres, le serveur est cherché automatiquement")')
			await page.goto(NC + '/settings/admin/webmail')
			await page.waitForSelector('#webmail-discovered li[data-domain="societe-c.fr"]:has-text("https://ex3.fake.test:8443/EWS/Exchange.asmx")')
			await shot('68-autodiscover')
			// forgotten
			await page.click('#webmail-discovered li[data-domain="societe-c.fr"] .wm-forget')
			await page.waitForSelector('#webmail-discovered li[data-domain="societe-c.fr"]', { state: 'detached' })
			await admin(false, 'mail.ovh.net')
		} finally {
			occ('config:system:delete allow_local_remote_servers')
		}
		// the account removed
		await page.goto(NC + '/apps/webmail/')
		await page.click('text=Comptes et signature')
		page.once('dialog', (d) => d.accept())
		await page.click('.wm-account-card:has-text("jean@societe-c.fr") button:has-text("Retirer ce compte")')
		await page.waitForFunction(() => ![...document.querySelectorAll('.wm-account-select option')].some((o) => o.textContent === 'jean@societe-c.fr'))
		await page.selectOption('.wm-account-select', { label: 'user@societe-a.fr' })
		await page.waitForSelector('.wm-message')
	})

	// ------------------------------------------------------------------ meeting invitation in the inbox
	await step('meeting invitation e-mail: accept', async () => {
		await page.selectOption('.wm-account-select', { label: 'user@societe-a.fr' })
		await page.click('.wm-message:has-text("Invitation : Comité de pilotage")')
		await page.waitForSelector('.wm-meeting:has-text("Invitation à une réunion")')
		assert((await page.textContent('.wm-meeting')).includes('Visio Teams'))
		await shot('08-invitation')
		await page.click('.wm-meeting button:has-text("Accepter")')
		await page.waitForSelector('.wm-toast:has-text("Réponse envoyée : accepté")')
		await page.waitForFunction(() => !document.querySelector('.wm-message[data-id] .wm-message-subject') || ![...document.querySelectorAll('.wm-message-subject')].some((e) => e.textContent.includes('Comité de pilotage')))
	})

	await step('invitation received as a delegate: answer on behalf of a colleague', async () => {
		await page.click('.wm-message:has-text("Invitation : Revue trimestrielle")')
		await page.waitForSelector('.wm-meeting.delegate:has-text("Invitation reçue pour Marie Martin (vous êtes son délégué)")')
		assert((await page.textContent('.wm-meeting')).includes('Répondre au nom de Marie Martin'))
		await shot('25-delegate-invitation')
		let confirmText = ''
		page.once('dialog', (d) => { confirmText = d.message(); d.accept() })
		await page.click('.wm-meeting button:has-text("Accepter")')
		await page.waitForSelector('.wm-toast:has-text("Réponse envoyée au nom de Marie Martin : accepté")')
		assert(confirmText.includes('au nom de Marie Martin'), confirmText)
		await page.waitForFunction(() => ![...document.querySelectorAll('.wm-message-subject')].some((e) => e.textContent.includes('Revue trimestrielle')))
	})

	await step('compose: address autocompletion from contacts and directory', async () => {
		await page.click('.wm-compose-btn')
		await page.type('input[name=to]', 'dup')
		await page.waitForSelector('.wm-ac-item:has-text("Jean Dupont")')
		await page.keyboard.press('Enter')
		assert.strictEqual(await page.inputValue('input[name=to]'), 'Jean Dupont <jean.dupont@client-exemple.fr>, ')
		await page.type('input[name=to]', 'marti')
		await page.waitForSelector('.wm-ac-item:has-text("annuaire")')
		const options = await page.$$eval('.wm-ac-item', (els) => els.map((e) => e.textContent))
		assert(options.some((o) => o.includes('marie.martin@societe-a.fr')), 'directory entry')
		await page.click('.wm-ac-item:has-text("Martine Leroy")')
		assert.strictEqual(await page.inputValue('input[name=to]'), 'Jean Dupont <jean.dupont@client-exemple.fr>, Martine Leroy <martine.leroy@societe-b.fr>, ')
		await shot('09-autocomplete')
		await page.click('.wm-compose button:has-text("Annuler")')
	})

	// ------------------------------------------------------------------ calendar
	await step('calendar: month view with events', async () => {
		await page.click('.wm-tab[data-module="calendar"]')
		await page.waitForSelector('.wm-month')
		await page.click('.wm-segmented button:has-text("Mois")')
		await page.waitForSelector('.wm-month-cell.today .wm-event:has-text("Point hebdo équipe")')
		assert(await page.$('.wm-event.allday:has-text("Salon Informatique")'), 'all-day event')
		// the accepted invitation is now in the calendar (may be next month)
		await page.click('.wm-segmented button:has-text("Liste")')
		await page.waitForSelector('.wm-agenda-item:has-text("Comité de pilotage")')
		assert((await page.textContent('.wm-agenda-item:has-text("Comité de pilotage")')).includes('Accepté'))
		await page.click('.wm-segmented button:has-text("Mois")')
		await page.waitForSelector('.wm-month-cell.today .wm-event')
		await shot('10-calendar-month')
	})

	await step('calendar: event details and edit', async () => {
		await page.click('.wm-month-cell.today .wm-event:has-text("Point hebdo équipe")')
		await page.waitForSelector('.wm-modal .wm-attendees')
		const detail = await page.textContent('.wm-modal')
		assert(detail.includes('Marie Martin') && detail.includes('Accepté') && detail.includes('Salle Méditerranée'), detail)
		await shot('11-event-detail')
		await page.click('.wm-modal button:has-text("Modifier")')
		await page.fill('.wm-modal input[name=location]', 'Salle Riviera')
		await page.click('.wm-modal button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("participants prévenus")')
		await page.click('.wm-month-cell.today .wm-event:has-text("Point hebdo équipe")')
		await page.waitForSelector('.wm-modal:has-text("Salle Riviera")')
		await page.click('.wm-modal-close')
	})

	await step('calendar: create a meeting with invitation', async () => {
		await page.click('.wm-compose-btn:has-text("Nouvel événement")')
		await page.waitForSelector('.wm-modal input[name=subject]')
		await page.fill('.wm-modal input[name=subject]', 'Rendez-vous banque')
		const d = new Date(); d.setDate(d.getDate() + 1)
		const ymd = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0')
		await page.fill('.wm-modal input[name=startDate]', ymd)
		await page.fill('.wm-modal input[name=startTime]', '16:00')
		await page.dispatchEvent('.wm-modal input[name=startTime]', 'change')
		assert.strictEqual(await page.inputValue('.wm-modal input[name=endTime]'), '17:00')
		await page.type('.wm-modal input[name=attendees]', 'paul')
		await page.click('.wm-ac-item:has-text("Paul Durand")')
		await page.fill('.wm-modal input[name=location]', 'Agence centre')
		await shot('12-event-form')
		await page.click('.wm-modal button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("invitations envoyées")')
		await page.click('.wm-segmented button:has-text("Liste")')
		await page.waitForSelector('.wm-agenda-item:has-text("Rendez-vous banque")')
		assert((await page.textContent('.wm-agenda-item:has-text("Rendez-vous banque")')).includes('16:00 – 17:00'))
	})

	await step('calendar: recurring all-day event, delete the series', async () => {
		await page.click('.wm-compose-btn:has-text("Nouvel événement")')
		await page.fill('.wm-modal input[name=subject]', 'Astreinte')
		await page.check('.wm-modal input[name=allDay]')
		assert(await page.isHidden('.wm-modal input[name=startTime]'))
		await page.selectOption('.wm-modal select[name=repeat]', 'daily')
		await page.selectOption('.wm-modal select[name=ends]', 'until')
		// 3 days after the start shown in the form (the browser's day: not the test runner's, which may be in UTC)
		const u = new Date(await page.inputValue('.wm-modal input[name=startDate]') + 'T12:00'); u.setDate(u.getDate() + 3)
		await page.fill('.wm-modal input[name=until]', u.getFullYear() + '-' + String(u.getMonth() + 1).padStart(2, '0') + '-' + String(u.getDate()).padStart(2, '0'))
		await page.click('.wm-modal button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Événement créé")')
		await page.waitForFunction(() => document.querySelectorAll('.wm-agenda-item').length > 0 && [...document.querySelectorAll('.wm-agenda-item')].filter((e) => e.textContent.includes('Astreinte')).length === 4)
		assert((await page.textContent('.wm-agenda-item:has-text("Astreinte")')).includes('Journée'))
		await page.click('.wm-agenda-item:has-text("Astreinte") >> nth=1')
		await page.waitForSelector('.wm-modal button:has-text("Supprimer la série")')
		page.once('dialog', (d) => d.accept())
		await page.click('.wm-modal button:has-text("Supprimer la série")')
		await page.waitForSelector('.wm-toast:has-text("Événement supprimé")')
		await page.waitForFunction(() => ![...document.querySelectorAll('.wm-agenda-item')].some((e) => e.textContent.includes('Astreinte')))
	})

	await step('calendar: answer an invitation from the calendar', async () => {
		await page.click('.wm-agenda-item:has-text("Revue budget")')
		await page.waitForSelector('.wm-modal:has-text("Organisateur : Marie Martin <marie.martin@societe-a.fr>")')
		assert(!(await page.$('.wm-modal button:has-text("Modifier")')), 'attendee cannot edit')
		await page.click('.wm-modal button:has-text("Provisoire")')
		await page.waitForSelector('.wm-toast:has-text("provisoire")')
		await page.waitForFunction(() => [...document.querySelectorAll('.wm-agenda-item')].some((e) => e.textContent.includes('Revue budget') && e.textContent.includes('Provisoire')))
		await shot('13-calendar-list')
	})

	// ------------------------------------------------------------------ week view
	await step('calendar: week view grid', async () => {
		await page.click('.wm-segmented button:has-text("Semaine")')
		await page.waitForSelector('.wm-week-col.today .wm-week-event:has-text("Point hebdo équipe")')
		assert((await page.textContent('.wm-cal-title')).startsWith('Semaine du '))
		assert.strictEqual(await page.$$eval('.wm-week-col', (els) => els.length), 7)
		// 09:00-10:00 => top = 9 h x 48 px, about one hour high
		const ev = page.locator('.wm-week-col.today .wm-week-event:has-text("Point hebdo équipe")')
		assert.strictEqual(await ev.evaluate((e) => e.style.top), '432px')
		const height = await ev.evaluate((e) => parseFloat(e.style.height))
		assert(height > 40 && height < 48, 'height ' + height)
		assert((await ev.textContent()).includes('09:00 – 10:00'))
		assert(await page.isVisible('.wm-week-col.today .wm-now'), 'current time line')
		assert.strictEqual(await page.$$eval('.wm-mini-day.in-week', (els) => els.length), 7)
		// all-day events never go into the hour grid
		assert(!(await page.$('.wm-week-grid .wm-event:has-text("Salon Informatique")')))
		let allDayRow = await page.$$eval('.wm-week-allday .wm-event', (els) => els.map((e) => e.textContent))
		if (!allDayRow.some((t) => t.includes('Salon Informatique'))) {
			await page.click('button[aria-label="Semaine suivante"]')
			await page.waitForSelector('.wm-week-allday .wm-event:has-text("Salon Informatique")')
			await page.click('.wm-cal-toolbar button:has-text("Aujourd\'hui")')
			await page.waitForSelector('.wm-week-col.today .wm-week-event')
		}
		await shot('17-week')
	})

	await step('calendar: week view, click on an empty slot and overlapping events', async () => {
		// a locator is resolved again if the week is re-rendered meanwhile
		await page.locator('.icon-loading-small').waitFor({ state: 'detached' })
		const box = await page.locator('.wm-week-col.today').boundingBox()
		// 08:30 in today's column
		await page.mouse.click(box.x + 8, box.y + 8.5 * 48 + 10)
		await page.waitForSelector('.wm-modal input[name=subject]')
		assert.strictEqual(await page.inputValue('.wm-modal input[name=startTime]'), '08:30')
		assert.strictEqual(await page.inputValue('.wm-modal input[name=endTime]'), '09:30')
		await page.fill('.wm-modal input[name=subject]', 'Café équipe')
		await page.click('.wm-modal button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Événement créé")')
		await page.waitForSelector('.wm-week-col.today .wm-week-event:has-text("Café équipe")')
		const colWidth = (await (await page.$('.wm-week-col.today')).boundingBox()).width
		const a = await (await page.$('.wm-week-col.today .wm-week-event:has-text("Café équipe")')).boundingBox()
		const b = await (await page.$('.wm-week-col.today .wm-week-event:has-text("Point hebdo équipe")')).boundingBox()
		assert(a.width < colWidth * 0.6 && b.width < colWidth * 0.6, 'overlapping events share the column')
		assert(Math.abs(a.x - b.x) > colWidth * 0.3, 'side by side')
		await shot('18-week-overlap')
		await page.click('.wm-week-col.today .wm-week-event:has-text("Café équipe")')
		page.once('dialog', (d) => d.accept())
		await page.click('.wm-modal button:has-text("Supprimer")')
		await page.waitForSelector('.wm-toast:has-text("Événement supprimé")')
		await page.waitForFunction(() => !document.querySelector('.wm-week-col.today .wm-week-event') || ![...document.querySelectorAll('.wm-week-col.today .wm-week-event')].some((e) => e.textContent.includes('Café')))
		// the remaining event takes the full width again
		const full = await (await page.$('.wm-week-col.today .wm-week-event:has-text("Point hebdo équipe")')).boundingBox()
		assert(full.width > colWidth * 0.9)
	})

	await step('calendar: week navigation and mini calendar', async () => {
		const title = await page.textContent('.wm-cal-title')
		await page.click('button[aria-label="Semaine suivante"]')
		await page.waitForFunction((t) => document.querySelector('.wm-cal-title').textContent !== t, title)
		await page.waitForSelector('.wm-week-event:has-text("Sport")') // weekly series
		assert(!(await page.$('.wm-week-col.today')))
		await page.click('.wm-cal-toolbar button:has-text("Aujourd\'hui")')
		await page.waitForSelector('.wm-week-col.today')
		// a day of the mini calendar shows its week
		const target = new Date(); target.setDate(target.getDate() + 14)
		const ymd = target.getFullYear() + '-' + String(target.getMonth() + 1).padStart(2, '0') + '-' + String(target.getDate()).padStart(2, '0')
		if (await page.$('.wm-mini-day[data-date="' + ymd + '"]')) {
			await page.click('.wm-mini-day[data-date="' + ymd + '"]')
			await page.waitForSelector('.wm-week-col[data-date="' + ymd + '"]')
			assert(await page.$('.wm-mini-day.in-week[data-date="' + ymd + '"]'))
		}
		// the chosen view is remembered: back to the list for the next steps
		await page.click('.wm-segmented button:has-text("Liste")')
		await page.waitForSelector('.wm-agenda')
	})

	// ------------------------------------------------------------------ drag and drop
	const dragBy = async (selector, dx, dy) => {
		// the calendar re-renders when loading ends: measure a settled element
		await page.waitForSelector('.wm-cal-toolbar .icon-loading-small', { state: 'detached' })
		const target = page.locator(selector).first()
		await target.waitFor({ state: 'visible' })
		const box = await target.boundingBox()
		const x = box.x + box.width / 2
		const y = box.y + Math.min(12, box.height / 2)
		await page.mouse.move(x, y)
		await page.mouse.down()
		for (let i = 1; i <= 8; i++) await page.mouse.move(x + dx * i / 8, y + dy * i / 8)
		await page.mouse.up()
	}

	await step('calendar: drag an event to another time (meeting: attendees warned)', async () => {
		await page.click('.wm-segmented button:has-text("Semaine")')
		await page.click('.wm-cal-toolbar button:has-text("Aujourd\'hui")')
		const ev = '.wm-week-col.today .wm-week-event:has-text("Point hebdo équipe")'
		await page.waitForSelector(ev)
		assert(await page.$(ev + '.wm-movable'), 'organiser can move')
		let confirmText = ''
		page.once('dialog', (d) => { confirmText = d.message(); d.accept() })
		await dragBy(ev, 0, 48) // one hour later
		await page.waitForSelector('.wm-toast:has-text("Réunion déplacée, participants prévenus")')
		assert(confirmText.includes('Les participants recevront la mise à jour'), confirmText)
		await page.waitForFunction(() => {
			const e = [...document.querySelectorAll('.wm-week-col.today .wm-week-event')].find((x) => x.textContent.includes('Point hebdo'))
			return e && e.style.top === '480px' && e.textContent.includes('10:00 – 11:00')
		})
		// the click that ends the drag does not open the event
		assert(!(await page.$('.wm-modal')))
		await shot('20-drag')
	})

	await step('calendar: resize an event from its bottom edge', async () => {
		const ev = '.wm-week-col.today .wm-week-event:has-text("Point hebdo équipe")'
		page.once('dialog', (d) => d.accept())
		const handle = await (await page.$(ev + ' .wm-resize')).boundingBox()
		await page.mouse.move(handle.x + handle.width / 2, handle.y + handle.height / 2)
		await page.mouse.down()
		for (let i = 1; i <= 6; i++) await page.mouse.move(handle.x + handle.width / 2, handle.y + handle.height / 2 + 24 * i / 6)
		await page.mouse.up()
		await page.waitForSelector('.wm-toast:has-text("10:00 – 11:30")')
		await page.click(ev)
		await page.waitForSelector('.wm-modal .wm-event-when:has-text("10:00 – 11:30")')
		await page.click('.wm-modal-close')
	})

	await step('calendar: drag to another day, Escape cancels', async () => {
		const ev = '.wm-week-col.today .wm-week-event:has-text("Point hebdo équipe")'
		// Escape: nothing changes
		const box = await (await page.$(ev)).boundingBox()
		await page.mouse.move(box.x + 10, box.y + 10)
		await page.mouse.down()
		await page.mouse.move(box.x + 10, box.y + 100, { steps: 5 })
		await page.keyboard.press('Escape')
		await page.mouse.up()
		await page.waitForFunction(() => {
			const e = [...document.querySelectorAll('.wm-week-col.today .wm-week-event')].find((x) => x.textContent.includes('Point hebdo'))
			return e && e.style.top === '480px'
		})
		assert(!(await page.$('.wm-modal')))
		// to the next day (when today is not Sunday)
		const cols = await page.$$('.wm-week-col')
		const todayIndex = await page.$$eval('.wm-week-col', (els) => els.findIndex((e) => e.classList.contains('today')))
		if (todayIndex < 6) {
			const next = await cols[todayIndex + 1].boundingBox()
			const cur = await cols[todayIndex].boundingBox()
			page.once('dialog', (d) => d.accept())
			await dragBy(ev, next.x - cur.x, 0)
			await page.waitForSelector('.wm-toast:has-text("Réunion déplacée")')
			const date = await cols[todayIndex + 1].getAttribute('data-date')
			await page.waitForSelector('.wm-week-col[data-date="' + date + '"] .wm-week-event:has-text("Point hebdo équipe")')
			assert(!(await page.$(ev)))
		}
	})

	await step('calendar: invitations from others cannot be dragged; month view drag', async () => {
		await page.click('.wm-segmented button:has-text("Mois")')
		await page.waitForSelector('.wm-month .wm-event:has-text("Revue budget")')
		await page.waitForSelector('.wm-cal-toolbar .icon-loading-small', { state: 'detached' })
		assert(!(await page.$('.wm-month .wm-event.wm-movable:has-text("Revue budget")')), 'attendee cannot move')
		const chipSel = '.wm-month .wm-event:has-text("Salon Informatique")'
		const firstCell = await page.$eval(chipSel, (e) => e.closest('.wm-month-cell').dataset.date)
		const cells = await page.$$eval('.wm-month-cell', (els) => els.map((e) => e.dataset.date))
		const target = cells[cells.indexOf(firstCell) + 1]
		const from = await (await page.$('.wm-month-cell[data-date="' + firstCell + '"]')).boundingBox()
		const to = await (await page.$('.wm-month-cell[data-date="' + target + '"]')).boundingBox()
		await dragBy(chipSel + ' >> nth=0', to.x - from.x, to.y - from.y) // the next day may be on the next row
		await page.waitForSelector('.wm-toast:has-text("Événement déplacé")')
		await page.waitForFunction((d) => {
			const first = [...document.querySelectorAll('.wm-month-cell')].find((c) => [...c.querySelectorAll('.wm-event')].some((e) => e.textContent.includes('Salon Informatique')))
			return first && first.dataset.date === d
		}, target)
		await shot('21-month-drag')
		await page.click('.wm-segmented button:has-text("Liste")')
		await page.waitForSelector('.wm-agenda')
	})

	// ------------------------------------------------------------------ recurring series
	await step('calendar: edit a whole recurring series', async () => {
		// the previous step moved the calendar two weeks ahead
		await page.click('.wm-cal-toolbar button:has-text("Aujourd\'hui")')
		await page.waitForSelector('.wm-agenda-item:has-text("Point hebdo équipe")')
		await page.click('.wm-agenda-item:has-text("Sport") >> nth=0')
		await page.waitForSelector('.wm-event-rule:has-text("Toutes les semaines le")')
		const ruleText = await page.textContent('.wm-event-rule')
		assert(ruleText.includes('6 fois'), ruleText)
		assert(await page.$('.wm-modal button:has-text("Modifier cette occurrence")'))
		await page.click('.wm-modal button:has-text("Modifier la série")')
		await page.waitForSelector('.wm-modal h2:has-text("Modifier la série")')
		assert.strictEqual(await page.inputValue('.wm-modal select[name=repeat]'), 'weekly')
		assert.strictEqual(await page.inputValue('.wm-modal select[name=ends]'), 'count')
		assert.strictEqual(await page.inputValue('.wm-modal input[name=count]'), '6')
		const startDate = new Date(await page.inputValue('.wm-modal input[name=startDate]') + 'T12:00')
		const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']
		assert(await page.isChecked('.wm-modal input[name="day-' + days[startDate.getDay()] + '"]'))
		await page.fill('.wm-modal input[name=subject]', 'Sport collectif')
		await page.fill('.wm-modal input[name=startTime]', '18:30')
		await page.dispatchEvent('.wm-modal input[name=startTime]', 'change')
		assert.strictEqual(await page.inputValue('.wm-modal input[name=endTime]'), '19:30')
		const second = days[(startDate.getDay() + 2) % 7]
		await page.check('.wm-modal input[name="day-' + second + '"]')
		await page.fill('.wm-modal input[name=count]', '4')
		await page.dispatchEvent('.wm-modal input[name=count]', 'change')
		const summary = await page.textContent('.wm-rule-summary')
		assert(/^Toutes les semaines le \w+ et le \w+, 4 fois$/.test(summary), summary)
		await shot('19-series-form')
		page.once('dialog', (d) => d.accept())
		await page.click('.wm-modal button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Série modifiée")')
		await page.waitForFunction(() => [...document.querySelectorAll('.wm-agenda-item')].filter((e) => e.textContent.includes('Sport collectif')).length === 4)
		const items = await page.$$eval('.wm-agenda-item', (els) => els.map((e) => e.textContent).filter((t) => t.includes('Sport')))
		assert(items.every((t) => t.includes('Sport collectif') && t.includes('18:30 – 19:30')), items.join(' | '))
	})

	await step('calendar: edit a single occurrence only', async () => {
		await page.click('.wm-agenda-item:has-text("Sport collectif") >> nth=1')
		await page.click('.wm-modal button:has-text("Modifier cette occurrence")')
		await page.waitForSelector('.wm-modal p:has-text("Seule cette occurrence sera modifiée")')
		assert(!(await page.$('.wm-modal select[name=repeat]')), 'no rule editor for an occurrence')
		await page.fill('.wm-modal input[name=location]', 'Gymnase B')
		await page.click('.wm-modal button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Événement modifié")')
		await page.waitForFunction(() => [...document.querySelectorAll('.wm-agenda-item')].filter((e) => e.textContent.includes('Gymnase B')).length === 1)
		assert.strictEqual(await page.$$eval('.wm-agenda-item', (els) => els.filter((e) => e.textContent.includes('Sport collectif')).length), 4)
	})

	await step('calendar: every 2nd Tuesday of the month', async () => {
		// the 2nd Tuesday of next month
		const d = new Date(); d.setDate(1); d.setMonth(d.getMonth() + 1)
		while (d.getDay() !== 2) d.setDate(d.getDate() + 1)
		d.setDate(d.getDate() + 7)
		const ymd = (x) => x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0')
		await page.click('.wm-compose-btn:has-text("Nouvel événement")')
		await page.fill('.wm-modal input[name=subject]', 'Comité de direction')
		await page.fill('.wm-modal input[name=startDate]', ymd(d))
		await page.dispatchEvent('.wm-modal input[name=startDate]', 'change')
		await page.selectOption('.wm-modal select[name=repeat]', 'monthly')
		// on the date, or on the weekday
		const choices = await page.$$eval('.wm-modal select[name=repeatBy] option', (els) => els.map((e) => e.textContent))
		assert.deepStrictEqual(choices.slice(0, 2), ['le ' + d.getDate(), 'le 2e mardi'])
		await page.selectOption('.wm-modal select[name=repeatBy]', 'weekday')
		await page.selectOption('.wm-modal select[name=ends]', 'count')
		await page.fill('.wm-modal input[name=count]', '3')
		await page.dispatchEvent('.wm-modal input[name=count]', 'change')
		assert.strictEqual(await page.textContent('.wm-rule-summary'), 'Tous les mois le 2e mardi, 3 fois')
		await shot('51-second-tuesday')
		await page.click('.wm-modal button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Événement créé")')
		// three meetings, each on the 2nd Tuesday of its month
		const found = await page.evaluate(async (from) => {
			const accounts = await (await fetch('/apps/webmail/api/accounts', { headers: { requesttoken: OC.requestToken } })).json()
			const id = accounts.find((a) => a.email === 'user@societe-a.fr').id
			const end = new Date(from); end.setMonth(end.getMonth() + 4)
			const r = await (await fetch('/apps/webmail/api/accounts/' + id + '/calendar/events?tz=Europe/Paris&start=' + from + 'T00:00&end=' + end.toISOString().slice(0, 10) + 'T00:00', { headers: { requesttoken: OC.requestToken } })).json()
			return { id, events: r.events.filter((e) => e.subject === 'Comité de direction').map((e) => ({ id: e.id, start: e.start })) }
		}, ymd(d))
		assert.strictEqual(found.events.length, 3, JSON.stringify(found.events))
		found.events.forEach((e) => {
			const s = new Date(e.start)
			assert(s.getDay() === 2 && s.getDate() > 7 && s.getDate() <= 14, e.start)
		})
		// shown as such, and kept when the series is edited
		await page.goto(NC + '/apps/webmail/?' + new URLSearchParams({ account: found.id, module: 'calendar', event: found.events[1].id }))
		await page.waitForSelector('.wm-modal .wm-event-rule:has-text("Tous les mois le 2e mardi, 3 fois")')
		await page.click('.wm-modal button:has-text("Modifier la série")')
		await page.waitForSelector('.wm-modal h2:has-text("Modifier la série")')
		assert.strictEqual(await page.inputValue('.wm-modal select[name=repeat]'), 'monthly')
		assert.strictEqual(await page.inputValue('.wm-modal select[name=repeatBy]'), 'weekday')
		await page.click('.wm-modal-close')
		await page.click('.wm-cal-toolbar button:has-text("Aujourd\'hui")')
	})

	await step('calendar: a Nextcloud Talk video room for a meeting', async () => {
		// Talk is not installed in the test Nextcloud: its API (create a public conversation) is answered here
		let asked = null
		await page.route('**/ocs/v2.php/apps/spreed/api/v4/room**', async (route) => {
			asked = { method: route.request().method(), body: JSON.parse(route.request().postData() || '{}'), ocs: route.request().headers()['ocs-apirequest'] }
			await route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ ocs: { meta: { status: 'ok', statuscode: 201 }, data: { token: 'k3tq7wzp', type: 3 } } }) })
		})
		await page.evaluate(() => { document.getElementById('webmail-app').dataset.talk = '1' })
		await page.click('.wm-compose-btn:has-text("Nouvel événement")')
		await page.fill('.wm-modal input[name=subject]', 'Point visio client')
		await page.click('.wm-modal .wm-talk-btn')
		await page.waitForSelector('.wm-toast:has-text("Salle de visio créée")')
		assert.deepStrictEqual(asked, { method: 'POST', body: { roomType: 3, roomName: 'Point visio client' }, ocs: 'true' })
		const url = await page.inputValue('.wm-modal input[name=location]')
		assert(new RegExp('^' + NC.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '/(index\\.php/)?call/k3tq7wzp$').test(url), url)
		assert.strictEqual(await page.inputValue('.wm-modal textarea[name=body]'), 'Rejoindre la visio Nextcloud Talk : ' + url)
		assert(await page.isHidden('.wm-modal .wm-talk-btn'), 'one room per event')
		await shot('54-talk')
		await page.click('.wm-modal button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Événement créé")')
		// the event: a button to join
		const created = await page.evaluate(async () => {
			const accounts = await (await fetch('/apps/webmail/api/accounts', { headers: { requesttoken: OC.requestToken } })).json()
			const id = accounts.find((a) => a.email === 'user@societe-a.fr').id
			const from = new Date(Date.now() - 86400000).toISOString().slice(0, 16)
			const to = new Date(Date.now() + 3 * 86400000).toISOString().slice(0, 16)
			const r = await (await fetch('/apps/webmail/api/accounts/' + id + '/calendar/events?tz=UTC&start=' + from + '&end=' + to, { headers: { requesttoken: OC.requestToken } })).json()
			return { account: id, event: r.events.find((e) => e.subject === 'Point visio client').id }
		})
		await page.goto(NC + '/apps/webmail/?' + new URLSearchParams({ account: created.account, module: 'calendar', event: created.event }))
		await page.waitForSelector('.wm-modal .wm-join-btn:has-text("Rejoindre la visio Nextcloud Talk")')
		assert.strictEqual(await page.getAttribute('.wm-modal .wm-join-btn', 'href'), url)
		await page.click('.wm-modal-close')
		// without Talk: no button
		await page.click('.wm-compose-btn:has-text("Nouvel événement")')
		assert(!(await page.$('.wm-modal .wm-talk-btn')), 'Talk not installed')
		await page.click('.wm-modal-close')
		await page.unroute('**/ocs/v2.php/apps/spreed/api/v4/room**')
		await page.evaluate(async (c) => {
			await fetch('/apps/webmail/api/accounts/' + c.account + '/calendar/delete', {
				method: 'POST',
				headers: { requesttoken: OC.requestToken, 'Content-Type': 'application/json' },
				body: JSON.stringify({ eventId: c.event }),
			})
		}, created)
		await page.click('.wm-cal-toolbar button:has-text("Aujourd\'hui")')
	})

	await step('calendar: a new event drawn with the mouse over its time in the week grid', async () => {
		await page.click('.wm-segmented button:has-text("Semaine")')
		await page.waitForSelector('.wm-week-col')
		const ymd = (x) => x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0')
		const col = '.wm-week-col[data-date="' + ymd(new Date()) + '"]'
		const pad = (n) => String(n).padStart(2, '0')
		// the first hours seen in the grid (it opens scrolled to the working day), where nothing is planned today
		// the week is drawn again when its events arrive: measured once it is there for good
		await page.waitForFunction(() => !document.querySelector('.wm-cal-toolbar .icon-loading-small'))
		let box = null
		let view = null
		for (let i = 0; i < 30 && !(box && view); i++) {
			await page.waitForTimeout(100)
			box = await page.locator(col).boundingBox()
			view = await page.locator('.wm-week-scroll').boundingBox()
		}
		assert.ok(box && view, 'week grid shown')
		const busy = await page.$$eval(col + ' .wm-week-event', (els) => els.map((e) => [parseFloat(e.style.top), parseFloat(e.style.top) + parseFloat(e.style.height)]))
		let hour = Math.ceil((view.y - box.y) / 48) + 1
		while (busy.some(([t, b]) => t < (hour + 2) * 48 && b > hour * 48)) hour++
		const x = box.x + box.width / 2
		await page.mouse.move(x, box.y + hour * 48 + 3)
		await page.mouse.down()
		await page.mouse.move(x, box.y + (hour + 0.5) * 48, { steps: 4 })
		await page.mouse.move(x, box.y + (hour + 1.5) * 48 - 2, { steps: 6 })
		const from = pad(hour) + ':00'
		const to = pad(hour + 1) + ':30'
		await page.waitForSelector(col + ' .wm-slot-ghost:has-text("' + from + ' – ' + to + '")')
		await shot('82-draw-slot')
		await page.mouse.up()
		await page.waitForSelector('.wm-modal input[name=startTime]')
		assert.strictEqual(await page.inputValue('.wm-modal input[name=startTime]'), from)
		assert.strictEqual(await page.inputValue('.wm-modal input[name=endTime]'), to)
		assert.strictEqual(await page.inputValue('.wm-modal input[name=startDate]'), ymd(new Date()))
		assert.strictEqual(await page.locator('.wm-slot-ghost').count(), 0)
		await page.click('.wm-modal-close')
		// a plain click still opens a half hour
		await page.mouse.click(x, box.y + hour * 48 + 30)
		await page.waitForSelector('.wm-modal input[name=startTime]')
		assert.strictEqual(await page.inputValue('.wm-modal input[name=startTime]'), pad(hour) + ':30')
		assert.strictEqual(await page.inputValue('.wm-modal input[name=endTime]'), pad(hour + 1) + ':30')
		await page.click('.wm-modal-close')
	})

	await step('calendar: an Element (Matrix) video room set by the administrator, instead of Talk', async () => {
		const setVisio = async (value) => {
			await page.goto(NC + '/settings/admin/webmail')
			await page.fill('#webmail-visio', value)
			await page.click('#webmail-save')
			await page.waitForSelector('#webmail-admin-msg.success')
		}
		// a wrong address is refused
		await page.goto(NC + '/settings/admin/webmail')
		await page.fill('#webmail-visio', 'http://call.element.io')
		await page.click('#webmail-save')
		await page.waitForSelector('#webmail-admin-msg.error:has-text("Adresse de visio invalide")')
		await setVisio('https://call.element.io/')
		assert.strictEqual(await page.inputValue('#webmail-visio'), 'https://call.element.io')

		await page.goto(NC + '/apps/webmail/?module=calendar')
		await page.waitForSelector('.wm-compose-btn:has-text("Nouvel événement")')
		// (even with Talk installed, the visio chosen by the administrator)
		await page.evaluate(() => { document.getElementById('webmail-app').dataset.talk = '1' })
		await page.click('.wm-compose-btn:has-text("Nouvel événement")')
		await page.fill('.wm-modal input[name=subject]', 'Point de chantier à Nice')
		assert(!(await page.$('.wm-modal .wm-talk-btn')), 'no Talk button')
		await page.click('.wm-modal .wm-visio-btn:has-text("Visio Element")')
		await page.waitForSelector('.wm-toast:has-text("Salle de visio ajoutée")')
		const url = await page.inputValue('.wm-modal input[name=location]')
		assert.match(url, /^https:\/\/call\.element\.io\/room\/#\/point-de-chantier-a-nice-[a-z0-9]{6}\?password=[A-Za-z0-9]{24}$/)
		assert.strictEqual(await page.inputValue('.wm-modal textarea[name=body]'), 'Rejoindre la visio : ' + url)
		assert(await page.isHidden('.wm-modal .wm-visio-btn'), 'one room per event')
		await shot('77-element-visio')
		await page.click('.wm-modal button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Événement créé")')
		const created = await page.evaluate(async () => {
			const accounts = await (await fetch('/apps/webmail/api/accounts', { headers: { requesttoken: OC.requestToken } })).json()
			const id = accounts.find((a) => a.email === 'user@societe-a.fr').id
			const from = new Date(Date.now() - 86400000).toISOString().slice(0, 16)
			const to = new Date(Date.now() + 3 * 86400000).toISOString().slice(0, 16)
			const r = await (await fetch('/apps/webmail/api/accounts/' + id + '/calendar/events?tz=UTC&start=' + from + '&end=' + to, { headers: { requesttoken: OC.requestToken } })).json()
			return { account: id, event: r.events.find((e) => e.subject === 'Point de chantier à Nice').id }
		})
		await page.goto(NC + '/apps/webmail/?' + new URLSearchParams({ account: created.account, module: 'calendar', event: created.event }))
		await page.waitForSelector('.wm-modal .wm-join-btn:has-text("Rejoindre la visio")')
		assert.strictEqual(await page.getAttribute('.wm-modal .wm-join-btn', 'href'), url)
		await page.click('.wm-modal-close')
		await page.evaluate(async (c) => {
			await fetch('/apps/webmail/api/accounts/' + c.account + '/calendar/delete', {
				method: 'POST',
				headers: { requesttoken: OC.requestToken, 'Content-Type': 'application/json' },
				body: JSON.stringify({ eventId: c.event }),
			})
		}, created)

		// another service (Jitsi…): the room name in its address
		await setVisio('https://meet.example.org/{room}')
		await page.goto(NC + '/apps/webmail/?module=calendar')
		await page.click('.wm-compose-btn:has-text("Nouvel événement")')
		await page.fill('.wm-modal input[name=subject]', 'Revue')
		await page.click('.wm-modal .wm-visio-btn:text-is("🎥 Visio")')
		assert.match(await page.inputValue('.wm-modal input[name=location]'), /^https:\/\/meet\.example\.org\/revue-[a-z0-9]{6}$/)
		await page.click('.wm-modal-close')

		// none: no button
		await setVisio('')
		await page.goto(NC + '/apps/webmail/?module=calendar')
		await page.click('.wm-compose-btn:has-text("Nouvel événement")')
		assert(!(await page.$('.wm-modal .wm-visio-btn')))
		await page.click('.wm-modal-close')
		await page.click('.wm-cal-toolbar button:has-text("Aujourd\'hui")')
	})

	await step('calendar: scheduling assistant, first free slot for all', async () => {
		const ymd = (x) => x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0')
		await page.click('.wm-compose-btn:has-text("Nouvel événement")')
		await page.fill('.wm-modal input[name=subject]', 'Réunion projet')
		await page.fill('.wm-modal input[name=attendees]', 'paul.durand@societe-a.fr, client@exterieur.example')
		await page.fill('.wm-modal input[name=startDate]', ymd(new Date()))
		await page.dispatchEvent('.wm-modal input[name=startDate]', 'change')
		await page.fill('.wm-modal input[name=startTime]', '10:30')
		await page.dispatchEvent('.wm-modal input[name=startTime]', 'change')
		await page.click('.wm-modal .wm-plan-btn')
		// a row per person: me, Paul (busy this morning), someone outside (unknown)
		await page.waitForSelector('.wm-plan-row[data-email="paul.durand@societe-a.fr"] .wm-plan-busy')
		const who = await page.$$eval('.wm-plan-row[data-email] .wm-plan-who', (els) => els.map((e) => e.textContent))
		assert.deepStrictEqual(who, ['Moi', 'paul.durand@societe-a.fr', 'client@exterieur.example'])
		await page.waitForSelector('.wm-plan-row[data-email="client@exterieur.example"] .wm-plan-unknown:has-text("disponibilités inconnues")')
		await page.waitForSelector('.wm-plan-row[data-email="paul.durand@societe-a.fr"] .wm-plan-meeting')
		await shot('56-scheduling')
		// the first moment everybody is free: the meeting moves there, as long as it was
		const before = await page.inputValue('.wm-modal input[name=startTime]')
		await page.click('.wm-plan-suggest')
		await page.waitForSelector('.wm-toast:has-text("Réunion placée")')
		const s = new Date(await page.inputValue('.wm-modal input[name=startDate]') + 'T' + await page.inputValue('.wm-modal input[name=startTime]'))
		const e = new Date(await page.inputValue('.wm-modal input[name=endDate]') + 'T' + await page.inputValue('.wm-modal input[name=endTime]'))
		assert.strictEqual(e - s, 3600000)
		assert(s.getDay() >= 1 && s.getDay() <= 5 && s.getHours() >= 8 && s.getHours() < 18, String(s))
		assert(!(await page.inputValue('.wm-modal input[name=startTime]') === before && ymd(s) === ymd(new Date())), 'moved')
		assert(s > new Date(), 'not in the past: ' + s)
		// the new time is free for everybody known
		await page.waitForSelector('.wm-plan-meeting')
		await page.click('.wm-modal button:has-text("Annuler")')
	})

	await step('calendar: a calendar of the public folders', async () => {
		const ymd = (x) => x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0')
		await page.click('.wm-public-cal-add')
		await page.waitForSelector('.wm-modal h2:has-text("Ajouter un agenda des dossiers publics")')
		await page.click('.wm-modal .wm-pf-open:has-text("Société A")')
		const row = '.wm-modal .wm-pf-folder[data-name="Agenda commun"]'
		await page.waitForSelector(row + ' .wm-cal-mode:has-text("modification")')
		// only calendars are offered there
		assert(!(await page.$('.wm-modal .wm-pf-folder[data-name="Clients communs"] button')), 'an address book is not a calendar')
		await page.click(row + ' button:has-text("Ajouter")')
		await page.waitForSelector('.wm-toast:has-text("« Agenda commun » ajouté (modification)")')
		await page.waitForSelector('.wm-cal-row:has-text("Agenda commun") .wm-cal-mode:has-text("modification")')
		await page.click('.wm-segmented button:has-text("Liste")')
		await page.waitForSelector('.wm-agenda-item:has-text("Inventaire annuel")')
		// an event added to it
		const d = new Date(); d.setDate(d.getDate() + 3)
		await page.click('.wm-compose-btn:has-text("Nouvel événement")')
		await page.fill('.wm-modal input[name=subject]', 'Journée portes ouvertes')
		await page.selectOption('.wm-modal select[name=calendar]', { label: 'Agenda commun' })
		await page.fill('.wm-modal input[name=startDate]', ymd(d))
		await page.dispatchEvent('.wm-modal input[name=startDate]', 'change')
		await page.fill('.wm-modal input[name=startTime]', '10:00')
		await page.dispatchEvent('.wm-modal input[name=startTime]', 'change')
		await page.click('.wm-modal button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Événement créé")')
		await page.waitForSelector('.wm-agenda-item:has-text("Journée portes ouvertes")')
		await shot('57-public-calendar')
		// opened and deleted there (for good: public folders have no « Éléments supprimés »)
		await page.click('.wm-agenda-item:has-text("Journée portes ouvertes")')
		await page.waitForSelector('.wm-modal .wm-event-when')
		assert((await page.textContent('.wm-modal')).includes('Agenda commun'))
		page.once('dialog', (dlg) => dlg.accept())
		await page.click('.wm-modal button:has-text("🗑 Supprimer")')
		await page.waitForSelector('.wm-toast:has-text("Événement supprimé")')
		await page.waitForSelector('.wm-agenda-item:has-text("Journée portes ouvertes")', { state: 'detached' })
		// removed from the list: its events go away, nothing is deleted
		page.once('dialog', (dlg) => dlg.accept())
		await page.click('.wm-cal-row:has-text("Agenda commun") .wm-cal-remove')
		await page.waitForSelector('.wm-toast:has-text("Agenda retiré")')
		await page.waitForSelector('.wm-agenda-item:has-text("Inventaire annuel")', { state: 'detached' })
	})

	await step('calendar: book a meeting room of the room lists', async () => {
		const ymd = (x) => x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0')
		const d = new Date(); d.setDate(d.getDate() + 2)
		await page.click('.wm-compose-btn:has-text("Nouvel événement")')
		await page.fill('.wm-modal input[name=subject]', 'Atelier devis')
		await page.fill('.wm-modal input[name=startDate]', ymd(d))
		await page.dispatchEvent('.wm-modal input[name=startDate]', 'change')
		await page.fill('.wm-modal input[name=startTime]', '14:00')
		await page.dispatchEvent('.wm-modal input[name=startTime]', 'change')
		await page.click('.wm-modal .wm-room-btn')
		// the room lists of the organisation; in Paris, free rooms first, Mistral is taken
		await page.waitForSelector('.wm-modal .wm-room[data-email="fourviere@societe-a.fr"]')
		await page.selectOption('.wm-modal select[name=roomList]', { label: 'Salles Paris' })
		await page.waitForSelector('.wm-modal .wm-room[data-email="mistral@societe-a.fr"].taken .wm-room-state:has-text("occupée")')
		await page.waitForSelector('.wm-modal .wm-room[data-email="alize@societe-a.fr"] .wm-room-state:has-text("libre")')
		const names = await page.$$eval('.wm-modal .wm-room .wm-room-name', (els) => els.map((e) => e.textContent))
		assert.strictEqual(names[names.length - 1], '🚪 Salle Mistral (8 places)')
		assert.deepStrictEqual(await page.$$eval('.wm-modal select[name=roomList] option', (els) => els.map((e) => e.textContent)), ['Salles Lyon', 'Salles Paris'])
		await shot('59-rooms')
		await page.click('.wm-modal .wm-room[data-email="alize@societe-a.fr"] button:has-text("Réserver")')
		await page.waitForSelector('.wm-modal .wm-room-chip:has-text("Salle Alizé (4 places)")')
		assert.strictEqual(await page.inputValue('.wm-modal input[name=location]'), 'Salle Alizé (4 places)')
		// the room is in the scheduling assistant too
		await page.click('.wm-modal .wm-plan-btn')
		await page.waitForSelector('.wm-plan-row[data-email="alize@societe-a.fr"] .wm-plan-who:has-text("🚪 Salle Alizé")')
		await page.click('.wm-modal button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Événement créé")')
		// the room accepted
		await page.click('.wm-agenda-item:has-text("Atelier devis")')
		await page.waitForSelector('.wm-modal .wm-event-rooms:has-text("Salle Alizé (4 places) — réservée")')
		// the room is now busy at that time
		await page.click('.wm-modal button:has-text("Modifier")')
		await page.waitForSelector('.wm-modal .wm-room-chip:has-text("Salle Alizé")')
		await page.click('.wm-modal .wm-room-btn')
		await page.waitForSelector('.wm-modal .wm-room[data-email="alize@societe-a.fr"] .wm-muted:has-text("choisie")')
		// the list chosen last time
		assert.strictEqual(await page.$eval('.wm-modal select[name=roomList]', (s) => s.selectedOptions[0].textContent), 'Salles Paris')
		await page.waitForSelector('.wm-modal .wm-room[data-email="sirocco@societe-a.fr"] .wm-room-state:has-text("libre")')
		// another room instead: Alizé removed, Sirocco booked
		await page.click('.wm-modal .wm-room-chip:has-text("Salle Alizé") .wm-chip-remove')
		await page.click('.wm-modal .wm-room[data-email="sirocco@societe-a.fr"] button:has-text("Réserver")')
		assert.strictEqual(await page.inputValue('.wm-modal input[name=location]'), 'Salle Sirocco (12 places)')
		await page.click('.wm-modal button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Événement modifié")')
		await page.click('.wm-agenda-item:has-text("Atelier devis")')
		await page.waitForSelector('.wm-modal .wm-event-rooms:has-text("Salle Sirocco (12 places) — réservée")')
		assert(!(await page.textContent('.wm-modal .wm-event-rooms')).includes('Alizé'))
		page.once('dialog', (dlg) => dlg.accept())
		await page.click('.wm-modal button:has-text("🗑 Supprimer")')
		await page.waitForSelector('.wm-toast:has-text("Événement supprimé")')
		await page.waitForSelector('.wm-agenda-item:has-text("Atelier devis")', { state: 'detached' })
	})

	await step('calendar: a series can become a single event', async () => {
		await page.click('.wm-agenda-item:has-text("Sport collectif") >> nth=0')
		await page.click('.wm-modal button:has-text("Modifier la série")')
		await page.waitForSelector('.wm-modal h2:has-text("Modifier la série")')
		await page.selectOption('.wm-modal select[name=repeat]', '')
		assert(await page.isHidden('.wm-modal select[name=ends]'))
		page.once('dialog', (d) => d.accept())
		await page.click('.wm-modal button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Série modifiée")')
		await page.waitForFunction(() => [...document.querySelectorAll('.wm-agenda-item')].filter((e) => e.textContent.includes('Sport collectif')).length === 1)
		await page.click('.wm-agenda-item:has-text("Sport collectif")')
		await page.waitForSelector('.wm-modal .wm-event-when')
		assert(!(await page.$('.wm-modal .wm-event-rule')), 'no longer recurring')
		assert(!(await page.$('.wm-modal button:has-text("Modifier la série")')))
		await page.click('.wm-modal-close')
	})

	// ------------------------------------------------------------------ shared calendars
	await step('calendar: own calendars, colours and visibility', async () => {
		await page.click('.wm-cal-toolbar button:has-text("Aujourd\'hui")')
		const own = await page.$$eval('.wm-cal-row .wm-cal-name', (els) => els.map((e) => e.textContent))
		assert.deepStrictEqual(own, ['Calendrier', 'Congés équipe'])
		await page.waitForSelector('.wm-agenda-item:has-text("Congés Lucas")')
		await page.click('.wm-segmented button:has-text("Mois")')
		const chip = page.locator('.wm-month .wm-event:has-text("Congés Lucas")').first()
		await chip.waitFor()
		assert((await chip.getAttribute('style')).includes('--ev-color'), 'secondary calendar has its own colour')
		await page.uncheck('.wm-cal-row:has-text("Congés équipe") input')
		await page.waitForFunction(() => ![...document.querySelectorAll('.wm-month .wm-event')].some((e) => e.textContent.includes('Congés Lucas')))
		await page.check('.wm-cal-row:has-text("Congés équipe") input')
		await page.waitForSelector('.wm-month .wm-event:has-text("Congés Lucas")')
	})

	await step('calendar: add shared calendars (edit, read, availability only)', async () => {
		const add = async (email) => {
			await page.click('.wm-cal-add')
			await page.fill('.wm-modal input[name=sharedEmail]', email)
			await page.click('.wm-modal button[type=submit]')
		}
		await add('inconnu@societe-a.fr')
		await page.waitForSelector('.wm-modal .wm-form-error:has-text("Aucune boîte Exchange ne correspond")')
		await page.click('.wm-modal-close')
		await add('marie.martin@societe-a.fr')
		await page.waitForSelector('.wm-toast:has-text("Agenda de Marie Martin ajouté (modification)")')
		await add('martine.leroy@societe-b.fr')
		await page.waitForSelector('.wm-toast:has-text("Agenda de Martine Leroy ajouté (lecture)")')
		await add('paul.durand@societe-a.fr')
		await page.waitForSelector('.wm-toast:has-text("Agenda de Paul Durand ajouté (disponibilités seulement)")')
		await page.waitForSelector('.wm-cal-row:has-text("Paul Durand")')
		const rows = await page.$$eval('.wm-cal-row', (els) => els.map((e) => e.textContent.replace('✕', '').replace('🔗', '').trim()))
		assert.deepStrictEqual(rows, ['Calendrier', 'Congés équipe', 'Marie Martinmodification', 'Martine Leroylecture', 'Paul Duranddisponibilités'])
		await page.click('.wm-segmented button:has-text("Semaine")')
		await page.waitForSelector('.wm-week-event:has-text("Inventaire")')
		await page.waitForSelector('.wm-week-event.freebusy:has-text("Rendez-vous fournisseur")')
		assert(!(await page.$('.wm-week-event.wm-movable:has-text("Inventaire")')), 'read-only calendar: not draggable')
		assert(!(await page.$('.wm-week-event.wm-movable:has-text("Rendez-vous fournisseur")')))
		await shot('22-shared-week')
	})

	await step('calendar: permissions of each shared calendar', async () => {
		await page.click('.wm-week-event:has-text("Inventaire")')
		await page.waitForSelector('.wm-modal:has-text("Agenda de Martine Leroy")')
		await page.waitForSelector('.wm-modal:has-text("lecture seule")')
		assert(!(await page.$('.wm-modal button:has-text("Modifier")')))
		assert(!(await page.$('.wm-modal button:has-text("Supprimer")')))
		await page.click('.wm-modal-close')

		await page.click('.wm-week-event:has-text("Rendez-vous fournisseur")')
		await page.waitForSelector('.wm-modal:has-text("ne partage que ses disponibilités")')
		await page.click('.wm-modal-close')

		await page.click('.wm-segmented button:has-text("Liste")')
		await page.click('.wm-agenda-item:has-text("Visite client Antibes")')
		await page.waitForSelector('.wm-modal:has-text("Agenda de Marie Martin")')
		await page.click('.wm-modal button:has-text("Modifier")')
		await page.fill('.wm-modal input[name=location]', 'Antibes, agence port')
		await page.click('.wm-modal button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Événement modifié")')
		await page.waitForSelector('.wm-agenda-item:has-text("Antibes, agence port")')
	})

	await step('calendar: create an event in a shared calendar', async () => {
		await page.click('.wm-compose-btn:has-text("Nouvel événement")')
		const options = await page.$$eval('.wm-modal select[name=calendar] option', (els) => els.map((e) => e.textContent))
		assert.deepStrictEqual(options, ['Calendrier', 'Congés équipe', 'Agenda de Marie Martin'], 'read-only calendars are not offered')
		await page.selectOption('.wm-modal select[name=calendar]', { label: 'Agenda de Marie Martin' })
		await page.fill('.wm-modal input[name=subject]', 'Préparer le salon (pour Marie)')
		await page.click('.wm-modal button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Événement créé")')
		await page.click('.wm-agenda-item:has-text("Préparer le salon")')
		await page.waitForSelector('.wm-modal:has-text("Agenda de Marie Martin")')
		await page.click('.wm-modal-close')
	})

	await step('calendar: answer on behalf of a colleague (edit rights only)', async () => {
		// the invitation accepted from the inbox shows up in Marie's calendar
		await page.waitForSelector('.wm-agenda-item:has-text("Revue trimestrielle")')
		assert((await page.textContent('.wm-agenda-item:has-text("Revue trimestrielle")')).includes('Accepté'))

		await page.click('.wm-agenda-item:has-text("Revue fournisseurs")')
		await page.waitForSelector('.wm-modal:has-text("Réponse de Marie Martin : Pas de réponse")')
		page.once('dialog', (d) => d.accept())
		await page.click('.wm-modal button:has-text("Provisoire")')
		await page.waitForSelector('.wm-toast:has-text("Réponse envoyée au nom de Marie Martin : provisoire")')
		await page.click('.wm-agenda-item:has-text("Revue fournisseurs")')
		await page.waitForSelector('.wm-modal:has-text("Réponse de Marie Martin : Provisoire")')
		await shot('26-delegate-calendar')
		await page.click('.wm-modal-close')

		// read-only calendar: no answer possible
		await page.click('.wm-agenda-item:has-text("Point transport")')
		await page.waitForSelector('.wm-modal:has-text("lecture seule")')
		assert(!(await page.$('.wm-modal button:has-text("Accepter")')), 'no answer on behalf with read-only access')
		await page.click('.wm-modal-close')
	})

	await step('calendar: remove a shared calendar', async () => {
		page.once('dialog', (d) => d.accept())
		await page.click('.wm-cal-row:has-text("Paul Durand") .wm-cal-remove')
		await page.waitForSelector('.wm-toast:has-text("Agenda retiré")')
		await page.waitForFunction(() => ![...document.querySelectorAll('.wm-cal-row')].some((e) => e.textContent.includes('Paul Durand')))
		await page.waitForFunction(() => ![...document.querySelectorAll('.wm-agenda-item')].some((e) => e.textContent.includes('Rendez-vous fournisseur')))
	})

	await step('calendar: day view and small calendar', async () => {
		await page.click('.wm-tab[data-module="calendar"]')
		await page.waitForSelector('.wm-cal-toolbar')
		const before = await page.textContent('.wm-segmented button.active')
		const ymd = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0')
		const tomorrow = new Date(Date.now() + 86400000)
		await page.click('.wm-cal-toolbar button:has-text("Aujourd\'hui")')
		await page.click('.wm-segmented button:has-text("Jour")')
		await page.waitForSelector('.wm-week.wm-day-view')
		assert.strictEqual(await page.$$eval('.wm-week-col', (els) => els.length), 1, 'one column')
		const today = await page.textContent('.wm-cal-title')
		assert(today.includes(String(new Date().getDate())), today)
		// next day: its events
		await page.click('.wm-cal-toolbar button[aria-label="Jour suivant"]')
		await page.waitForSelector('.wm-week-col[data-date="' + ymd(tomorrow) + '"]')
		await page.waitForSelector('.wm-day-view .wm-week-event:has-text("Point hebdo équipe")')
		await page.waitForFunction(() => !document.querySelector('.wm-cal-toolbar .icon-loading-small'))
		// the small calendar: a dot under the days with events, its own arrows
		await page.waitForSelector('.wm-mini-day.has-events[data-date="' + ymd(tomorrow) + '"]')
		await shot('41-day-view')
		const shownMonth = await page.textContent('.wm-mini-title span')
		await page.click('.wm-mini-nav[aria-label="Mois suivant"]')
		await page.waitForFunction((m) => document.querySelector('.wm-mini-title span').textContent !== m, shownMonth)
		assert(await page.$('.wm-week-col[data-date="' + ymd(tomorrow) + '"]'), 'the main view did not move')
		await page.click('.wm-mini-nav[aria-label="Mois précédent"]')
		// double-click a day of the small calendar: that day alone
		await page.click('.wm-segmented button:has-text("Semaine")')
		await page.waitForSelector('.wm-week:not(.wm-day-view)')
		await page.dblclick('.wm-mini-day[data-date="' + ymd(new Date()) + '"]')
		await page.waitForSelector('.wm-week.wm-day-view .wm-week-col[data-date="' + ymd(new Date()) + '"]')
		// back to the view used before
		await page.click('.wm-segmented button:has-text("' + before.trim() + '")')
	})

	await step('calendar: searching events', async () => {
		await page.fill('.wm-cal-search', 'EQUIPE')
		await page.press('.wm-cal-search', 'Enter')
		await page.waitForSelector('.wm-cal-results .wm-search-info:has-text("Événements contenant « EQUIPE »")')
		await page.waitForSelector('.wm-cal-results .wm-agenda-item')
		// accents and capitals do not matter
		const subjects = await page.$$eval('.wm-cal-results .wm-agenda-subject', (els) => els.map((e) => e.firstChild.textContent))
		assert(subjects.length >= 1 && subjects.every((t) => /équipe/i.test(t)), subjects.join('|'))
		await shot('44-event-search')
		await page.click('.wm-cal-results .wm-agenda-item >> nth=0')
		await page.waitForSelector('.wm-modal .wm-event-when')
		await page.click('.wm-modal-close')
		await page.fill('.wm-cal-search', 'zzz introuvable')
		await page.press('.wm-cal-search', 'Enter')
		await page.waitForSelector('.wm-cal-results .wm-empty:has-text("Aucun événement trouvé")')
		await page.click('.wm-cal-results a:has-text("revenir à l\'agenda")')
		await page.waitForSelector('.wm-cal-results', { state: 'detached' })
	})

	await step('calendar: share my calendar without delegation', async () => {
		const openShare = async (name) => {
			await page.click('.wm-cal-row:has-text("' + name + '") .wm-cal-share')
			await page.waitForSelector('.wm-modal h2:has-text("Partager « ' + name + ' »")')
			await page.waitForSelector('.wm-share-row')
		}
		const row = (user) => '.wm-share-row[data-user="' + user + '"]'
		await openShare('Calendrier')
		assert.strictEqual(await page.inputValue(row('Default') + ' select'), 'FreeBusyTimeOnly')
		// a custom level set in Outlook is shown and kept as it is
		assert.strictEqual(await page.inputValue(row('paul.durand@societe-a.fr') + ' select'), 'keep')
		assert((await page.textContent(row('paul.durand@societe-a.fr'))).includes('Autre (défini dans Outlook)'))

		await page.selectOption(row('Default') + ' select', 'FreeBusyTimeAndSubjectAndLocation')
		await page.type('.wm-modal input[name=shareEmail]', 'marie')
		await page.click('.wm-ac-item:has-text("Marie Martin")')
		await page.click('.wm-share-add button:has-text("Ajouter")')
		await page.waitForSelector(row('marie.martin@societe-a.fr'))
		assert.strictEqual(await page.inputValue(row('marie.martin@societe-a.fr') + ' select'), 'Reviewer')

		// unknown person: refused by Exchange, the dialog stays open
		await page.fill('.wm-modal input[name=shareEmail]', 'inconnu@societe-a.fr')
		await page.selectOption('.wm-modal select[name=newLevel]', 'Editor')
		await page.click('.wm-share-add button:has-text("Ajouter")')
		await page.click('.wm-modal button[type=submit]')
		await page.waitForSelector('.wm-modal .wm-form-error:has-text("n\'a pas de boîte Exchange")')
		await page.click(row('inconnu@societe-a.fr') + ' .wm-cal-remove')
		await shot('28-share-calendar')
		await page.click('.wm-modal button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Partage de « Calendrier » enregistré")')
		await page.waitForSelector('.wm-modal', { state: 'detached' })

		await openShare('Calendrier')
		assert.strictEqual(await page.inputValue(row('Default') + ' select'), 'FreeBusyTimeAndSubjectAndLocation')
		assert.strictEqual(await page.inputValue(row('marie.martin@societe-a.fr') + ' select'), 'Reviewer')
		assert.strictEqual(await page.inputValue(row('paul.durand@societe-a.fr') + ' select'), 'keep', 'custom level untouched')
		// stop sharing with Marie
		await page.click(row('marie.martin@societe-a.fr') + ' .wm-cal-remove')
		await page.click('.wm-modal button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Partage de « Calendrier » enregistré")')
		await openShare('Calendrier')
		assert(!(await page.$(row('marie.martin@societe-a.fr'))))
		await page.click('.wm-modal-close')

		// a secondary calendar can be shared too
		await openShare('Congés équipe')
		assert.strictEqual(await page.inputValue(row('Default') + ' select'), 'None')
		await page.selectOption(row('Default') + ' select', 'Reviewer')
		await page.click('.wm-modal button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Partage de « Congés équipe » enregistré")')
		// shared calendars of colleagues have no share button
		assert(!(await page.$('.wm-cal-row:has-text("Marie Martin") .wm-cal-share')))
	})

	// ------------------------------------------------------------------ contacts
	await step('contacts: list, search, details', async () => {
		await page.click('.wm-tab[data-module="contacts"]')
		await page.waitForSelector('.wm-contact:has-text("Jean Dupont")')
		const names = await page.$$eval('.wm-contact-name', (els) => els.map((e) => e.textContent))
		assert.deepStrictEqual(names, ['Anne Bernard', 'Émilie Roux', 'Équipe projet · groupe', 'Jean Dupont', 'Lucas Petit', 'Plombier Express'])
		await page.click('.wm-contact:has-text("Jean Dupont")')
		await page.waitForSelector('.wm-contact-card:has-text("06 12 34 56 78")')
		const card = await page.textContent('.wm-contact-card')
		assert(card.includes('Directeur') && card.includes('12 promenade des Anglais, 06000 Nice, France') && card.includes('Client depuis 2019'), card)
		assert.strictEqual(await page.getAttribute('.wm-contact-card a[href^="tel:"]', 'href'), 'tel:0612345678')
		await shot('14-contacts')
		await page.fill('.wm-list .wm-search', 'roux')
		await page.press('.wm-list .wm-search', 'Enter')
		await page.waitForFunction(() => document.querySelectorAll('.wm-contact').length === 1)
		await page.click('.wm-search-info a')
		await page.waitForFunction(() => document.querySelectorAll('.wm-contact').length === 6)
	})

	await step('contacts: edit', async () => {
		await page.click('.wm-contact:has-text("Jean Dupont")')
		await page.click('.wm-toolbar button:has-text("Modifier")')
		assert.strictEqual(await page.inputValue('input[name=email1]'), 'jean.dupont@client-exemple.fr')
		await page.fill('input[name=jobTitle]', 'Président')
		await page.fill('input[name=email2]', 'jean@perso.example')
		await page.fill('input[name=mobilePhone]', '')
		await page.click('.wm-contact-form button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Contact enregistré")')
		await page.waitForSelector('.wm-contact-card:has-text("Président")')
		const card = await page.textContent('.wm-contact-card')
		assert(card.includes('jean@perso.example') && !card.includes('06 12 34 56 78'), card)
	})

	await step('contacts: a Matrix address, to write on Matrix (Element), kept by Exchange', async () => {
		await page.click('.wm-contact:has-text("Jean Dupont")')
		await page.click('.wm-toolbar button:has-text("Modifier")')
		await page.fill('input[name=matrix]', 'jean.dupont@matrix')
		await page.click('.wm-contact-form button[type=submit]')
		await page.waitForSelector('.wm-form-error:has-text("Adresse Matrix invalide")')
		// a matrix.to link pasted: its address is kept
		await page.fill('input[name=matrix]', 'https://matrix.to/#/@jean.dupont:matrix.client-exemple.fr')
		await page.click('.wm-contact-form button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Contact enregistré")')
		await page.waitForSelector('.wm-contact-card .wm-matrix-link:text-is("@jean.dupont:matrix.client-exemple.fr")')
		assert.strictEqual(await page.getAttribute('.wm-matrix-btn:has-text("Écrire sur Matrix")', 'href'), 'https://matrix.to/#/@jean.dupont:matrix.client-exemple.fr')
		assert.strictEqual(await page.getAttribute('.wm-matrix-btn', 'target'), '_blank')
		await shot('78-contact-matrix')
		// read again from Exchange
		await page.click('.wm-toolbar button:has-text("Modifier")')
		assert.strictEqual(await page.inputValue('input[name=matrix]'), '@jean.dupont:matrix.client-exemple.fr')
		// removed
		await page.fill('input[name=matrix]', '')
		await page.click('.wm-contact-form button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Contact enregistré")')
		await page.waitForSelector('.wm-contact-card:has-text("Jean Dupont")')
		assert.strictEqual(await page.locator('.wm-matrix-btn').count(), 0)
	})

	await step('contacts: create, write to, delete', async () => {
		await page.click('.wm-compose-btn:has-text("Nouveau contact")')
		await page.click('.wm-contact-form button[type=submit]')
		await page.waitForSelector('.wm-form-error:has-text("Indiquez au moins")')
		await page.fill('input[name=givenName]', 'Zoé')
		await page.fill('input[name=surname]', 'Garnier')
		await page.fill('input[name=company]', 'Garnier & Fils')
		await page.fill('input[name=email1]', 'pas-une-adresse')
		// the browser itself refuses the invalid address (type="email")
		assert.strictEqual(await page.$eval('input[name=email1]', (i) => i.checkValidity()), false)
		await page.fill('input[name=email1]', 'zoe@garnier.example')
		await page.click('.wm-contact-form button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Contact créé")')
		await page.waitForSelector('.wm-contact.selected:has-text("Zoé Garnier")')
		await page.click('.wm-toolbar button:has-text("Écrire")')
		await page.waitForSelector('.wm-compose')
		assert.strictEqual(await page.inputValue('input[name=to]'), 'Zoé Garnier <zoe@garnier.example>, ')
		assert.strictEqual(await page.getAttribute('#webmail-app', 'data-module'), 'mail')
		await page.click('.wm-compose button:has-text("Annuler")')
		await page.click('.wm-tab[data-module="contacts"]')
		await page.click('.wm-contact:has-text("Zoé Garnier")')
		page.once('dialog', (d) => d.accept())
		await page.click('.wm-toolbar button:has-text("Supprimer")')
		await page.waitForSelector('.wm-toast:has-text("Contact supprimé")')
		assert(!(await page.$('.wm-contact:has-text("Zoé Garnier")')))
	})

	await step('contacts: groups (Outlook distribution lists)', async () => {
		// an existing group: its members, written to all at once
		await page.click('.wm-contact:has-text("Équipe projet")')
		await page.waitForSelector('.wm-group-members:has-text("2 membres")')
		await page.waitForSelector('.wm-group-members li:has-text("Anne Bernard <anne@cabinet-bernard.fr>")')
		await page.click('.wm-toolbar button:has-text("Écrire au groupe")')
		await page.waitForSelector('.wm-compose')
		assert.strictEqual(await page.inputValue('input[name=to]'), 'Anne Bernard <anne@cabinet-bernard.fr>, Lucas Petit <lucas.petit@exemple.org>, ')
		await page.click('.wm-compose button:has-text("Annuler")')
		await page.click('.wm-tab[data-module="contacts"]')
		// changed: one removed, one added by address
		await page.click('.wm-contact:has-text("Équipe projet")')
		await page.click('.wm-toolbar button:has-text("Modifier")')
		await page.waitForSelector('.wm-group-form h2:has-text("Modifier le groupe")')
		await page.click('.wm-group-edit li[data-email="lucas.petit@exemple.org"] button')
		await page.fill('input[name=groupMember]', 'Jean Martin <jean.martin@chantier.example>')
		await page.press('input[name=groupMember]', 'Enter')
		await page.waitForSelector('.wm-group-edit li[data-email="jean.martin@chantier.example"]')
		await shot('65-contact-group')
		await page.click('.wm-group-form button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Groupe enregistré")')
		await page.waitForSelector('.wm-group-members li:has-text("Jean Martin <jean.martin@chantier.example>")')
		assert(!(await page.$('.wm-group-members li:has-text("Lucas Petit")')))
		// a new group
		await page.click('.wm-new-group')
		await page.fill('input[name=groupName]', 'Fournisseurs chantier')
		await page.fill('input[name=groupMember]', 'pas une adresse')
		await page.press('input[name=groupMember]', 'Enter')
		await page.waitForSelector('.wm-group-form .wm-form-error:has-text("Adresse invalide")')
		await page.fill('input[name=groupMember]', 'devis@elec-azur.example, contact@plombier-express.fr')
		await page.press('input[name=groupMember]', 'Enter')
		await page.click('.wm-group-form button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Groupe créé")')
		await page.waitForSelector('.wm-contact.selected:has-text("Fournisseurs chantier")')
		await page.waitForSelector('.wm-group-members:has-text("2 membres")')
		page.once('dialog', (d) => d.accept())
		await page.click('.wm-toolbar button:has-text("Supprimer")')
		// (the toast of the previous step may still be there: wait for the group itself to go)
		await page.waitForSelector('.wm-contact:has-text("Fournisseurs chantier")', { state: 'detached' })
	})

	await step('contacts: photos (own contacts and directory)', async () => {
		// a loaded photo: in the row of a contact (by its name), or at a selector
		const photoOf = (name) => page.waitForFunction((n) => {
			const li = [...document.querySelectorAll('.wm-contact')].find((e) => e.textContent.includes(n))
			const i = li && li.querySelector('img.wm-avatar-photo')
			return i && i.complete && i.naturalWidth > 0
		}, name)
		const loaded = (sel) => page.waitForFunction((s) => { const i = document.querySelector(s); return i && i.complete && i.naturalWidth > 0 }, sel)
		const bookLink = (name) => '.wm-book-row:has-text("' + name + '") .wm-book-link'
		// a photo set in Outlook
		await photoOf('Anne Bernard')
		assert(!(await page.$('.wm-contact:has-text("Lucas Petit") img')), 'initials without a photo')
		// a photo given to a contact
		await page.click('.wm-contact:has-text("Lucas Petit")')
		await page.click('.wm-toolbar button:has-text("Modifier")')
		await page.setInputFiles('.wm-contact-form input[name=photo]', { name: 'lucas.png', mimeType: 'image/png',
			buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAAEElEQVR4nGNgaDqJHQ0tCQAjaFLBNtJ19gAAAABJRU5ErkJggg==', 'base64') })
		await loaded('.wm-photo-preview img')
		await shot('66-contact-photo')
		await page.click('.wm-contact-form button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Contact enregistré")')
		await loaded('.wm-contact-head img.wm-avatar-photo')
		await photoOf('Lucas Petit')
		// and removed
		await page.click('.wm-toolbar button:has-text("Modifier")')
		await page.click('.wm-contact-form button:has-text("Retirer la photo")')
		await page.click('.wm-contact-form button[type=submit]')
		await page.waitForSelector('.wm-contact-head span.wm-avatar')
		await page.waitForFunction(() => { const li = [...document.querySelectorAll('.wm-contact')].find((e) => e.textContent.includes('Lucas Petit')); return li && !li.querySelector('img') })
		// the directory: the photo of the Exchange account
		await page.click(bookLink('Annuaire'))
		await page.fill('.wm-list .wm-search', 'mar')
		await page.press('.wm-list .wm-search', 'Enter')
		await photoOf('Marie Martin')
		await page.click(bookLink('Contacts'))
		await page.waitForSelector('.wm-contact:has-text("Anne Bernard")')
	})

	await step('contacts: export and import vCard files', async () => {
		await page.click('.wm-tab[data-module="contacts"]')
		await page.waitForSelector('.wm-contact:has-text("Jean Dupont")')
		// the whole book in a .vcf file (the group is left out)
		const [download] = await Promise.all([page.waitForEvent('download'), page.click('.wm-vcf-export')])
		assert.strictEqual(download.suggestedFilename(), 'Contacts.vcf')
		const vcf = fs.readFileSync(await download.path(), 'utf8')
		// as many cards as people in the list (groups excepted); Jean Dupont as edited above
		const people = await page.$$eval('.wm-contact', (els) => els.filter((e) => !e.textContent.includes('· groupe')).length)
		assert.strictEqual((vcf.match(/BEGIN:VCARD/g) || []).length, people, vcf.slice(0, 300))
		assert(vcf.includes('FN:Jean Dupont\r\nN:Dupont;Jean;;;\r\nORG:Client Exemple SA\r\nTITLE:Président\r\nEMAIL;TYPE=INTERNET,PREF:jean.dupont@client-exemple.fr\r\nEMAIL;TYPE=INTERNET:jean@perso.example'), vcf.slice(0, 400))
		assert(vcf.includes('ADR;TYPE=WORK:;;12 promenade des Anglais;Nice;;06000;France'))
		assert(!vcf.includes('Équipe projet'))
		// a file from another program: one new person, one already there, one from an old Outlook
		const cards = 'BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Sophie Lambert\r\nN:Lambert;Sophie;;;\r\nORG:Lambert Conseil\r\nEMAIL;TYPE=INTERNET:sophie@lambert-conseil.example\r\nTEL;TYPE=CELL:06 98 76 54 32\r\nEND:VCARD\r\n'
			+ 'BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Jean Dupont\r\nEMAIL:JEAN.DUPONT@client-exemple.fr\r\nEND:VCARD\r\n'
			+ 'BEGIN:VCARD\r\nVERSION:2.1\r\nN:Faure;Hugo\r\nFN:Hugo Faure\r\nORG;ENCODING=QUOTED-PRINTABLE;CHARSET=utf-8:Menuiserie Fa=C3=BBre\r\nTEL;WORK;VOICE:04 93 12 12 12\r\nEND:VCARD\r\n'
		const [chooser] = await Promise.all([page.waitForEvent('filechooser'), page.click('.wm-vcf-import')])
		await chooser.setFiles({ name: 'clients.vcf', mimeType: 'text/vcard', buffer: Buffer.from(cards) })
		await page.waitForSelector('.wm-toast:has-text("2 contacts importés dans « Contacts » ; 1 déjà présent (même adresse e-mail)")')
		await page.waitForSelector('.wm-contact:has-text("Sophie Lambert")')
		await shot('52-vcard-import')
		await page.click('.wm-contact:has-text("Hugo Faure")')
		await page.waitForSelector('.wm-contact-card:has-text("Menuiserie Faûre")')
		assert((await page.textContent('.wm-contact-card')).includes('04 93 12 12 12'))
		// the next steps count the contacts of the book: as they were
		for (const name of ['Sophie Lambert', 'Hugo Faure']) {
			await page.click('.wm-contact:has-text("' + name + '")')
			page.once('dialog', (d) => d.accept())
			await page.click('.wm-toolbar button:has-text("Supprimer")')
			await page.waitForSelector('.wm-contact:has-text("' + name + '")', { state: 'detached' })
		}
	})

	// ------------------------------------------------------------------ shared contacts
	const book = (name) => '.wm-book-row:has-text("' + name + '") .wm-book-link'

	await step('contacts: own address books', async () => {
		await page.click('.wm-tab[data-module="contacts"]')
		await page.waitForSelector('.wm-book-row')
		const books = await page.$$eval('.wm-book-row .wm-cal-name', (els) => els.map((e) => e.textContent))
		assert.deepStrictEqual(books, ['Contacts', 'Fournisseurs', 'Annuaire de l\'entreprise'], 'internal Exchange folders are hidden')
		await page.click(book('Fournisseurs'))
		await page.waitForSelector('.wm-contact:has-text("Électricité Azur")')
		assert.strictEqual(await page.$$eval('.wm-contact', (els) => els.length), 2)
		await page.click('.wm-compose-btn:has-text("Nouveau contact")')
		assert.strictEqual(await page.inputValue('select[name=book]'), 'F-CONTACTS2', 'new contact goes to the displayed book')
		await page.fill('input[name=company]', 'Garage Central')
		await page.fill('input[name=email1]', 'atelier@garage-central.example')
		await page.click('.wm-contact-form button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Contact créé")')
		await page.waitForSelector('.wm-contact:has-text("Garage Central")')
		await page.click(book('Contacts'))
		await page.waitForSelector('.wm-contact:has-text("Jean Dupont")')
		assert(!(await page.$('.wm-contact:has-text("Garage Central")')))
	})

	await step('contacts: add a colleague\'s contacts (edit and read access)', async () => {
		const add = async (email) => {
			await page.click('.wm-cal-add:has-text("Ajouter les contacts")')
			await page.fill('.wm-modal input[name=sharedEmail]', email)
			await page.click('.wm-modal button[type=submit]')
		}
		await add('inconnu@societe-a.fr')
		await page.waitForSelector('.wm-modal .wm-form-error:has-text("Aucune boîte Exchange ne correspond")')
		await page.fill('.wm-modal input[name=sharedEmail]', 'paul.durand@societe-a.fr')
		await page.click('.wm-modal button[type=submit]')
		await page.waitForSelector('.wm-modal .wm-form-error:has-text("ne partage pas de carnet d\'adresses avec vous")')
		await page.click('.wm-modal-close')

		// Marie shares three address books: tick the ones to add
		await add('marie.martin@societe-a.fr')
		await page.waitForSelector('.wm-modal .wm-choice-list')
		const choices = await page.$$eval('.wm-modal .wm-choice-list li', (els) => els.map((e) => e.textContent.replace(/\s+/g, ' ').trim()))
		assert.deepStrictEqual(choices, ['Contacts modification', 'Clients VIP lecture', 'Partenaires modification'], 'secondary books inside « Contacts » and at the top of the mailbox')
		assert(await page.isChecked('.wm-modal input[name=sharedFolder][value=""]'), 'the default book is ticked')
		await page.check('.wm-modal li:has-text("Clients VIP") input')
		await shot('30-add-colleague-books')
		await page.click('.wm-modal button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("2 carnets de Marie Martin ajoutés")')
		await page.waitForSelector('.wm-book-row.active[data-key="shared:marie.martin@societe-a.fr"]')
		await page.click('.wm-contact:has-text("Hugo Blanc")')
		await page.click('.wm-toolbar button:has-text("Modifier")')
		await page.fill('input[name=jobTitle]', 'Acheteur')
		await page.click('.wm-contact-form button[type=submit]')
		await page.waitForSelector('.wm-contact-card:has-text("Acheteur")')

		await add('martine.leroy@societe-b.fr')
		await page.waitForSelector('.wm-toast:has-text("Contacts de Martine Leroy ajoutés (lecture)")')
		await page.click('.wm-contact:has-text("Sophie Girard")')
		await page.waitForSelector('.wm-toolbar:has-text("lecture seule")')
		assert(!(await page.$('.wm-toolbar button:has-text("Modifier")')))
		assert(!(await page.$('.wm-toolbar button:has-text("Supprimer")')))
		const badges = await page.$$eval('.wm-book-row .wm-cal-mode', (els) => els.map((e) => e.textContent))
		assert.deepStrictEqual(badges, ['modification', 'lecture', 'lecture'])
		const sharedNames = await page.$$eval('.wm-book-row[data-key^="shared:"] .wm-cal-name', (els) => els.map((e) => e.textContent))
		assert.deepStrictEqual(sharedNames, ['Marie Martin', 'Clients VIP (Marie Martin)', 'Martine Leroy'])
		await shot('23-shared-contacts')
		await page.click('.wm-toolbar button:has-text("Ajouter à mes contacts")')
		await page.waitForSelector('.wm-toast:has-text("Sophie Girard ajouté à vos contacts")')
		await page.click(book('Contacts'))
		await page.waitForSelector('.wm-contact:has-text("Sophie Girard")')
	})

	await step('contacts: add another address book of a colleague', async () => {
		await page.click('.wm-book-row:has-text("Clients VIP") .wm-book-link')
		await page.click('.wm-contact:has-text("Laura Costa")')
		await page.waitForSelector('.wm-toolbar:has-text("« Clients VIP » de Marie Martin (lecture seule)")')

		// books already added are shown as such; add the last one
		await page.click('.wm-cal-add:has-text("Ajouter les contacts")')
		await page.fill('.wm-modal input[name=sharedEmail]', 'marie.martin@societe-a.fr')
		await page.click('.wm-modal button[type=submit]')
		await page.waitForSelector('.wm-modal .wm-choice-list')
		assert(await page.isDisabled('.wm-modal li:has-text("Clients VIP") input'))
		assert((await page.textContent('.wm-modal li:has-text("Clients VIP")')).includes('déjà ajouté'))
		await page.click('.wm-modal button[type=submit]')
		await page.waitForSelector('.wm-modal .wm-form-error:has-text("Cochez au moins un carnet")')
		await page.check('.wm-modal li:has-text("Partenaires") input')
		await page.click('.wm-modal button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("« Partenaires » de Marie Martin ajouté (modification)")')
		await page.waitForSelector('.wm-book-row.active:has-text("Partenaires (Marie Martin)")')
		await page.waitForSelector('.wm-contact:has-text("Agence Horizon")')

		// edit access: a new contact goes into her « Partenaires »
		await page.click('.wm-compose-btn:has-text("Nouveau contact")')
		assert.strictEqual(await page.inputValue('select[name=book]'), 'shared:marie.martin@societe-a.fr:F-MARIE-PART')
		const options = await page.$$eval('select[name=book] option', (els) => els.map((e) => e.textContent))
		assert(options.includes('« Partenaires » de Marie Martin') && options.includes('Contacts de Marie Martin'), options.join('|'))
		await page.fill('input[name=company]', 'Traiteur des Collines')
		await page.click('.wm-contact-form button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Contact créé")')
		await page.waitForSelector('.wm-contact:has-text("Traiteur des Collines")')

		// removing one of her books keeps the others
		page.once('dialog', (d) => d.accept())
		await page.click('.wm-book-row:has-text("Partenaires") .wm-cal-remove')
		await page.waitForSelector('.wm-toast:has-text("Carnet retiré")')
		await page.waitForFunction(() => ![...document.querySelectorAll('.wm-book-row')].some((e) => e.textContent.includes('Partenaires')))
		assert(await page.$('.wm-book-row[data-key="shared:marie.martin@societe-a.fr"]'))
		assert(await page.$('.wm-book-row:has-text("Clients VIP")'))
	})

	await step('contacts: address books of the public folders', async () => {
		const openBrowser = async () => {
			await page.click('.wm-public-add')
			await page.waitForSelector('.wm-modal .wm-pf-folder')
		}
		const names = () => page.$$eval('.wm-modal .wm-pf-folder', (els) => els.map((e) => e.dataset.name))
		await openBrowser()
		// only folders holding address books or other folders
		assert.deepStrictEqual(await names(), ['Société A'])
		await page.click('.wm-modal .wm-pf-folder[data-name="Société A"] .wm-pf-open')
		await page.waitForSelector('.wm-modal .wm-pf-path:has-text("Dossiers publics › Société A")')
		await page.waitForSelector('.wm-modal .wm-pf-folder[data-name="Clients communs"]')
		assert.deepStrictEqual(await names(), ['Clients communs', 'Fournisseurs agréés'], 'the shared calendar is not offered')
		await shot('31-public-folders')
		await page.click('.wm-modal .wm-pf-folder[data-name="Clients communs"] .wm-pf-add')
		await page.waitForSelector('.wm-toast:has-text("« Clients communs » ajouté (modification)")')
		await page.waitForSelector('.wm-book-row.active[data-key="public:PF-CLIENTS"]')
		await page.waitForSelector('.wm-contact:has-text("Imprimerie Azur")')
		assert.strictEqual(await page.$$eval('.wm-contact', (els) => els.length), 2)

		// search without the search index (public folders have none)
		await page.fill('.wm-list .wm-search', 'azur')
		await page.press('.wm-list .wm-search', 'Enter')
		await page.waitForFunction(() => document.querySelectorAll('.wm-contact').length === 1)
		assert((await page.textContent('.wm-contact')).includes('Imprimerie Azur'))
		await page.fill('.wm-list .wm-search', '')
		await page.press('.wm-list .wm-search', 'Enter')
		await page.waitForSelector('.wm-contact:has-text("Nadia Roux")')

		// edit access: modify, create, delete
		await page.click('.wm-contact:has-text("Nadia Roux")')
		await page.click('.wm-toolbar button:has-text("Modifier")')
		await page.fill('input[name=jobTitle]', 'Associée')
		await page.click('.wm-contact-form button[type=submit]')
		await page.waitForSelector('.wm-contact-card:has-text("Associée")')
		await page.click('.wm-compose-btn:has-text("Nouveau contact")')
		assert.strictEqual(await page.inputValue('select[name=book]'), 'public:PF-CLIENTS')
		await page.fill('input[name=company]', 'Menuiserie du Var')
		await page.click('.wm-contact-form button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Contact créé")')
		await page.waitForSelector('.wm-contact:has-text("Menuiserie du Var")')
		page.once('dialog', (d) => { assert(d.message().includes('supprimé pour tout le monde'), d.message()); d.accept() })
		await page.click('.wm-toolbar button:has-text("Supprimer")')
		await page.waitForSelector('.wm-toast:has-text("Contact supprimé")')
		await page.waitForFunction(() => ![...document.querySelectorAll('.wm-contact')].some((e) => e.textContent.includes('Menuiserie du Var')))

		// a read-only one
		await openBrowser()
		await page.click('.wm-modal .wm-pf-folder[data-name="Société A"] .wm-pf-open')
		await page.waitForSelector('.wm-modal .wm-pf-folder[data-name="Clients communs"]:has-text("déjà ajouté")')
		await page.click('.wm-modal .wm-pf-folder[data-name="Fournisseurs agréés"] .wm-pf-add')
		await page.waitForSelector('.wm-toast:has-text("« Fournisseurs agréés » ajouté (lecture)")')
		await page.click('.wm-contact:has-text("Location Matériel Sud")')
		await page.waitForSelector('.wm-toolbar:has-text("« Fournisseurs agréés » (dossiers publics) (lecture seule)")')
		assert(!(await page.$('.wm-toolbar button:has-text("Modifier")')))
		await page.click('.wm-toolbar button:has-text("Ajouter à mes contacts")')
		await page.waitForSelector('.wm-toast:has-text("Location Matériel Sud ajouté à vos contacts")')
		const badges = await page.$$eval('.wm-book-row[data-key^="public:"] .wm-cal-mode', (els) => els.map((e) => e.textContent))
		assert.deepStrictEqual(badges, ['modification', 'lecture'])

		page.once('dialog', (d) => d.accept())
		await page.click('.wm-book-row[data-key="public:PF-FOURN"] .wm-cal-remove')
		await page.waitForSelector('.wm-toast:has-text("Carnet retiré")')
		await page.waitForFunction(() => !document.querySelector('.wm-book-row[data-key="public:PF-FOURN"]'))
		assert(await page.$('.wm-book-row[data-key="public:PF-CLIENTS"]'))
	})

	await step('contacts: company directory', async () => {
		await page.click(book('Annuaire'))
		await page.waitForSelector('.wm-empty:has-text("Tapez au moins 2 lettres")')
		await page.fill('.wm-list .wm-search', 'mar')
		await page.press('.wm-list .wm-search', 'Enter')
		await page.waitForSelector('.wm-contact:has-text("Martine Leroy")')
		const names = await page.$$eval('.wm-contact-name', (els) => els.map((e) => e.textContent))
		assert.deepStrictEqual(names, ['Marie Martin', 'Martine Leroy'], 'only the directory, not personal contacts')
		await page.click('.wm-contact:has-text("Marie Martin")')
		await page.waitForSelector('.wm-contact-card:has-text("Directrice commerciale")')
		const card = await page.textContent('.wm-contact-card')
		assert(card.includes('Direction') && card.includes('04 93 10 10 10') && card.includes('Nice'), card)
		assert(await page.$('.wm-toolbar:has-text("Annuaire de l\'entreprise")'))
		await shot('24-directory')
		await page.click('.wm-toolbar button:has-text("Écrire")')
		await page.waitForSelector('.wm-compose')
		assert.strictEqual(await page.inputValue('input[name=to]'), 'Marie Martin <marie.martin@societe-a.fr>, ')
		await page.click('.wm-compose button:has-text("Annuler")')
	})

	await step('contacts: remove a shared address book', async () => {
		await page.click('.wm-tab[data-module="contacts"]')
		page.once('dialog', (d) => d.accept())
		await page.click('.wm-book-row:has-text("Martine Leroy") .wm-cal-remove')
		await page.waitForSelector('.wm-toast:has-text("Carnet retiré")')
		await page.waitForFunction(() => ![...document.querySelectorAll('.wm-book-row')].some((e) => e.textContent.includes('Martine Leroy')))
		await page.click(book('Contacts'))
		await page.waitForSelector('.wm-contact:has-text("Jean Dupont")')
	})

	await step('contacts: share my address book without delegation', async () => {
		const row = (user) => '.wm-share-row[data-user="' + user + '"]'
		const openShare = async (key, name) => {
			await page.click('.wm-book-row[data-key="' + key + '"] .wm-cal-share')
			await page.waitForSelector('.wm-modal h2:has-text("Partager « ' + name + ' »")')
			await page.waitForSelector('.wm-share-row')
		}
		// only my own address books can be shared
		assert(!(await page.$('.wm-book-row[data-key="shared:marie.martin@societe-a.fr"] .wm-cal-share')))
		assert(!(await page.$('.wm-book-row[data-key="directory"] .wm-cal-share')))

		await openShare('contacts', 'Contacts')
		const levels = await page.$$eval(row('Default') + ' select option', (els) => els.map((e) => e.value))
		assert.deepStrictEqual(levels, ['None', 'Reviewer', 'Author', 'Editor'], 'no free/busy levels for contacts')
		assert.strictEqual(await page.inputValue(row('Default') + ' select'), 'None')
		assert.strictEqual(await page.inputValue(row('martine.leroy@societe-b.fr') + ' select'), 'keep', 'custom level from Outlook kept')

		await page.selectOption(row('Default') + ' select', 'Reviewer')
		await page.fill('.wm-modal input[name=shareEmail]', 'paul.durand@societe-a.fr')
		await page.selectOption('.wm-modal select[name=newLevel]', 'Editor')
		await page.click('.wm-share-add button:has-text("Ajouter")')
		await page.waitForSelector(row('paul.durand@societe-a.fr'))
		await shot('29-share-contacts')
		await page.click('.wm-modal button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Partage de « Contacts » enregistré")')
		await page.waitForSelector('.wm-modal', { state: 'detached' })

		await openShare('contacts', 'Contacts')
		assert.strictEqual(await page.inputValue(row('Default') + ' select'), 'Reviewer')
		assert.strictEqual(await page.inputValue(row('paul.durand@societe-a.fr') + ' select'), 'Editor')
		assert.strictEqual(await page.inputValue(row('martine.leroy@societe-b.fr') + ' select'), 'keep')
		await page.click('.wm-modal-close')

		// a second address book of mine
		await openShare('F-CONTACTS2', 'Fournisseurs')
		await page.selectOption(row('Default') + ' select', 'Author')
		await page.click('.wm-modal button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Partage de « Fournisseurs » enregistré")')
	})

	// ------------------------------------------------------------------ delegates
	await step('delegates: add, update and remove delegates of the mailbox', async () => {
		await page.click('.wm-nav-footer a:has-text("Comptes et signature")')
		await page.click('.wm-account-card:has(legend:has-text("user@societe-a.fr")) .wm-delegates-btn')
		await page.waitForSelector('.wm-delegates h3:has-text("Aucun délégué pour l\'instant")')
		assert(await page.isDisabled('select[name=deliverMeetingRequests]'), 'delivery option needs a delegate receiving copies')

		const add = async (email) => {
			await page.fill('.wm-delegate-new input[name=delegateEmail]', email)
			await page.click('.wm-delegate-new button[type=submit]')
		}
		await add('inconnu@societe-a.fr')
		await page.waitForSelector('.wm-toast-error:has-text("Aucune boîte Exchange ne correspond")')

		// default: calendar editor + copies of invitations
		await add('marie.martin@societe-a.fr')
		await page.waitForSelector('.wm-toast:has-text("Délégué ajouté")')
		const marie = '.wm-delegate[data-email="marie.martin@societe-a.fr"]'
		await page.waitForSelector(marie)
		assert.strictEqual(await page.inputValue(marie + ' select[name=calendar]'), 'Editor')
		assert(await page.isChecked(marie + ' input[name=receiveCopies]'))
		assert(!(await page.isDisabled('select[name=deliverMeetingRequests]')))

		// read-only delegate: lowering the calendar level unticks the invitations
		await page.selectOption('.wm-delegate-new select[name=calendar]', 'Reviewer')
		assert(!(await page.isChecked('.wm-delegate-new input[name=receiveCopies]')))
		await page.selectOption('.wm-delegate-new select[name=inbox]', 'Reviewer')
		await add('paul.durand@societe-a.fr')
		await page.waitForSelector('.wm-toast:has-text("Délégué ajouté")')
		const paul = '.wm-delegate[data-email="paul.durand@societe-a.fr"]'
		await page.waitForSelector(paul)
		assert.strictEqual(await page.inputValue(paul + ' select[name=calendar]'), 'Reviewer')
		assert.strictEqual(await page.inputValue(paul + ' select[name=inbox]'), 'Reviewer')
		const names = await page.$$eval('.wm-delegate:not(.wm-delegate-new) .wm-delegate-name strong', (els) => els.map((e) => e.textContent))
		assert.deepStrictEqual(names, ['Marie Martin', 'Paul Durand'])

		// update
		await page.selectOption(paul + ' select[name=contacts]', 'Editor')
		await page.click(paul + ' button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Délégué mis à jour")')
		await page.waitForFunction((sel) => document.querySelector(sel + ' select[name=contacts]').value === 'Editor', paul)

		// invitations go to the delegates only
		await page.selectOption('select[name=deliverMeetingRequests]', 'DelegatesOnly')
		await page.waitForSelector('.wm-toast:has-text("Réglage des invitations enregistré")')
		await shot('27-delegates')

		// remove
		page.once('dialog', (d) => d.accept())
		await page.click(paul + ' button:has-text("Retirer")')
		await page.waitForSelector('.wm-toast:has-text("Délégué retiré")')
		await page.waitForFunction((sel) => !document.querySelector(sel), paul)
		assert.strictEqual(await page.inputValue('select[name=deliverMeetingRequests]'), 'DelegatesOnly')

		// back to the accounts
		await page.click('.wm-delegates .wm-back')
		await page.waitForSelector('.wm-settings h2:has-text("Comptes Exchange")')
	})

	await step('tasks: what is typed in « Ajouter une tâche » stays when the list is refreshed meanwhile', async () => {
		// the tasks shown at once from the cache, the fresh list held back 1.5 s
		let fetched = false
		const hold = async (route) => {
			const response = await route.fetch()
			fetched = true
			await new Promise((r) => setTimeout(r, 1500))
			await route.fulfill({ response })
		}
		await page.route('**/api/accounts/*/tasks?*', hold)
		try {
			await page.click('.wm-tab[data-module="tasks"]')
			await page.waitForSelector('input[name=quickTask]')
			for (let i = 0; i < 100 && !fetched; i++) await page.waitForTimeout(50)
			await page.click('input[name=quickTask]')
			await page.keyboard.type('Brouillon de tâche')
			// the fresh list arrives: the field is still there, with its text and the focus
			await page.waitForTimeout(1800)
			assert.strictEqual(await page.inputValue('input[name=quickTask]'), 'Brouillon de tâche')
			assert.ok(await page.$eval('input[name=quickTask]', (e) => document.activeElement === e))
		} finally {
			await page.unroute('**/api/accounts/*/tasks?*', hold)
		}
		await page.fill('input[name=quickTask]', '')
		await page.click('.wm-tab[data-module="mail"]')
	})

	await step('tasks: add, edit, complete and delete an Exchange task', async () => {
		await page.click('.wm-tab[data-module="tasks"]')
		await page.waitForSelector('.wm-task:has-text("Renouveler le nom de domaine")')
		// the late one first, in red; the completed one is not in « À faire »
		assert.ok((await page.textContent('.wm-task >> nth=0')).includes('Relancer le cabinet comptable'))
		await page.waitForSelector('.wm-task.late:has-text("Relancer le cabinet comptable") .wm-task-due:has-text("En retard")')
		await page.waitForSelector('.wm-task:has-text("Renouveler le nom de domaine") .wm-task-due:has-text("Demain")')
		await page.waitForSelector('.wm-task:has-text("Commander des cartouches") .wm-task-status:has-text("En cours")')
		assert.strictEqual(await page.locator('.wm-task:has-text("Envoyer les vœux")').count(), 0)

		// quick add
		await page.fill('input[name=quickTask]', 'Préparer le devis Dupont')
		await page.press('input[name=quickTask]', 'Enter')
		await page.waitForSelector('.wm-toast:has-text("Tâche ajoutée")')
		const devis = '.wm-task:has-text("Préparer le devis Dupont")'
		await page.waitForSelector(devis)

		// edit: due tomorrow, high importance, notes
		await page.click(devis + ' .wm-task-subject')
		await page.waitForSelector('.wm-task-form input[name=subject]')
		assert.strictEqual(await page.inputValue('.wm-task-form input[name=subject]'), 'Préparer le devis Dupont')
		await page.click('.wm-task-form button:has-text("Demain")')
		await page.selectOption('.wm-task-form select[name=importance]', 'High')
		await page.fill('.wm-task-form textarea[name=notes]', 'Chiffrer la maintenance sur 3 ans')
		await page.click('.wm-task-form button:has-text("Enregistrer")')
		await page.waitForSelector('.wm-toast:has-text("Tâche enregistrée")')
		await page.waitForSelector(devis + ' .wm-important')
		await page.waitForSelector(devis + ' .wm-task-due:has-text("Demain")')
		await shot('58-tasks')
		// saved on the server: reopen it
		await page.click('.wm-task:has-text("Renouveler le nom de domaine") .wm-task-subject')
		await page.waitForFunction(() => document.querySelector('.wm-task-form input[name=subject]')?.value === 'Renouveler le nom de domaine')
		await page.click(devis + ' .wm-task-subject')
		await page.waitForFunction(() => document.querySelector('.wm-task-form textarea[name=notes]')?.value === 'Chiffrer la maintenance sur 3 ans')

		// done: it leaves « À faire » and is in « Terminées »
		await page.check(devis + ' .wm-task-done')
		await page.waitForSelector('.wm-toast:has-text("Tâche terminée")')
		await page.locator(devis).waitFor({ state: 'detached' })
		await page.click('.wm-task-filter[data-filter="done"]')
		await page.waitForSelector('.wm-task.done:has-text("Préparer le devis Dupont")')
		await page.waitForSelector('.wm-task.done:has-text("Envoyer les vœux")')

		// delete
		await page.click(devis + ' .wm-task-subject')
		await page.waitForSelector('.wm-task-form button:has-text("Remettre à faire")')
		page.once('dialog', (d) => d.accept())
		await page.click('.wm-task-form button:has-text("Supprimer")')
		await page.waitForSelector('.wm-toast:has-text("Tâche supprimée")')
		await page.locator(devis).waitFor({ state: 'detached' })
		await page.click('.wm-task-filter[data-filter="todo"]')
		await page.waitForSelector('.wm-task:has-text("Renouveler le nom de domaine")')
	})

	await step('tab memory: after a reload, the mailbox shows before Exchange answers', async () => {
		await page.click('.wm-tab[data-module="mail"]')
		await page.waitForSelector('.wm-message')
		await page.waitForTimeout(800) // written half a second after the list
		const stored = await page.evaluate(() => sessionStorage.getItem('webmail.tab.v1'))
		assert.ok(stored && stored.includes('"lists"') && stored.includes('"folders"'), 'lists and folders kept for the tab')
		assert.ok(!/"body"\s*:/.test(stored), 'never the content of the messages')
		// Exchange does not answer the list (yet): the remembered list shows anyway
		const listCall = /\/apps\/webmail\/api\/accounts\/\d+\/(messages|conversations)\?/
		let release
		const held = new Promise((resolve) => { release = resolve })
		await page.route(listCall, async (route) => { await held; await route.continue().catch(() => null) }) // may already be let through by unroute
		try {
			const t0 = Date.now()
			await page.reload()
			await page.waitForSelector('.wm-message', { timeout: 5000 })
			await page.waitForSelector('.wm-folder.active')
			console.log('    mailbox shown ' + (Date.now() - t0) + ' ms after the reload, list request still pending')
		} finally {
			release()
			await page.unroute(listCall)
		}
		await page.waitForSelector('.wm-message')
	})

	await step('idle prefetch and first page: unread messages ready before the click, a new folder shows its first rows sooner', async () => {
		// a folder never seen: 25 rows first, then the rest of the page right after
		const firstPages = listRequests.findIndex(([offset, limit], i) => offset === 0 && limit === 25 && listRequests.slice(i + 1).some(([o, l]) => o === 25 && l === 25))
		assert.ok(firstPages >= 0, 'first page of 25 then 25 more: ' + JSON.stringify(listRequests.slice(0, 12)))
		// after a reload (empty message memory), the first unread messages of the inbox are loaded while idle
		await page.click('.wm-tab[data-module="mail"]')
		await page.click('.wm-folder:has-text("Boîte de réception")')
		for (const subject of ['Préchargé 1', 'Préchargé 2']) {
			await page.click('.wm-compose-btn')
			await page.fill('input[name=to]', 'user@societe-a.fr')
			await page.fill('input[name=subject]', subject)
			await page.fill('.wm-editor', 'Message à lire.')
			await submitCompose()
		}
		const fetched = []
		const onRequest = (r) => { if (r.url().includes('/message?')) fetched.push(new URL(r.url()).searchParams) }
		page.on('request', onRequest)
		try {
			await page.reload()
			// the list remembered by the tab shows first (without the two messages just sent): wait for Exchange's
			await page.waitForSelector('.wm-message.unread:has-text("Préchargé 2")')
			const unread = await page.$$eval('.wm-message.unread', (els) => els.slice(0, 5).map((e) => e.dataset.id))
			assert.ok(unread.length > 0, 'unread messages in the inbox')
			for (let i = 0; i < 80 && !unread.every((id) => fetched.some((q) => q.get('itemId') === id)); i++) await page.waitForTimeout(100)
			for (const id of unread) {
				const q = fetched.find((x) => x.get('itemId') === id)
				assert.ok(q && q.get('markRead') === '0', 'prefetched without marking it read: ' + id)
				assert.ok(await page.$(`.wm-message.unread[data-id="${id}"]`), 'still unread')
			}
			// the first one opens without asking the server again
			const before = fetched.length
			await page.click(`.wm-message[data-id="${unread[0]}"]`)
			await page.waitForSelector('.wm-body-frame')
			assert.strictEqual(fetched.slice(before).filter((q) => q.get('itemId') === unread[0]).length, 0, 'opened from the prefetch')
		} finally {
			page.off('request', onRequest)
		}
	})

	await step('quick start: the accounts come with the page, the folders and the messages are asked for at once', async () => {
		const asked = []
		const onRequest = (r) => { const u = new URL(r.url()); if (u.pathname.includes('/apps/webmail/api/')) asked.push([Date.now(), r.method(), u.pathname]) }
		page.on('request', onRequest)
		try {
			await page.goto(NC + '/apps/webmail/?module=mail')
			await page.waitForSelector('.wm-message')
		} finally {
			page.off('request', onRequest)
		}
		assert.ok(!asked.some(([, m, p]) => m === 'GET' && /\/api\/accounts$/.test(p)), 'no request for the accounts: ' + asked.map((a) => a[2]).join(' '))
		assert.ok(asked.some(([, , p]) => /\/api\/accounts\/\d+\/folders$/.test(p)) && asked.some(([, , p]) => /\/api\/accounts\/\d+\/(messages|conversations)$/.test(p)), asked.map((a) => a[2]).join(' '))
	})

	await step('command palette (Ctrl+K): a folder, a module, a new message, a search, with a few letters', async () => {
		const palette = async (text) => {
			await page.keyboard.press('Control+k')
			await page.waitForSelector('.wm-palette-input')
			assert.ok(await page.evaluate(() => document.activeElement.classList.contains('wm-palette-input')), 'typed at once')
			await page.keyboard.type(text)
		}
		// a folder, accents left out
		await palette('fact')
		assert.strictEqual((await page.textContent('.wm-palette-item.active .wm-palette-label')).trim(), 'Factures')
		await shot('79-palette')
		await page.keyboard.press('Enter')
		await page.locator('.wm-palette-overlay').waitFor({ state: 'detached' })
		await page.waitForSelector('.wm-folder.active:has-text("Factures")')
		// a module, from the arrows
		await palette('a')
		const labels = await page.locator('.wm-palette-item .wm-palette-label').allTextContents()
		const i = labels.indexOf('Agenda')
		assert.ok(i >= 0, labels.join(' | '))
		for (let k = 0; k < i; k++) await page.keyboard.press('ArrowDown')
		await page.keyboard.press('Enter')
		await page.waitForSelector('.wm-tab.active[data-module="calendar"]')
		// from the calendar: a new message
		await palette('nouveau mes')
		await page.keyboard.press('Enter')
		await page.waitForSelector('.wm-compose input[name=to]')
		assert.strictEqual(await page.getAttribute('#webmail-app', 'data-module'), 'mail')
		await page.click('.wm-compose button:has-text("Annuler")')
		// words of no command: « search for it in all the mail »
		await palette('maintenance annuelle')
		await page.waitForSelector('.wm-palette-empty:has-text("Aucune commande")')
		await page.waitForSelector('.wm-palette-item.active:has-text("Rechercher « maintenance annuelle » dans tout le courrier")')
		await page.keyboard.press('Enter')
		await page.waitForSelector('.wm-message:has-text("Devis pour la maintenance annuelle")')
		assert.strictEqual(await page.inputValue('.wm-search'), 'maintenance annuelle')
		// Escape closes, nothing done
		await palette('tach')
		await page.keyboard.press('Escape')
		await page.locator('.wm-palette-overlay').waitFor({ state: 'detached' })
		assert.strictEqual(await page.getAttribute('#webmail-app', 'data-module'), 'mail')
		// back to the inbox, no search
		await page.click('.wm-folder >> nth=0')
		await page.waitForSelector('.wm-message')
	})

	await step('instant tabs: the calendar, the tasks and the contacts are loaded while the mail is idle, then show at once', async () => {
		const ahead = ['/calendar/events', '/tasks?', '/contacts?'].map((part) => page.waitForResponse((r) => r.url().includes(part) && r.status() === 200, { timeout: 30000 }))
		await page.goto(NC + '/apps/webmail/?module=mail')
		await page.waitForSelector('.wm-message')
		await Promise.all(ahead)
		// from now on, Exchange is slow: what shows at once was loaded ahead
		const slow = async (route) => { await new Promise((r) => setTimeout(r, 4000)); await route.continue().catch(() => null) }
		await page.route(/\/calendar\/(events|calendars)/, slow)
		await page.route(/\/apps\/webmail\/api\/accounts\/\d+\/(tasks|contacts)(\?|\/books|$)/, slow)
		try {
			const shown = async (tab, selector) => {
				const t0 = Date.now()
				await page.click('.wm-tab[data-module="' + tab + '"]')
				await page.waitForSelector(selector, { timeout: 2000 })
				return Date.now() - t0
			}
			const times = {
				calendar: await shown('calendar', '.wm-event, .wm-agenda-item'),
				tasks: await shown('tasks', '.wm-task:has-text("Renouveler le nom de domaine")'),
				contacts: await shown('contacts', '.wm-contact'),
			}
			process.stdout.write('    shown in ' + JSON.stringify(times) + ' ms (Exchange answering in 4 s)\n')
		} finally {
			await page.unroute(/\/calendar\/(events|calendars)/)
			await page.unroute(/\/apps\/webmail\/api\/accounts\/\d+\/(tasks|contacts)(\?|\/books|$)/)
		}
		await page.click('.wm-tab[data-module="mail"]')
		await page.waitForSelector('.wm-message')
	})

	await step('keyboard: Tab stays in a dialog, the focus goes back to its button, fields have a name', async () => {
		await page.click('.wm-tab[data-module="calendar"]')
		await page.focus('.wm-compose-btn')
		await page.keyboard.press('Enter')
		await page.waitForSelector('.wm-modal input[name=subject]')
		const inDialog = () => page.evaluate(() => !!document.activeElement.closest('.wm-modal'))
		for (let i = 0; i < 30; i++) {
			await page.keyboard.press('Tab')
			assert.ok(await inDialog(), 'Tab ' + (i + 1) + ' stays in the dialog')
		}
		for (let i = 0; i < 30; i++) {
			await page.keyboard.press('Shift+Tab')
			assert.ok(await inDialog(), 'Shift+Tab ' + (i + 1) + ' stays in the dialog')
		}
		// the date and the hour of the start and the end each have their own name for a screen reader
		const names = await page.$$eval('.wm-modal input[type=date], .wm-modal input[type=time]', (els) => els.map((e) => e.getAttribute('aria-label')))
		assert.deepStrictEqual(names.slice(0, 4), ['Date de début', 'Heure de début', 'Date de fin', 'Heure de fin'])
		await page.keyboard.press('Escape')
		await page.waitForSelector('.wm-modal', { state: 'detached' })
		assert.ok(await page.evaluate(() => document.activeElement && document.activeElement.classList.contains('wm-compose-btn')), 'focus back on « Nouvel événement »')
		await page.click('.wm-tab[data-module="mail"]')
		await page.waitForSelector('.wm-message')
	})

	await step('undo send: a few seconds to change one\'s mind, then the message is back as it was', async () => {
		await page.evaluate(() => localStorage.removeItem('webmail.undoSend')) // the default: 5 s
		await page.click('.wm-tab[data-module="mail"]')
		const sends = []
		const cleanups = []
		const onRequest = (r) => { if (r.url().endsWith('/send') && r.method() === 'POST') sends.push(r) }
		page.on('request', onRequest)
		try {
			await page.click('.wm-compose-btn')
			await page.fill('input[name=to]', 'user@societe-a.fr')
			await page.fill('input[name=subject]', 'Finalement non')
			await page.fill('.wm-editor', 'Il manque la pièce jointe.')
			// « pièce jointe » but no file: a question first; « no » keeps the message open
			let asked = null
			page.once('dialog', (d) => { asked = d.message(); d.dismiss() })
			await page.click('.wm-compose button[type=submit]')
			await page.waitForFunction(() => document.activeElement && document.activeElement.classList.contains('wm-editor'))
			assert.ok(asked && asked.includes('aucun fichier n\'est joint'), String(asked))
			assert.ok(await page.$('.wm-compose'), 'still open')
			assert.strictEqual(sends.length, 0)
			// « send anyway », for the rest of this step
			const yes = (d) => d.accept()
			page.on('dialog', yes)
			cleanups.push(() => page.off('dialog', yes))
			await page.click('.wm-compose button[type=submit]')
			await page.waitForSelector('.wm-toast-undo:has-text("Envoi dans")')
			assert.strictEqual(await page.$('.wm-compose'), null, 'the message leaves the screen')
			await shot('70-undo-send')
			await page.click('.wm-toast-undo button:has-text("Annuler l\'envoi")')
			await page.waitForSelector('.wm-compose')
			assert.strictEqual(await page.inputValue('input[name=subject]'), 'Finalement non')
			assert.ok((await page.textContent('.wm-editor')).includes('Il manque la pièce jointe.'))
			await page.waitForTimeout(6000)
			assert.strictEqual(sends.length, 0, 'nothing sent')
			// sent once the delay is over… or at once
			const [sent] = await Promise.all([
				page.waitForResponse((r) => r.url().endsWith('/send') && r.request().method() === 'POST'),
				(async () => {
					await page.click('.wm-compose button[type=submit]')
					await page.click('.wm-toast-undo button:has-text("Envoyer maintenant")')
				})(),
			])
			assert.ok(sent.ok())
			await page.waitForSelector('.wm-toast:has-text("Message envoyé")')
			assert.strictEqual(sends.length, 1, 'sent once')
			// the setting, in « Comptes et signature »
			await page.click('text=Comptes et signature')
			assert.strictEqual(await page.inputValue('select[name=undoSend]'), '5')
			await page.selectOption('select[name=undoSend]', '0')
			assert.strictEqual(await page.evaluate(() => localStorage.getItem('webmail.undoSend')), '0')
		} finally {
			page.off('request', onRequest)
			cleanups.forEach((f) => f())
			await page.evaluate(() => localStorage.setItem('webmail.undoSend', '0'))
		}
		await page.click('.wm-folder >> nth=0')
		await page.waitForSelector('.wm-message')
	})

	await step('reply templates: a text kept as a template, inserted in another message, renamed, deleted', async () => {
		await page.click('.wm-tab[data-module="mail"]')
		await page.click('.wm-compose-btn')
		await page.fill('.wm-editor', 'Bonjour, avez-vous pu consulter notre devis ? Bien cordialement.')
		await page.click('.wm-editor-btn[title="Modèles de réponse"]')
		await page.waitForSelector('.wm-template-menu p:has-text("Aucun modèle")')
		page.once('dialog', (d) => d.accept('Relance devis'))
		await page.click('.wm-template-save')
		await page.waitForSelector('.wm-toast:has-text("Modèle « Relance devis » enregistré")')
		await page.click('.wm-compose button:has-text("Annuler")')
		// another message: the template goes where the cursor is, the signature stays below
		await page.click('.wm-compose-btn')
		await page.click('.wm-editor')
		await page.keyboard.press('Control+Home') // the top of the message, above the signature
		await page.keyboard.type('Madame, ')
		await page.click('.wm-editor-btn[title="Modèles de réponse"]')
		await page.click('.wm-template-item:has-text("Relance devis")')
		// spaces typed in an editor may come out as non-breaking ones
		const text = (await page.textContent('.wm-editor')).replace(/\s+/g, ' ')
		assert.ok(/^Madame, Bonjour, avez-vous pu consulter notre devis \? Bien cordialement\. ?--/.test(text), JSON.stringify(text))
		await shot('71-templates')
		await page.click('.wm-compose button:has-text("Annuler")')
		// managed in « Comptes et signature »
		await page.click('text=Comptes et signature')
		await page.waitForSelector('.wm-template-list li:has-text("Relance devis") .wm-template-preview:has-text("avez-vous pu consulter")')
		page.once('dialog', (d) => d.accept('Relance du devis'))
		await page.click('.wm-template-list li:has-text("Relance devis") button:has-text("Renommer")')
		await page.waitForSelector('.wm-template-list li:has-text("Relance du devis")')
		page.once('dialog', (d) => d.accept())
		await page.click('.wm-template-list li:has-text("Relance du devis") button:has-text("Supprimer")')
		await page.waitForSelector('.wm-template-list li:has-text("Aucun modèle")')
		await page.click('.wm-folder >> nth=0')
		await page.waitForSelector('.wm-message')
	})

	await step('mailto: an address in a message, and a mailto: link of another site, open a new message here', async () => {
		await page.click('.wm-tab[data-module="mail"]')
		await page.click('.wm-folder >> nth=0')
		await page.click('.wm-message:has-text("Votre facture OVH")')
		await page.waitForSelector('.wm-body-frame')
		await page.frameLocator('.wm-body-frame').locator('a:has-text("facturation@ovh.example")').click()
		await page.waitForSelector('.wm-compose')
		assert.strictEqual(await page.inputValue('input[name=to]'), 'facturation@ovh.example, ')
		assert.strictEqual(await page.inputValue('input[name=subject]'), 'Facture OVH')
		assert.ok((await page.textContent('.wm-editor')).startsWith('Bonjour,'))
		assert.strictEqual(page.context().pages().length, 1, 'no other window')
		await page.click('.wm-compose button:has-text("Annuler")')
		// the webmail as the browser's mail program: …/apps/webmail/?mailto=<the link>
		const link = 'mailto:paul@x.fr,anne@x.fr?cc=claire@x.fr&subject=Devis%20toiture&body=Bonjour%2C%0D%0Avoici%20le%20devis.'
		await page.goto(NC + '/apps/webmail/?mailto=' + encodeURIComponent(link))
		await page.waitForSelector('.wm-compose')
		assert.strictEqual(await page.inputValue('input[name=to]'), 'paul@x.fr, anne@x.fr, ')
		assert.strictEqual(await page.inputValue('input[name=cc]'), 'claire@x.fr, ')
		assert.strictEqual(await page.inputValue('input[name=subject]'), 'Devis toiture')
		assert.ok((await page.textContent('.wm-editor')).includes('voici le devis.'))
		assert.ok(!page.url().includes('mailto'), 'the address bar is tidied')
		await page.click('.wm-compose button:has-text("Annuler")')
		// the setting
		await page.click('text=Comptes et signature')
		await page.waitForSelector('.wm-mailto-register')
		await page.click('.wm-folder >> nth=0')
		await page.waitForSelector('.wm-message')
	})

	await step('a message as an .eml file: downloaded, saved in Files, imported into a folder', async () => {
		await page.click('.wm-tab[data-module="mail"]')
		await page.click('.wm-folder >> nth=0')
		await page.click('.wm-message:has-text("Votre facture OVH")')
		await page.waitForSelector('.wm-message-header h2:has-text("Votre facture OVH")')
		const [download] = await Promise.all([page.waitForEvent('download'), page.click('.wm-eml-btn')])
		assert.strictEqual(download.suggestedFilename(), 'Votre facture OVH.eml')
		const eml = fs.readFileSync(await download.path(), 'utf8')
		assert.ok(/^Subject: Votre facture OVH$/m.test(eml) && /^From: OVHcloud <noreply@ovh\.example>$/m.test(eml), eml.slice(0, 300))
		await page.evaluate(() => { OC.dialogs.filepicker = (t, cb) => cb('/Documents') })
		await page.click('.wm-eml-save-btn')
		await page.waitForSelector('.wm-toast:has-text("Enregistré dans /Documents/Votre facture OVH.eml")')

		// and back: the .eml imported into another folder, as a received message (not a draft)
		const row = (name) => '.wm-folder-row:has(.wm-folder-name:text-is("' + name + '"))'
		await page.hover(row('Chantier Antibes'))
		await page.click(row('Chantier Antibes') + ' .wm-folder-menu-btn')
		const [chooser] = await Promise.all([page.waitForEvent('filechooser'), page.click('.wm-folder-menu button:has-text("Importer des messages (.eml)")')])
		await chooser.setFiles(await download.path().then((p) => { const copy = p + '.eml'; fs.copyFileSync(p, copy); return copy }))
		await page.waitForSelector('.wm-toast:has-text("1 message importé dans « Chantier Antibes »")')
		await page.click('.wm-folder:has-text("Chantier Antibes")')
		await page.waitForSelector('.wm-message:not(.unread):has-text("Votre facture OVH")')
		await page.click('.wm-message:has-text("Votre facture OVH")')
		await page.waitForSelector('.wm-message-header h2:has-text("Votre facture OVH")')
		assert.strictEqual(await page.$('.wm-compose'), null, 'opened as a message, not as a draft')
		// a file that is not a message
		await page.hover(row('Chantier Antibes'))
		await page.click(row('Chantier Antibes') + ' .wm-folder-menu-btn')
		const [chooser2] = await Promise.all([page.waitForEvent('filechooser'), page.click('.wm-folder-menu button:has-text("Importer des messages (.eml)")')])
		await chooser2.setFiles({ name: 'notes.eml', mimeType: 'message/rfc822', buffer: Buffer.from('Juste du texte, pas un message.') })
		await page.waitForSelector('.wm-toast:has-text("notes.eml : Ce fichier n\'est pas un message (.eml)")')
		await page.click('.wm-folder >> nth=0')
		await page.waitForSelector('.wm-message')
	})

	await step('folder menu: mark all as read, in one request', async () => {
		await page.click('.wm-tab[data-module="mail"]')
		const row = (name) => '.wm-folder-row:has(.wm-folder-name:text-is("' + name + '"))'
		await page.click('.wm-folder:has-text("Factures")')
		await page.waitForSelector('.wm-message.unread')
		await page.hover(row('Factures'))
		await page.click(row('Factures') + ' .wm-folder-menu-btn')
		const [req] = await Promise.all([
			page.waitForRequest((r) => r.url().endsWith('/folders/read') && r.method() === 'POST'),
			page.click('.wm-folder-menu button:has-text("Tout marquer comme lu")'),
		])
		assert.ok(req)
		await page.waitForSelector('.wm-toast:has-text("Tous les messages de « Factures » sont lus")')
		assert.strictEqual(await page.$$eval('.wm-message.unread', (els) => els.length), 0, 'at once on screen')
		assert.strictEqual(await page.$(row('Factures') + ' .wm-folder-count, ' + row('Factures') + ' .wm-badge'), null, 'no unread count left')
		// and in Exchange: still read after a reload
		await page.reload()
		await page.click('.wm-folder:has-text("Factures")')
		await page.waitForSelector('.wm-message')
		await page.waitForTimeout(1500) // the list is refreshed from Exchange
		assert.strictEqual(await page.$$eval('.wm-message.unread', (els) => els.length), 0)
		// a folder without unread messages does not offer it
		await page.hover(row('Factures'))
		await page.click(row('Factures') + ' .wm-folder-menu-btn')
		assert.strictEqual(await page.$('.wm-folder-menu button:has-text("Tout marquer comme lu")'), null)
		await page.keyboard.press('Escape')
		await page.click('.wm-folder >> nth=0')
		await page.waitForSelector('.wm-message')
	})

	await step('the sender of a message added to the contacts in one click (not twice)', async () => {
		await page.click('.wm-tab[data-module="mail"]')
		await page.click('.wm-folder >> nth=0')
		// already a contact
		await page.click('.wm-message:has-text("Devis pour la maintenance annuelle")')
		await page.waitForSelector('.wm-message-header .wm-add-contact')
		await page.click('.wm-message-header .wm-add-contact')
		await page.waitForSelector('.wm-toast:has-text("Jean Dupont est déjà dans vos contacts")')
		// a new one
		await page.click('.wm-message:has-text("Votre facture OVH")')
		await page.waitForSelector('.wm-message-header h2:has-text("Votre facture OVH")')
		await page.click('.wm-message-header .wm-add-contact')
		await page.waitForSelector('.wm-toast:has-text("OVHcloud ajouté à vos contacts")')
		await page.click('.wm-tab[data-module="contacts"]')
		await page.waitForSelector('.wm-contact:has-text("OVHcloud") :text("noreply@ovh.example")')
		await page.click('.wm-tab[data-module="mail"]')
		await page.waitForSelector('.wm-message')
	})

	await step('an event from a message: its subject, its text, its people (not me) as attendees', async () => {
		await page.click('.wm-tab[data-module="mail"]')
		await page.click('.wm-folder >> nth=0')
		await page.click('.wm-message:has-text("Devis pour la maintenance annuelle")')
		await page.waitForSelector('.wm-message-header h2:has-text("Devis pour la maintenance annuelle")')
		await page.click('.wm-event-from-msg')
		await page.waitForSelector('.wm-tab.active[data-module="calendar"]')
		await page.waitForSelector('.wm-modal input[name=subject]')
		assert.strictEqual(await page.inputValue('.wm-modal input[name=subject]'), 'Devis pour la maintenance annuelle')
		let attendees = (await page.inputValue('.wm-modal input[name=attendees]')).split(/,\s*/)
		assert.deepStrictEqual(attendees, ['jean.dupont@client-exemple.fr'], 'the sender, not me')
		const body = await page.inputValue('.wm-modal textarea[name=body]')
		assert.ok(body.startsWith('Message de Jean Dupont <jean.dupont@client-exemple.fr>') && body.includes('Bonjour,\nVeuillez trouver ci-joint notre devis pour la maintenance 2027.'), body)
		assert.ok(!body.includes('alert('), 'no script text')
		await shot('72-event-from-message')
		await page.click('.wm-modal button:has-text("Annuler")')
		// a message I sent: « Re: » left out of the subject, its recipient invited
		await page.click('.wm-tab[data-module="mail"]')
		await page.click('.wm-folder:has-text("Éléments envoyés")')
		await page.click('.wm-message:has-text("Re: Planning")')
		await page.waitForSelector('.wm-message-header h2:has-text("Re: Planning")')
		await page.click('.wm-event-from-msg')
		await page.waitForSelector('.wm-modal input[name=subject]')
		assert.strictEqual(await page.inputValue('.wm-modal input[name=subject]'), 'Planning')
		attendees = (await page.inputValue('.wm-modal input[name=attendees]')).split(/,\s*/)
		assert.deepStrictEqual(attendees, ['marie.martin@societe-a.fr'])
		await page.click('.wm-modal button:has-text("Annuler")')
		await page.click('.wm-tab[data-module="mail"]')
		await page.click('.wm-folder >> nth=0')
		await page.waitForSelector('.wm-message')
	})

	await step('a task from a message: its subject as the title, its sender and text in the notes, saved in Exchange', async () => {
		await page.click('.wm-message:has-text("Devis pour la maintenance annuelle")')
		await page.waitForSelector('.wm-message-header h2:has-text("Devis pour la maintenance annuelle")')
		await page.click('.wm-task-from-msg')
		await page.waitForSelector('.wm-tab.active[data-module="tasks"]')
		await page.waitForSelector('.wm-task-form input[name=subject]')
		assert.strictEqual(await page.inputValue('.wm-task-form input[name=subject]'), 'Devis pour la maintenance annuelle')
		const notes = await page.inputValue('.wm-task-form textarea[name=notes]')
		assert.ok(notes.startsWith('Message de Jean Dupont <jean.dupont@client-exemple.fr>') && notes.includes('Bonjour,\nVeuillez trouver ci-joint notre devis'), notes)
		await page.click('.wm-task-form button:has-text("Demain")')
		await shot('73-task-from-message')
		await page.click('.wm-task-form button:has-text("Créer")')
		await page.waitForSelector('.wm-toast:has-text("Tâche créée")')
		const task = '.wm-task:has-text("Devis pour la maintenance annuelle")'
		await page.waitForSelector(task + ' .wm-task-due:has-text("Demain")')
		// saved with its notes
		await page.click('.wm-task:has-text("Renouveler le nom de domaine") .wm-task-subject')
		await page.waitForFunction(() => document.querySelector('.wm-task-form input[name=subject]')?.value === 'Renouveler le nom de domaine')
		await page.click(task + ' .wm-task-subject')
		await page.waitForFunction(() => (document.querySelector('.wm-task-form textarea[name=notes]')?.value || '').includes('notre devis pour la maintenance 2027'))
		page.once('dialog', (d) => d.accept())
		await page.click('.wm-task-form button:has-text("Supprimer")')
		await page.waitForSelector('.wm-toast:has-text("Tâche supprimée")')
		await page.locator(task).waitFor({ state: 'detached' })
		await page.click('.wm-tab[data-module="mail"]')
		await page.waitForSelector('.wm-message')
	})

	await step('Internet headers: the SPF, DKIM and DMARC checks, the way of the message, every header', async () => {
		await page.click('.wm-message:has-text("Devis pour la maintenance annuelle")')
		await page.waitForSelector('.wm-message-header h2:has-text("Devis pour la maintenance annuelle")')
		await page.click('.wm-headers-btn')
		await page.waitForSelector('.wm-headers .wm-auth-checks')
		for (const k of ['spf', 'dkim', 'dmarc']) await page.waitForSelector('.wm-headers .wm-auth-pass[data-check="' + k + '"]')
		assert.ok((await page.textContent('.wm-auth[data-check="dkim"]')).includes('DKIM : réussi'))
		assert.strictEqual(await page.locator('.wm-auth-warning').count(), 0)
		// the sender's server first, then 2 seconds to the mailbox server
		const hops = await page.locator('.wm-hops li').allTextContents()
		assert.strictEqual(hops.length, 2, hops.join(' | '))
		assert.ok(hops[0].startsWith('mail.client-exemple.fr → mx1.mail.ovh.net'), hops[0])
		assert.ok(hops[1].startsWith('EX3.mail.ovh.net → EX3.mail.ovh.net') && hops[1].includes('(+2 s)'), hops[1])
		await page.click('.wm-headers-all summary:has-text("Tous les en-têtes (6)")')
		await page.waitForSelector('.wm-headers-all tr:has(th:text-is("Authentication-Results")) td:has-text("dkim=pass header.d=client-exemple.fr")')
		await shot('74-internet-headers')
		await page.keyboard.press('Escape')
		await page.locator('.wm-overlay').waitFor({ state: 'detached' })
		assert.ok(await page.evaluate(() => document.activeElement && document.activeElement.classList.contains('wm-headers-btn')), 'the focus goes back to the button')

		// a forged-looking sender: a warning
		await page.click('.wm-message:has(.wm-message-subject:text-is("Newsletter n°1"))')
		await page.waitForSelector('.wm-message-header h2:has-text("Newsletter n°1")')
		await page.click('.wm-headers-btn')
		await page.waitForSelector('.wm-headers .wm-auth-fail[data-check="dkim"]')
		await page.waitForSelector('.wm-headers .wm-auth-fail[data-check="dmarc"]')
		await page.waitForSelector('.wm-headers .wm-auth-pass[data-check="spf"]')
		await page.waitForSelector('.wm-auth-warning:has-text("ce message peut être une usurpation")')
		await page.click('.wm-headers .wm-modal-close')
		await page.locator('.wm-overlay').waitFor({ state: 'detached' })

		// a message I sent: written here, no Internet headers
		await page.click('.wm-folder:has-text("Éléments envoyés")')
		await page.click('.wm-message:has-text("Re: Planning")')
		await page.waitForSelector('.wm-message-header h2:has-text("Re: Planning")')
		await page.click('.wm-headers-btn')
		await page.waitForSelector('.wm-headers:has-text("Ce message n\'a pas d\'en-têtes Internet")')
		await page.keyboard.press('Escape')
		await page.locator('.wm-overlay').waitFor({ state: 'detached' })
		await page.click('.wm-folder >> nth=0')
		await page.waitForSelector('.wm-message')
	})

	await step('follow-up flag with a due date: tomorrow, a chosen date, then cleared; kept by Exchange', async () => {
		const ymd = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0')
		const now = new Date()
		const tomorrow = ymd(new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1))
		const row = '.wm-message:has(.wm-message-subject:text-is("Newsletter n°2"))'
		await page.click(row)
		await page.waitForSelector('.wm-message-header h2:has-text("Newsletter n°2")')
		await page.click('.wm-flag-menu-btn')
		await page.waitForSelector('.wm-flag-menu [data-choice="today"]')
		assert.strictEqual(await page.locator('.wm-flag-menu [data-choice="clear"]').count(), 0, 'not flagged yet')
		await shot('75-flag-menu')
		const sent = page.waitForRequest((r) => r.url().endsWith('/flags') && r.method() === 'POST')
		await page.click('.wm-flag-menu [data-choice="tomorrow"]')
		assert.deepStrictEqual(JSON.parse((await sent).postData()), { itemIds: [await page.getAttribute(row, 'data-id')], flag: 'flagged', value: true, due: tomorrow })
		await page.waitForSelector('.wm-flag-btn.on:has-text("Suivi : Demain")')
		await page.waitForSelector(row + ' .wm-flag.on')
		await page.waitForSelector(row + ' .wm-flag-due:has-text("Demain")')

		// kept by Exchange: after a reload, from the server
		const listed = page.waitForResponse((r) => /\/api\/accounts\/\d+\/(messages|conversations)(\?|$)/.test(r.url()) && r.status() === 200)
		await page.reload()
		await listed
		await page.waitForSelector(row + ' .wm-flag-due:has-text("Demain")')

		// a date of one's own
		const later = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 10)
		await page.click(row)
		await page.waitForSelector('.wm-message-header h2:has-text("Newsletter n°2")')
		await page.click('.wm-flag-menu-btn')
		await page.fill('.wm-flag-menu input[name=flagDue]', ymd(later))
		await page.click('.wm-flag-menu button:has-text("OK")')
		const label = later.toLocaleDateString('fr', { weekday: 'short', day: 'numeric', month: 'short' })
		await page.waitForSelector(row + ' .wm-flag-due:has-text("' + label + '")')
		await page.waitForSelector('.wm-flag-btn:has-text("Suivi : ' + label + '")')

		// no more follow-up
		await page.click('.wm-flag-menu-btn')
		await page.click('.wm-flag-menu [data-choice="clear"]')
		await page.waitForFunction(() => document.querySelector('.wm-flag-btn')?.textContent === '⚑ Suivi')
		await page.locator(row + ' .wm-flag-due').waitFor({ state: 'detached' })
		assert.ok(!(await page.$(row + ' .wm-flag.on')))
	})

	await step('snooze: a message leaves the inbox for « Reportés », then comes back unread at its time (background job)', async () => {
		const occ = (args) => execSync(OCC + ' ' + args, { encoding: 'utf8' })
		const jobId = (occ("background-job:list --class='OCA\\Webmail\\BackgroundJob\\SnoozeJob'").match(/\|\s*(\d+)\s*\|\s*OCA/) || [])[1]
		assert(jobId, 'background job registered')
		const row = '.wm-message:has(.wm-message-subject:text-is("Newsletter n°4"))'
		await page.click('.wm-folder >> nth=0')
		await page.click(row)
		await page.waitForSelector('.wm-message-header h2:has-text("Newsletter n°4")')
		await page.click('.wm-snooze-btn')
		await page.waitForSelector('.wm-snooze-menu [data-choice="tomorrow"]:has-text("demain à 08:00")')
		await shot('76-snooze-menu')
		await page.click('.wm-snooze-menu [data-choice="tomorrow"]')
		await page.waitForSelector('.wm-toast:has-text("Message reporté : il reviendra demain à 08:00")')
		await page.locator(row).waitFor({ state: 'detached' })

		// in « Reportés », with the time it comes back
		await page.click('.wm-folder:has-text("Reportés")')
		await page.waitForSelector(row + ' .wm-snooze-due:has-text("demain à 08:00")')
		await page.click(row)
		await page.waitForSelector('.wm-snooze-banner:has-text("revient dans la boîte de réception demain à 08:00")')

		// its time has come: the background job puts it back, unread
		const key = (occ('user:setting admin webmail').match(/(snooze_\d+):/) || [])[1]
		assert(key, 'kept in the preferences: ' + occ('user:setting admin webmail'))
		const list = JSON.parse(occ('user:setting admin webmail ' + key).trim())
		assert.strictEqual(list.length, 1)
		list[0].until = '2020-01-01T00:00:00Z'
		occ('user:setting admin webmail ' + key + " '" + JSON.stringify(list) + "'")
		occ('background-job:execute ' + jobId + ' --force-execute')
		assert.ok(!occ('user:setting admin webmail').includes(key + ':'), 'forgotten once back')
		await page.click('.wm-folder >> nth=0')
		await page.click('button[aria-label="Actualiser"]')
		await page.waitForSelector(row + '.unread')

		// put aside till a date of one's own, then back now
		await page.click(row)
		await page.waitForSelector('.wm-message-header h2:has-text("Newsletter n°4")')
		await page.click('.wm-snooze-btn')
		const d = new Date(Date.now() + 3 * 86400000)
		const pad = (n) => String(n).padStart(2, '0')
		await page.fill('.wm-snooze-menu input[name=snoozeUntil]', d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T14:30')
		await page.click('.wm-snooze-menu button:has-text("OK")')
		await page.waitForSelector('.wm-toast:has-text("à 14:30")')
		await page.locator(row).waitFor({ state: 'detached' })
		await page.click('.wm-folder:has-text("Reportés")')
		await page.click(row)
		await page.click('.wm-unsnooze-btn')
		await page.waitForSelector('.wm-toast:has-text("Message remis dans la boîte de réception")')
		await page.locator(row).waitFor({ state: 'detached' })
		assert.ok(!occ('user:setting admin webmail').includes(key + ':'))
		await page.click('.wm-folder >> nth=0')
		await page.waitForSelector(row)
	})

	await step('undo: a message moved or deleted by mistake goes back to its folder with « Annuler »', async () => {
		const row = '.wm-message:has(.wm-message-subject:text-is("Newsletter n°5"))'
		await page.click('.wm-folder >> nth=0')
		await page.click(row)
		await page.waitForSelector('.wm-message-header h2:has-text("Newsletter n°5")')
		// moved to « Factures », then undone
		await page.selectOption('.wm-move', { label: 'Factures' })
		await page.waitForSelector('.wm-toast:has-text("Message déplacé vers « Factures »") .wm-toast-action:has-text("Annuler")')
		await page.locator(row).waitFor({ state: 'detached' })
		await shot('80-undo')
		await page.click('.wm-toast .wm-toast-action:has-text("Annuler")')
		await page.waitForSelector('.wm-toast:has-text("Annulé")')
		await page.waitForSelector(row)
		await page.click('.wm-folder:has-text("Factures")')
		await page.waitForSelector('.wm-message')
		assert.strictEqual(await page.locator(row).count(), 0, 'not in « Factures »')
		// deleted, then undone
		await page.click('.wm-folder >> nth=0')
		await page.click(row)
		await page.waitForSelector('.wm-message-header h2:has-text("Newsletter n°5")')
		await page.click('.wm-toolbar button:has-text("Supprimer")')
		await page.waitForSelector('.wm-toast:has-text("Message placé dans les éléments supprimés") .wm-toast-action')
		await page.locator(row).waitFor({ state: 'detached' })
		await page.click('.wm-toast:has-text("Message placé dans les éléments supprimés") .wm-toast-action')
		await page.waitForSelector(row)
		await page.click('.wm-folder:has-text("Éléments supprimés")')
		await page.waitForSelector('.wm-message, .wm-empty')
		assert.strictEqual(await page.locator(row).count(), 0, 'not in the trash')
		await page.click('.wm-folder >> nth=0')
		await page.waitForSelector(row)
	})

	await step('quick reply: a few lines under the message, sent with Ctrl+Enter, or « Plus d\'options… » with what was typed', async () => {
		const row = '.wm-message:has(.wm-message-subject:text-is("Newsletter n°6"))'
		await page.click('.wm-folder >> nth=0')
		await page.click(row)
		await page.waitForSelector('.wm-message-header h2:has-text("Newsletter n°6")')
		assert.strictEqual(await page.getAttribute('.wm-quick-text', 'placeholder'), 'Répondre à Lettre d\'info…')
		// « Plus d'options… »: the full form, with the text
		await page.click('.wm-quick-text')
		await page.keyboard.type('Merci, à lire plus tard.')
		await page.click('.wm-quick-more')
		await page.waitForSelector('.wm-compose .wm-editor:has-text("Merci, à lire plus tard.")')
		assert.strictEqual(await page.inputValue('.wm-compose input[name=subject]'), 'RE : Newsletter n°6')
		await page.click('.wm-compose button:has-text("Annuler")')
		// sent from the box (Ctrl+Enter)
		await page.click(row)
		await page.waitForSelector('.wm-message-header h2:has-text("Newsletter n°6")')
		await page.click('.wm-quick-text')
		await page.keyboard.type('Bien reçu, merci.\nBonne journée')
		await shot('81-quick-reply')
		await page.keyboard.press('Control+Enter')
		await page.waitForSelector('.wm-toast:has-text("Message envoyé")')
		assert.strictEqual(await page.inputValue('.wm-quick-text'), '')
		await page.click('.wm-folder:has-text("Éléments envoyés")')
		await page.click('.wm-message:has-text("RE: Newsletter n°6")')
		await page.waitForSelector('.wm-message-header h2:has-text("RE: Newsletter n°6")')
		const body = await page.frameLocator('.wm-body-frame').locator('body').textContent()
		assert.ok(body.includes('Bien reçu, merci.') && body.includes('Bonne journée'), body)
		await page.click('.wm-folder >> nth=0')
		await page.waitForSelector('.wm-message')
	})

	await step('trusted senders: « Toujours pour… » shows a sender\'s remote images at once, until removed', async () => {
		const row = '.wm-message:has-text("Devis pour la maintenance annuelle")'
		const tracker = () => page.evaluate(() => !!document.querySelector('.wm-body-frame').contentDocument.querySelector('img[src*="tracker.example"]'))
		await page.click('.wm-folder >> nth=0')
		await page.click(row)
		await page.waitForSelector('.wm-remote-banner .wm-trust-sender:has-text("Toujours pour jean.dupont@client-exemple.fr")')
		await page.waitForSelector('.wm-remote-banner .wm-trust-domain:has-text("Toujours pour @client-exemple.fr")')
		await page.waitForSelector('.wm-body-frame')
		assert.strictEqual(await tracker(), false, 'blocked')
		await page.click('.wm-trust-sender')
		await page.waitForSelector('.wm-toast:has-text("Les images de jean.dupont@client-exemple.fr s\'afficheront désormais directement")')
		await page.waitForSelector('.wm-trusted-banner:has-text("jean.dupont@client-exemple.fr fait partie de vos expéditeurs de confiance")')
		await page.locator('.wm-remote-banner').waitFor({ state: 'detached' })
		await page.waitForFunction(() => { const f = document.querySelector('.wm-body-frame'); return f && f.contentDocument && f.contentDocument.querySelector('img[src*="tracker.example"]') })
		await shot('83-trusted-sender')
		// in the settings, removed: blocked again
		await page.click('.wm-trusted-banner button:has-text("Gérer")')
		await page.waitForSelector('.wm-trusted-list li[data-entry="jean.dupont@client-exemple.fr"]')
		await page.click('.wm-trusted-list li[data-entry="jean.dupont@client-exemple.fr"] button')
		await page.waitForSelector('.wm-trusted-list li.wm-muted')
		await page.click('.wm-folder >> nth=0')
		await page.click(row)
		await page.waitForSelector('.wm-remote-banner .wm-trust-sender')
		await page.waitForSelector('.wm-body-frame')
		assert.strictEqual(await tracker(), false, 'blocked again')
	})

	await step('unsubscribe: « Se désabonner » of a mailing list, by a message sent for the user, or on its page', async () => {
		const open = async (n) => {
			await page.click('.wm-folder >> nth=0')
			await page.click('.wm-message:has(.wm-message-subject:text-is("Newsletter n°' + n + '"))')
			await page.waitForSelector('.wm-message-header h2:has-text("Newsletter n°' + n + '")')
		}
		// an ordinary message: nothing
		await page.click('.wm-folder >> nth=0')
		await page.click('.wm-message:has-text("Devis pour la maintenance annuelle")')
		await page.waitForSelector('.wm-message-header h2:has-text("Devis pour la maintenance annuelle")')
		assert.strictEqual(await page.$('.wm-unsub-banner'), null)
		// a page to open only: in a new tab, nothing sent
		await page.evaluate(() => { window.__opened = []; window.open = (u) => { window.__opened.push(u); return null } })
		await open(21)
		await page.waitForSelector('.wm-unsub-banner button.wm-unsub:has-text("Se désabonner ↗")')
		await page.click('.wm-unsub')
		assert.deepStrictEqual(await page.evaluate(() => window.__opened), ['https://exemple.org/desinscription?n=21'])
		// by a message: asked first, then sent from the mailbox
		await open(20)
		await page.waitForSelector('.wm-unsub-banner:has-text("Ce message vient d\'une liste de diffusion.") button.wm-unsub:text-is("Se désabonner")')
		await shot('85-unsubscribe')
		let question = ''
		page.once('dialog', (d) => { question = d.message(); d.dismiss() })
		await page.click('.wm-unsub')
		await page.waitForFunction(() => !document.querySelector('.wm-unsub').disabled)
		assert.ok(question.includes('desinscription@exemple.org'), question)
		page.once('dialog', (d) => d.accept())
		await page.click('.wm-unsub')
		await page.waitForSelector('.wm-toast:has-text("Désabonnement demandé")')
		await page.waitForSelector('.wm-unsub-banner:has-text("Désabonnement demandé. Il peut falloir quelques jours")')
		// another message of the same list says so
		await open(22)
		await page.waitForSelector('.wm-unsub-banner:has-text("Désabonnement demandé")')
		await page.click('.wm-folder:has-text("Éléments envoyés")')
		await page.waitForSelector('.wm-message:has-text("Desinscription lettre d\'info")')
	})

	await step('recent recipients: the people written to come first in the completion, from the first letter', async () => {
		await page.click('.wm-folder >> nth=0')
		await page.click('.wm-compose-btn')
		await page.fill('input[name=to]', 'Claire Martin <claire.martin@exemple.net>')
		await page.fill('input[name=subject]', 'Planning de la semaine')
		await page.fill('.wm-editor', 'Bonjour Claire, voici le planning.')
		await submitCompose()
		const resolves = []
		const onRequest = (r) => { if (r.url().includes('/contacts/resolve')) resolves.push(r.url()) }
		page.on('request', onRequest)
		try {
			await page.click('.wm-compose-btn')
			await page.click('input[name=to]')
			await page.keyboard.type('c')
			// at once, without asking Exchange (one letter): the recent ones only
			await page.waitForSelector('.wm-ac-item:has-text("Claire Martin"):has-text("claire.martin@exemple.net · récent")', { timeout: 2000 })
			const first = await page.$$eval('.wm-ac-item', (els) => els.map((e) => e.textContent))
			assert.ok(first.length <= 5 && first.every((t) => t.endsWith('· récent')), first.join(' | '))
			assert.deepStrictEqual(resolves, [])
			await shot('84-recent-recipients')
			// a few more letters: the one meant, first (before the contacts and the directory)
			await page.keyboard.type('laire m')
			await page.waitForSelector('.wm-ac-item.active:has-text("claire.martin@exemple.net · récent")')
			await page.keyboard.press('Enter')
			assert.strictEqual(await page.inputValue('input[name=to]'), 'Claire Martin <claire.martin@exemple.net>, ')
		} finally {
			page.off('request', onRequest)
		}
		await page.click('.wm-compose button:has-text("Annuler")')
		await page.waitForSelector('.wm-compose', { state: 'detached' })
	})

	await step('list: an answer asked for before a change, arriving after it, does not undo it', async () => {
		const row = '.wm-message:has(.wm-message-subject:text-is("Newsletter n°30"))'
		await page.click('.wm-folder >> nth=0')
		await page.waitForSelector(row + ':not(.unread)')
		// the next list answer held back 1.5 s (a slow Exchange)
		let held = 0
		let fetched = false
		const hold = async (route) => {
			held++
			// asked now, answered late: the answer is the folder as it was before the change
			const response = await route.fetch()
			fetched = true
			await new Promise((r) => setTimeout(r, 1500))
			await route.fulfill({ response })
		}
		await page.route('**/api/accounts/*/messages?*', hold)
		try {
			await page.click('.wm-list-header button[title="Actualiser"]')
			for (let i = 0; i < 100 && !fetched; i++) await page.waitForTimeout(50)
			assert.ok(fetched, 'the list asked for')
			// meanwhile: marked unread
			await page.click(row + ' .wm-check')
			await page.click('.wm-bulk button:has-text("Non lu")')
			await page.waitForSelector(row + '.unread')
			await page.waitForFunction(() => !document.querySelector('.wm-list .icon-loading'))
			await page.waitForTimeout(1800)
			assert.ok(held > 0, 'a list answer was held back')
			assert.ok(await page.$(row + '.unread'), 'still unread after the late answer')
		} finally {
			await page.unroute('**/api/accounts/*/messages?*', hold)
		}
		await page.click(row + ' .wm-check')
		await page.click('.wm-bulk button:text-is("Lu")')
		await page.waitForSelector(row + ':not(.unread)')
	})

	await step('touch: a message swiped to the right is read / unread, to the left goes to the trash (with « Annuler »)', async () => {
		const row = '.wm-message:has(.wm-message-subject:text-is("Newsletter n°2"))'
		// a finger on the row, moved by a fraction of its width (touch events, as a phone sends them)
		const swipe = (fraction) => page.locator(row).evaluate((el, f) => {
			const r = el.getBoundingClientRect()
			const x = r.left + r.width / 2
			const y = r.top + r.height / 2
			const t = (cx) => new Touch({ identifier: 1, target: el, clientX: cx, clientY: y })
			el.dispatchEvent(new TouchEvent('touchstart', { touches: [t(x)], bubbles: true, cancelable: true }))
			for (let i = 1; i <= 10; i++) el.dispatchEvent(new TouchEvent('touchmove', { touches: [t(x + r.width * f * i / 10)], bubbles: true, cancelable: true }))
			el.dispatchEvent(new TouchEvent('touchend', { touches: [], changedTouches: [t(x + r.width * f)], bubbles: true, cancelable: true }))
		}, fraction)
		await page.click('.wm-folder >> nth=0')
		await page.waitForSelector(row)
		assert.ok(!(await page.$(row + '.unread')), 'read to start with')
		// not far enough: nothing
		await swipe(0.2)
		await page.waitForTimeout(300)
		assert.ok(!(await page.$(row + '.unread')))
		// to the right: unread, then read again
		await swipe(0.5)
		await page.waitForSelector(row + '.unread')
		await page.waitForSelector('.wm-toast:has-text("1 message marqué comme non lu")')
		await swipe(0.5)
		await page.waitForSelector(row + ':not(.unread)')
		// to the left: to the trash, and back with « Annuler »
		await swipe(-0.5)
		await page.locator(row).waitFor({ state: 'detached' })
		await page.click('.wm-toast:has-text("Message placé dans les éléments supprimés") .wm-toast-action')
		await page.waitForSelector(row)
		assert.strictEqual(await page.locator('.wm-message.wm-swiping').count(), 0)
	})

	await step('offline: a banner, what is loaded stays readable, back by itself when the network returns', async () => {
		const row = '.wm-message:has-text("Devis pour la maintenance annuelle")'
		await page.click('.wm-folder >> nth=0')
		await page.click(row)
		await page.waitForSelector('.wm-message-header h2:has-text("Devis pour la maintenance annuelle")')
		await page.context().setOffline(true)
		try {
			await page.click('.wm-list-header button[title="Actualiser"]')
			await page.waitForSelector('.wm-offline:has-text("Hors connexion : les messages déjà chargés restent lisibles.")')
			await page.waitForSelector('.wm-offline:has-text("Nouvel essai dans")')
			// the list and the message already loaded are still there
			await page.waitForSelector(row)
			await page.click(row)
			await page.waitForSelector('.wm-message-header h2:has-text("Devis pour la maintenance annuelle")')
			await page.waitForSelector('.wm-body-frame')
			// « Réessayer » while still offline: still offline
			await page.click('.wm-offline button:has-text("Réessayer")')
			await page.waitForSelector('.wm-offline:has-text("Nouvel essai dans")')
			await shot('91-offline')
		} finally {
			await page.context().setOffline(false)
		}
		// the network is back: tried at once, the banner goes
		await page.waitForSelector('.wm-toast:has-text("Connexion rétablie")')
		await page.locator('.wm-offline').waitFor({ state: 'detached' })
		await page.waitForSelector(row)
	})

	await step('English: the translated parts follow the language of the Nextcloud account', async () => {
		const occ = (args) => execSync(OCC + ' ' + args, { encoding: 'utf8' })
		occ('user:setting admin core lang en')
		try {
			await page.reload()
			await page.click('.wm-tab[data-module="tasks"]')
			await page.waitForSelector('.wm-compose-btn:has-text("New task")')
			await page.waitForSelector('.wm-task-filter:has-text("To do")')
			await page.waitForSelector('input[name=quickTask][placeholder="Add a task (Enter)"]')
			await page.waitForSelector('.wm-task.late .wm-task-due:has-text("Overdue")')
			await shot('69-english')
			// rules and delegates
			await page.click('.wm-tab[data-module="mail"]')
			// mail: the module tabs, the folders and the toolbar
			for (const label of ['Mail', 'Calendar', 'Contacts', 'Tasks']) await page.waitForSelector('.wm-tab:has-text("' + label + '")')
			await page.waitForSelector('.wm-compose-btn:has-text("New message")')
			await page.click('.wm-message >> nth=0')
			await page.waitForSelector('.wm-toolbar button:has-text("Reply")')
			await page.click('.wm-compose-btn:has-text("New message")')
			await page.waitForSelector('.wm-compose button:has-text("Send")')
			await shot('69-english-mail')
			await page.click('.wm-compose button:has-text("Cancel")')
			await page.click('text=Accounts and signature')
			await page.click('.wm-account-card:has-text("user@societe-a.fr") button:has-text("Rules")')
			await page.waitForSelector('.wm-rules h2:has-text("Rules of user@societe-a.fr")')
			await page.waitForSelector('.wm-rule-tag:has-text("created in Outlook")')
			await page.waitForSelector('.wm-rule-new:has-text("New rule")')
			await page.click('text=Accounts and signature')
			await page.click('.wm-account-card:has-text("user@societe-a.fr") button:has-text("Delegates")')
			await page.waitForSelector('.wm-delegates h2:has-text("Delegates of user@societe-a.fr")')
			await page.waitForSelector('.wm-delegates h3:has-text("Meeting requests")')
			// contacts
			await page.click('.wm-tab[data-module="contacts"]')
			await page.waitForSelector('.wm-compose-btn:has-text("New contact")')
			await page.waitForSelector('.wm-cal-section:has-text("My address books")')
			await page.waitForSelector('.wm-cal-add:has-text("Add a colleague\'s contacts")')
			await page.waitForSelector('.wm-empty-detail:has-text("Select a contact")')
			await page.click('.wm-contact >> nth=0')
			await page.waitForSelector('.wm-toolbar button:has-text("Edit")')
			await page.click('.wm-toolbar button:has-text("Edit")')
			await page.waitForSelector('.wm-contact-form h2:has-text("Edit the contact")')
			await page.waitForSelector('.wm-contact-form .wm-field:has-text("First name")')
			await shot('69-english-contacts')
			await page.click('.wm-contact-form button:has-text("Cancel")')
			// calendar: dates in English too
			const calView = await page.evaluate(() => localStorage.getItem('webmail.calview'))
			await page.click('.wm-tab[data-module="calendar"]')
			await page.waitForSelector('.wm-compose-btn:has-text("New event")')
			await page.waitForSelector('.wm-cal-section:has-text("My calendars")')
			await page.waitForSelector('.wm-segmented button:has-text("Week")')
			await page.click('.wm-segmented button:has-text("Month")')
			const monthTitle = await page.textContent('.wm-cal-title')
			assert.ok(/^(January|February|March|April|May|June|July|August|September|October|November|December) \d{4}$/.test(monthTitle.trim()), monthTitle)
			await page.waitForSelector('.wm-month-head:has-text("Mon")')
			await page.click('.wm-compose-btn:has-text("New event")')
			await page.waitForSelector('.wm-modal h2:has-text("New event")')
			await page.waitForSelector('.wm-modal button:has-text("Cancel")')
			await shot('69-english-calendar')
			await page.click('.wm-modal button:has-text("Cancel")')
			await page.evaluate((v) => (v ? localStorage.setItem('webmail.calview', v) : localStorage.removeItem('webmail.calview')), calView)
			// a message of the server, in English too
			await page.click('.wm-cal-add:has-text("Add a shared calendar")')
			await page.fill('input[name=sharedEmail]', 'not-an-address')
			await page.click('.wm-modal button[type=submit]')
			await page.waitForSelector('.wm-modal .wm-form-error:has-text("Invalid e-mail address")')
			await page.click('.wm-modal button:has-text("Cancel")')
			// the administration page
			await page.goto(NC + '/settings/admin/webmail')
			await page.waitForSelector('#webmail-admin h2:has-text("Exchange Webmail — servers")')
			await shot('69-english-admin')
			await page.goto(NC + '/apps/webmail/')
		} finally {
			occ('user:setting admin core lang fr')
		}
		await page.reload()
		await page.click('.wm-tab[data-module="tasks"]')
		await page.waitForSelector('.wm-compose-btn:has-text("Nouvelle tâche")')
		await page.click('.wm-tab[data-module="mail"]')
		await page.waitForSelector('.wm-message')
	})

	await step('module choice is remembered', async () => {
		await page.click('.wm-tab[data-module="calendar"]')
		await page.reload()
		await page.waitForSelector('.wm-tab.active[data-module="calendar"]')
		await page.waitForSelector('.wm-agenda, .wm-month, .wm-week')
		await page.click('.wm-tab[data-module="mail"]')
		await page.waitForSelector('.wm-message')
	})

	await step('new messages: notification, tab title, toast', async () => {
		await page.selectOption('.wm-account-select', { label: 'user@societe-a.fr' })
		await page.waitForSelector('.wm-message')
		// the browser's notifications, recorded
		await page.evaluate(() => {
			window.__notes = []
			window.Notification = class {
				constructor(title, opts) { this.title = title; this.body = opts.body; window.__notes.push(this) }
				static get permission() { return 'granted' }
				static requestPermission() { return Promise.resolve('granted') }
			}
		})
		await page.click('.wm-notify-toggle')
		await page.waitForSelector('.wm-notify-toggle:has-text("Notifications activées")')
		const sendToMe = async (subject) => {
			await page.click('.wm-compose-btn')
			await page.fill('input[name=to]', 'user@societe-a.fr')
			await page.fill('input[name=subject]', subject)
			await page.fill('.wm-editor', 'À ne pas oublier.')
			await submitCompose()
		}
		const check = () => page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')))

		// window in the background: a notification
		await sendToMe('Rappel : rapport mensuel')
		await page.evaluate(() => { document.hasFocus = () => false })
		await check()
		await page.waitForFunction(() => window.__notes.length === 1)
		// (both test accounts share the fake server's inbox: either may announce it first)
		const [title, body] = await page.evaluate(() => [window.__notes[0].title, window.__notes[0].body])
		assert(/^Utilisateur \((user@societe-a|compta@societe-b)\.fr\)$/.test(title), title)
		assert.strictEqual(body, 'Rappel : rapport mensuel')
		// unread count in the tab title
		const badge = await page.textContent('.wm-folder:has-text("Boîte de réception") .wm-badge')
		await page.waitForFunction((n) => document.title.startsWith('(' + n + ') '), badge)
		// clicking it opens the message
		await page.evaluate(() => window.__notes[0].onclick())
		await page.waitForSelector('.wm-message-header h2:has-text("Rappel : rapport mensuel")')
		await page.selectOption('.wm-account-select', { label: 'user@societe-a.fr' })
		await page.waitForSelector('.wm-message')

		// window in front: a message in the page, no notification
		await page.evaluate(() => {
			document.hasFocus = () => true
			// every message shown in the page, even if it is gone before it is looked for
			window.__toasts = []
			const box = document.getElementById('wm-toasts')
			new MutationObserver((records) => records.forEach((r) => r.addedNodes.forEach((n) => window.__toasts.push(n.textContent))))
				.observe(box, { childList: true })
		})
		const polls = []
		const onPoll = async (r) => {
			if (r.url().includes('/new?')) polls.push(r.url().replace(/.*\/accounts\//, '').replace(/\?.*/, '') + ' ' + decodeURIComponent(r.url().split('?')[1]) + ' -> ' + (await r.text().catch(() => '?')).slice(0, 160))
			if (r.url().endsWith('/send')) polls.push('SEND via ' + r.url().replace(/.*\/accounts\//, ''))
		}
		page.on('response', onPoll)
		await sendToMe('Rappel 2')
		await check()
		await page.waitForFunction(() => window.__toasts.some((t) => /Nouveau message de Utilisateur \(.+\) : Rappel 2/.test(t)), null, { timeout: 30000 })
			.catch(async (e) => {
				const seen = await page.evaluate(() => ({ toasts: window.__toasts, notes: window.__notes.map((n) => n.title + ' / ' + n.body), focus: document.hasFocus(), hidden: document.hidden }))
				const inbox = await page.$$eval('.wm-message-subject', (els) => els.slice(0, 4).map((x) => x.textContent))
				polls.push('selected account: ' + await page.inputValue('.wm-account-select').catch(() => '?'))
				throw new Error('no in-page message for Rappel 2: ' + JSON.stringify(seen) + ' polls=' + JSON.stringify(polls) + ' list=' + JSON.stringify(inbox) + ' (' + e.message.split('\n')[0] + ')')
			})
		await page.waitForSelector('.wm-message:has-text("Rappel 2")')
		assert.strictEqual(await page.evaluate(() => window.__notes.length), 1)
		page.off('response', onPoll)
		await shot('35-new-message')

		await page.click('.wm-notify-toggle')
		await page.waitForSelector('.wm-notify-toggle:has-text("Activer les notifications")')
	})

	await step('Nextcloud unified search: messages and events, the link opens them', async () => {
		const search = async (term, section) => {
			await page.goto(NC + '/apps/files/')
			// up to Nextcloud 33: a button that opens the search; Nextcloud 34: a field in the header
			await page.click('header button[aria-label="Recherche unifiée"], .unified-search-input__button')
			await page.keyboard.type(term)
			await page.waitForSelector('text=' + section)
		}
		await search('facture', 'Courrier Exchange')
		// the message (Files may list a saved « Votre facture OVH.eml » first)
		const result = page.locator('a:has-text("Votre facture OVH"):has-text("OVHcloud")').first()
		assert((await result.textContent()).includes('OVHcloud'))
		await shot('47-unified-search')
		await result.click()
		await page.waitForURL(/apps\/webmail/)
		await page.waitForSelector('.wm-message-header h2:has-text("Votre facture OVH")')
		// an event: the calendar at its date, the event open
		await search('revue budget', 'Agenda Exchange')
		await page.locator('a:has-text("Revue budget")').first().click()
		await page.waitForURL(/apps\/webmail/)
		await page.waitForSelector('.wm-modal .wm-event-when')
		assert(await page.isVisible('.wm-modal h2:has-text("Revue budget")'))
		await page.click('.wm-modal-close')
		// two letters: nothing asked to Exchange
		const r = await page.evaluate(async () => (await (await fetch('/ocs/v2.php/search/providers/webmail-mail/search?term=fa&format=json', { headers: { 'OCS-APIREQUEST': 'true' } })).json()).ocs.data.entries.length)
		assert.strictEqual(r, 0)
		await page.click('.wm-tab[data-module="mail"]')
		await page.waitForSelector('.wm-message')
	})

	await step('Nextcloud dashboard: unread messages and next events', async () => {
		const ocs = (path, opts = {}) => page.evaluate(async ({ path, opts }) => {
			const r = await fetch(OC.linkToOCS('apps/dashboard/api', 2).replace(/\/$/, '') + path, {
				method: opts.method || 'GET',
				headers: { 'OCS-APIRequest': 'true', Accept: 'application/json', 'Content-Type': 'application/json', requesttoken: OC.requestToken },
				body: opts.body ? JSON.stringify(opts.body) : undefined,
			})
			return (await r.json()).ocs
		}, { path, opts })
		// both widgets are offered, and put on this user's dashboard
		const widgets = (await ocs('/v1/widgets')).data
		assert(widgets['webmail-unread'] && widgets['webmail-agenda'], Object.keys(widgets).join(','))
		assert.strictEqual(widgets['webmail-unread'].title, 'Courrier non lu')
		await ocs('/v3/layout', { method: 'POST', body: { layout: ['webmail-unread', 'webmail-agenda'] } })
		const items = (await ocs('/v2/widget-items?widgets[]=webmail-unread&widgets[]=webmail-agenda')).data
		const unread = items['webmail-unread'].items
		assert(unread.length > 0 && unread.every((i) => /[?&]account=\d+&message=/.test(i.link)), 'unread widget: ' + JSON.stringify(items['webmail-unread']))
		assert(unread.some((i) => i.title === 'Rappel 2'), unread.map((i) => i.title).join('|'))
		const events = items['webmail-agenda'].items
		assert(events.length > 0, 'next events')
		assert(events.every((e) => /^(Aujourd'hui|Demain|[a-zé]+\. \d+ [a-zéû.]+)( |, toute la journée)/.test(e.subtitle)), events.map((e) => e.subtitle).join('|'))

		await page.goto(NC + '/apps/dashboard/')
		await page.waitForSelector('text=Courrier non lu')
		await page.waitForSelector('a:has-text("Rappel 2")')
		await shot('40-dashboard')
		// an item opens the message in the webmail (the dashboard opens links in a new tab)
		const [tab] = await Promise.all([page.context().waitForEvent('page'), page.click('a:has-text("Rappel 2")')])
		await tab.waitForSelector('.wm-message-header h2:has-text("Rappel 2")')
		assert(!tab.url().includes('message='), 'the link is not kept in the address')
		await tab.close()
		await page.goto(NC + '/apps/webmail/')
		await page.waitForSelector('.wm-message')
	})

	await step('mobile layout', async () => {
		await page.setViewportSize({ width: 390, height: 844 })
		await page.goto(NC + '/apps/webmail/')
		await page.waitForSelector('.wm-message')
		await shot('06-mobile-list')
		await page.click('.wm-message >> nth=0')
		await page.waitForSelector('.wm-body-frame')
		assert(!(await page.isVisible('.wm-list')))
		await shot('07-mobile-message')
		await page.click('.wm-toolbar .wm-back')
		assert(await page.isVisible('.wm-list'))
		await page.click('.wm-list-header .wm-back')
		await page.click('.wm-tab[data-module="calendar"]')
		await page.waitForSelector('.wm-agenda-item')
		assert(await page.isVisible('.wm-detail'))
		await shot('15-mobile-calendar')
		await page.click('.wm-cal-toolbar .wm-back')
		await page.click('.wm-tab[data-module="contacts"]')
		await page.waitForSelector('.wm-contact')
		await page.click('.wm-contact:has-text("Anne Bernard")')
		await page.waitForSelector('.wm-contact-card')
		await shot('16-mobile-contact')
	})

	// expected: deliberate 400s (bad domain/password, missing recipient) and the fake tracker pixel
	// errors of other Nextcloud apps (Files, Text… where the unified search is tried) are not ours
	const relevant = errors.filter((e) => !/favicon|ResizeObserver|status of 400|ERR_TUNNEL_CONNECTION_FAILED|ERR_NAME_NOT_RESOLVED/.test(e)
		&& !/\/apps\/(?!webmail\/)[a-z_]+\//.test(e))
	await browser.close()
	if (relevant.length) {
		console.log('\nBrowser errors:\n' + relevant.join('\n'))
		process.exit(1)
	}
	console.log('\nAll end-to-end steps passed.')
}

main().catch((e) => { console.error('\nFAILED:', e); process.exit(1) })
