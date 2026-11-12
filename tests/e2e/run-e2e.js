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
		await page.waitForFunction(() => document.querySelectorAll('.wm-message').length === 61)
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

	await step('module choice is remembered', async () => {
		await page.click('.wm-tab[data-module="calendar"]')
		await page.reload()
		await page.waitForSelector('.wm-tab.active[data-module="calendar"]')
		await page.waitForSelector('.wm-agenda, .wm-month')
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
