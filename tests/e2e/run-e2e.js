/*
 * End-to-end test of the webmail app in a real Nextcloud, against fake_ews.py.
 *
 *   NC_URL=http://localhost:8080 NC_USER=admin NC_PASS=... SHOTS=/tmp/shots node tests/e2e/run-e2e.js
 *
 * The Nextcloud must have the app enabled, verify_tls=no and the domain
 * societe-a.fr mapped to the fake server (see tests/e2e/README.md).
 */
const { chromium } = require('playwright')
const assert = require('assert')
const fs = require('fs')
const path = require('path')

const NC = process.env.NC_URL || 'http://localhost:8080'
const SHOTS = process.env.SHOTS || '/tmp/webmail-shots'
fs.mkdirSync(SHOTS, { recursive: true })

async function main() {
	const browser = await chromium.launch()
	const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR', timezoneId: 'Europe/Paris' })
	const page = await context.newPage()
	const errors = []
	page.on('pageerror', (e) => errors.push('pageerror: ' + e.message))
	page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text()) })

	const step = async (name, fn) => {
		process.stdout.write('• ' + name + ' … ')
		await fn()
		console.log('ok')
	}
	const shot = (name) => page.screenshot({ path: path.join(SHOTS, name + '.png') })

	await step('login', async () => {
		await page.goto(NC + '/login')
		await page.fill('#user', process.env.NC_USER || 'admin')
		await page.fill('#password', process.env.NC_PASS || 'admin-Pass-123')
		await page.click('button[type=submit]')
		await page.waitForURL(/apps/)
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
		// start with empty caches
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
		await page.click('.wm-folder:has-text("Clients")')
		await page.waitForSelector('.wm-message:has-text("Commande 4521")')
		const instant = await page.evaluate(() => {
			document.querySelector('.wm-folder[data-folder-id]').click()
			return { count: document.querySelectorAll('.wm-message').length, spinner: !!document.querySelector('.wm-list .icon-loading'), top: document.querySelector('.wm-list').scrollTop }
		})
		assert.deepStrictEqual(instant, { count: 50, spinner: false, top: 600 }, JSON.stringify(instant))

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
		assert.deepStrictEqual(items, ['＋ Nouveau sous-dossier'])
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
		await page.fill('.wm-account-card textarea', 'Utilisateur\nSociété A')
		await page.click('.wm-account-card button:has-text("Enregistrer")')
		await page.waitForSelector('.wm-toast:has-text("Compte mis à jour")')
		await page.click('.wm-compose-btn')
		const signed = await page.innerText('.wm-editor')
		assert(/--\s*\nUtilisateur\nSociété A/.test(signed), signed)
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
		const u = new Date(); u.setDate(u.getDate() + 3)
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
		await page.waitForSelector('.wm-modal:has-text("Organisateur : Marie Martin")')
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
			await page.click('.wm-compose button[type=submit]')
			await page.waitForSelector('.wm-toast:has-text("Message envoyé")')
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
	const relevant = errors.filter((e) => !/favicon|ResizeObserver|status of 400|ERR_TUNNEL_CONNECTION_FAILED|ERR_NAME_NOT_RESOLVED/.test(e))
	await browser.close()
	if (relevant.length) {
		console.log('\nBrowser errors:\n' + relevant.join('\n'))
		process.exit(1)
	}
	console.log('\nAll end-to-end steps passed.')
}

main().catch((e) => { console.error('\nFAILED:', e); process.exit(1) })
