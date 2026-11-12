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

	await step('search', async () => {
		await page.fill('.wm-search', 'réunion')
		await page.press('.wm-search', 'Enter')
		await page.waitForFunction(() => document.querySelectorAll('.wm-message').length === 1)
		assert.strictEqual(await page.textContent('.wm-message-subject'), 'Réunion de lundi')
		await page.click('.wm-search-info a')
		await page.waitForFunction(() => document.querySelectorAll('.wm-message').length === 50)
	})

	await step('compose new message with attachment', async () => {
		await page.click('.wm-compose-btn')
		await page.fill('input[name=to]', 'Client <client@exemple.fr>, autre@exemple.fr')
		await page.fill('input[name=subject]', 'Test depuis Nextcloud')
		await page.fill('textarea[name=body]', 'Bonjour,\nceci est un test.\nhttps://nextcloud.com')
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
		await page.fill('textarea[name=body]', 'Oui, 14h me va.')
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
		await page.waitForSelector('.wm-empty:has-text("Aucun message")')
	})

	await step('account settings: signature', async () => {
		await page.click('text=Comptes et signature')
		await page.fill('.wm-account-card textarea', 'Utilisateur\nSociété A')
		await page.click('.wm-account-card button:has-text("Enregistrer")')
		await page.waitForSelector('.wm-toast:has-text("Compte mis à jour")')
		await page.click('.wm-compose-btn')
		assert((await page.inputValue('textarea[name=body]')).includes('-- \nUtilisateur\nSociété A'))
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
