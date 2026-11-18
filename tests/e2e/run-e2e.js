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
// the warnings « Undefined array key "UID" » in the log of Nextcloud (the CalDAV wrapper reading an event or a task)
const uidWarnings = () => {
	const container = (OCC.match(/docker exec(?: -u \S+)? (\S+)/) || [])[1] || 'nc'
	return Number(require('child_process').execSync('docker exec ' + container + ' sh -c "grep -c \'Undefined array key .\\{1,2\\}UID\' /var/www/html/data/nextcloud.log || true"', { encoding: 'utf8' }).trim() || 0)
}
fs.mkdirSync(SHOTS, { recursive: true })

async function main() {
	const browser = await chromium.launch()
	const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR', timezoneId: 'Europe/Paris' })
	// « Se connecter avec Nextcloud »: the fake Matrix server « ex3.fake.test » is only known to Nextcloud
	// (its container): the browser's step through it is made here, then the browser goes on to the webmail
	await context.route(/\/apps\/webmail\/matrix\/sso$/, async (route) => {
		const start = await route.fetch({ maxRedirects: 0 })
		const toChat = start.headers().location || ''
		if (!toChat.startsWith('https://ex3.fake.test:8443/')) return route.fulfill({ response: start })
		const chat = await context.request.get(toChat.replace('//ex3.fake.test:8443/', '//127.0.0.1:8443/'), { maxRedirects: 0, ignoreHTTPSErrors: true })
		await route.fulfill({ status: 302, headers: { Location: chat.headers().location } })
	})
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
	const shot = (name, p = page) => p.screenshot({ path: path.join(SHOTS, name + '.png') })
	// an action of « ⋯ Plus », above a message: the menu opened, then the action (its selector in the menu)
	const openMore = async () => {
		await page.click('.wm-toolbar .wm-more-btn')
		await page.waitForSelector('.wm-more-menu')
	}
	const more = async (selector) => {
		await openMore()
		await page.click('.wm-more-menu ' + selector)
	}
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
		// remote images blocked in every folder for the steps about them; the folders that show
		// them at once (inbox, sent items… by default) have their own step
		execSync(OCC + ' user:setting admin webmail images_folders \'{"inbox":false,"sentitems":false,"other":false,"junkemail":false}\'')
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

	await step('admin settings: « État des services » checks the Exchange servers, the chat, the background jobs', async () => {
		const occ = (args) => execSync(OCC + ' ' + args, { encoding: 'utf8' })
		// (the fake servers are on the local network: allowed here only)
		occ('config:system:set allow_local_remote_servers --value=true --type=boolean')
		try {
			await page.click('#webmail-health-check')
			await page.waitForSelector('#webmail-health li.wm-health-ok:has-text("Exchange — societe-a.fr")')
			await page.waitForSelector('#webmail-health li:has-text("Exchange — societe-b.fr")')
			await page.waitForSelector('#webmail-health li:has-text("Tâches de fond de Nextcloud")')
			await page.waitForSelector('#webmail-health li:has-text("Chat (Matrix / Element)")')
			await shot('01b-admin-health')
		} finally {
			occ('config:system:delete allow_local_remote_servers')
		}
	})

	await step('admin settings: « Mises à jour » tells the versions published since the one installed, with their notes', async () => {
		const occ = (args) => execSync(OCC + ' ' + args, { encoding: 'utf8' })
		occ('config:system:set allow_local_remote_servers --value=true --type=boolean')
		occ('config:system:set webmail_update_url --value=https://ex3.fake.test:8443/_test/releases')
		try {
			// (at first, with no network to GitHub: the version installed is said all the same)
			await page.waitForSelector('#webmail-update .wm-update-status')
			await page.click('#webmail-update-check')
			await page.waitForSelector('#webmail-update.available .wm-update-status:has-text("99.0.0")')
			const versions = await page.$$eval('#webmail-update .wm-update-release summary', (els) => els.map((e) => e.textContent))
			assert.strictEqual(versions.length, 2, versions.join(' | ')) // not the draft, nor the older one
			assert.ok(versions[0].includes('99.0.0') && versions[1].includes('98.0.0'), versions.join(' | '))
			// the notes of the newest, open: a heading and a list, as text
			await page.waitForSelector('#webmail-update details[open] .wm-update-heading:has-text("Nouveautés")')
			await page.waitForSelector('#webmail-update details[open] li:has-text("Recherches enregistrées dans le volet")')
			assert.ok((await page.textContent('#webmail-update')).includes('sudo maj-webmail --auto'))
			await page.locator('#webmail-update').scrollIntoViewIfNeeded()
			await shot('01c-admin-update')
			// told to the administrators (a setting kept on the server): unticked, then ticked again
			const notifyIs = (v) => page.waitForFunction(async (v) => {
				const r = await fetch(OC.generateUrl('/apps/webmail/api/admin/update'), { headers: { requesttoken: OC.requestToken } })
				return (await r.json()).notify === v
			}, v)
			const before = await page.isChecked('#webmail-update-notify')
			await page.click('label[for=webmail-update-notify]')
			await notifyIs(!before)
			await page.click('label[for=webmail-update-notify]')
			await notifyIs(before)
		} finally {
			occ('config:system:delete webmail_update_url')
			// (the list kept by the check forgotten)
			await page.click('#webmail-update-check')
			await page.waitForFunction(() => !document.querySelector('#webmail-update.available'))
			occ('config:system:delete allow_local_remote_servers')
		}
	})

	await step('admin settings: certificates and size of the attachments', async () => {
		// this test Nextcloud talks to the fake server without checking its certificate (occ verify_tls=no)
		assert(await page.isChecked('#webmail-no-verify-tls'))
		assert(await page.isVisible('#webmail-tls-warning'))
		// the box of Nextcloud's style is drawn by its label
		await page.click('label[for=webmail-no-verify-tls]')
		assert(!(await page.isChecked('#webmail-no-verify-tls')))
		assert(!(await page.isVisible('#webmail-tls-warning')))
		await page.click('label[for=webmail-no-verify-tls]')
		assert(await page.isVisible('#webmail-tls-warning'))
		assert.strictEqual(await page.inputValue('#webmail-max-attachments'), '25')
		// out of the allowed sizes: refused, nothing saved
		await page.fill('#webmail-max-attachments', '500')
		await page.click('#webmail-save')
		await page.waitForSelector('#webmail-admin-msg.error:has-text("entre 1 et 150 Mo")')
		await page.fill('#webmail-max-attachments', '30')
		await page.click('#webmail-save')
		await page.waitForSelector('#webmail-admin-msg.success')
		await page.reload()
		assert.strictEqual(await page.inputValue('#webmail-max-attachments'), '30')
		assert(await page.isChecked('#webmail-no-verify-tls'), 'still not checked')
		assert.strictEqual(execSync(OCC + ' config:app:get webmail verify_tls').toString().trim(), 'no')
		// the limit reaches the page of the webmail
		await page.goto(NC + '/apps/webmail/')
		await page.waitForSelector('#webmail-app')
		assert.strictEqual(await page.getAttribute('#webmail-app', 'data-max-attachments'), String(30 * 1024 * 1024))
		await page.goto(NC + '/settings/admin/webmail')
		await page.fill('#webmail-max-attachments', '25')
		await page.click('#webmail-save')
		await page.waitForSelector('#webmail-admin-msg.success')
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
		const folders = await page.$$eval('.wm-folder .wm-folder-name', (els) => els.map((e) => e.textContent))
		assert.deepStrictEqual(folders.slice(0, 6), ['Boîte de réception', 'Clients', 'Brouillons', 'Éléments envoyés', 'Courrier indésirable', 'Éléments supprimés'])
		assert(!folders.includes('Calendrier'), 'calendar folder must be hidden')
		// the first 25 rows come first, the rest of the page right after (FIRST_PAGE)
		await page.waitForFunction(() => document.querySelectorAll('.wm-message').length === 50)
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
		await page.click('.wm-message-toolbar .wm-unread-btn')
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
		// (the newest messages of the fake server are from its start: « Hier » once midnight passed in Paris)
		assert.ok(['Aujourd\'hui', 'Hier'].includes(periods[0]), periods.join(' | '))
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
		// protected: an expiry, a password given apart (not in the message), another link
		await page.click('.wm-link-options summary')
		await page.selectOption('select[name=linkExpiry]', '7')
		await page.check('input[name=linkPassword]')
		await page.click('.wm-link-btn')
		await page.waitForFunction(() => document.querySelectorAll('.wm-editor a').length === 4)
		const toastText = await (await page.waitForSelector('.wm-toast:has-text("Mot de passe du lien « devis-2027.pdf »")')).textContent()
		const password = toastText.match(/: ([A-Za-z0-9]{12}) —/)[1]
		const editorText = await page.textContent('.wm-editor')
		assert.ok(editorText.includes('protégé par un mot de passe') && editorText.includes('lien valable jusqu'), editorText)
		assert.ok(!editorText.includes(password), 'the password is not in the message')
		// (inserted where the cursor is: the one link that is new)
		const all = await page.$$eval('.wm-editor a', (els) => els.map((e) => e.getAttribute('href')))
		const fresh = all.filter((x) => x !== hrefs[0] && x !== big)
		assert.strictEqual(fresh.length, 1, all.join(' '))
		const protectedHref = fresh[0]
		// without the password: no file
		const locked = await visitor.request.get(protectedHref + '/download', { maxRedirects: 5 })
		assert.notStrictEqual((await locked.body()).toString('latin1', 0, 5), '%PDF-', 'protected')
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
		assert.deepStrictEqual(items, ['★ Ajouter aux favoris', '＋ Nouveau sous-dossier', '📥 Importer des messages (.eml)…', '📦 Exporter (.zip)…'])
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

	await step('blocked and allowed senders: the lists (also filled by « Indésirable »), kept by Exchange as two rules acting on arrival', async () => {
		const fake = (path, body) => new Promise((resolve, reject) => {
			const data = JSON.stringify(body)
			const req = require('https').request({ host: 'localhost', port: 8443, path, method: 'POST', rejectUnauthorized: false, headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(data) } }, (res) => {
				let out = ''
				res.on('data', (c) => { out += c })
				res.on('end', () => resolve(JSON.parse(out || '{}')))
			})
			req.on('error', reject)
			req.end(data)
		})
		const where = (subject) => page.evaluate(async (subject) => {
			const headers = { requesttoken: OC.requestToken }
			const accounts = await (await fetch('/apps/webmail/api/accounts', { headers })).json()
			const id = accounts.find((a) => a.email === 'user@societe-a.fr').id
			for (const folder of ['inbox', 'junkemail']) {
				const r = await (await fetch('/apps/webmail/api/accounts/' + id + '/messages?folder=' + folder, { headers })).json()
				if (r.items.some((m) => m.subject === subject)) return folder
			}
			return null
		}, subject)
		await page.click('text=Comptes et signature')
		const card = '.wm-sender-lists'
		await page.waitForSelector(card)
		// the sender put in the junk mail, then trusted again (step before): in the allowed list
		await page.waitForSelector(card + ' .wm-sender-list[data-kind=allowed] li[data-entry="news@exemple.org"]')
		// a whole domain blocked, one address of it allowed
		await page.fill(card + ' input[name=senderEntry]', 'promo-exemple.com')
		await page.click(card + ' .wm-sender-block')
		await page.waitForSelector(card + ' .wm-sender-list[data-kind=blocked] li[data-entry="@promo-exemple.com"]:has-text("Tout le domaine promo-exemple.com")')
		await page.fill(card + ' input[name=senderEntry]', 'Service client <contact@promo-exemple.com>')
		await page.click(card + ' .wm-sender-allow')
		await page.waitForSelector(card + ' .wm-sender-list[data-kind=allowed] li[data-entry="contact@promo-exemple.com"]')
		await shot('115-sender-lists')
		const made = []
		try {
			made.push(await fake('/_test/deliver', { subject: 'Soldes flash -70 %', sender: ['Promo', 'offres@promo-exemple.com'], body: '<p>Promo.</p>', rules: true }))
			made.push(await fake('/_test/deliver', { subject: 'Votre commande 7781', sender: ['Service client', 'contact@promo-exemple.com'], body: '<p>Commande.</p>', rules: true }))
			assert.strictEqual(await where('Soldes flash -70 %'), 'junkemail')
			assert.strictEqual(await where('Votre commande 7781'), 'inbox')
		} finally {
			for (const m of made) await fake('/_test/remove', { id: m.id })
		}
		// lists emptied: the rules of Exchange taken away
		for (const [kind, e] of [['blocked', '@promo-exemple.com'], ['allowed', 'contact@promo-exemple.com'], ['allowed', 'news@exemple.org']]) {
			await page.click(card + ' .wm-sender-list[data-kind=' + kind + '] li[data-entry="' + e + '"] .wm-sender-remove')
			await page.waitForFunction((sel) => !document.querySelector(sel), card + ' .wm-sender-list[data-kind=' + kind + '] li[data-entry="' + e + '"]')
		}
		const left = await page.evaluate(async () => {
			const headers = { requesttoken: OC.requestToken }
			const accounts = await (await fetch('/apps/webmail/api/accounts', { headers })).json()
			const id = accounts.find((a) => a.email === 'user@societe-a.fr').id
			return (await (await fetch('/apps/webmail/api/accounts/' + id + '/rules', { headers })).json()).rules.filter((r) => r.name.startsWith('Webmail :')).map((r) => r.name)
		})
		assert.deepStrictEqual(left, [])
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
		const [popup] = await Promise.all([page.context().waitForEvent('page'), page.click('.wm-message-toolbar .wm-print-btn')])
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
		await page.click('.wm-account-card:has(.wm-signature-editor) button.primary:text-is("Enregistrer") >> nth=0')
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

	await step('signatures: another one (« Courte »), the default of the answers, switched in a message', async () => {
		await page.click('text=Comptes et signature')
		const card = '.wm-account-card:has-text("user@societe-a.fr")'
		await page.click(card + ' .wm-signatures summary')
		await page.click(card + ' .wm-signature-add')
		await page.fill(card + ' input[name=signatureName]', 'Courte')
		await page.click(card + ' .wm-signature-row .wm-signature-editor')
		await page.keyboard.type('U. — Société A')
		await page.selectOption(card + ' select[name=signatureReply]', 'Courte')
		await page.click(card + ' .wm-signatures-save')
		await page.waitForSelector('.wm-toast:has-text("Signatures enregistrées")')
		await shot('63-signatures')
		// a new message: the main one; « ✍ Courte » puts the other in its place
		await page.reload()
		await page.waitForSelector('.wm-message')
		await page.click('.wm-compose-btn')
		await page.waitForSelector('.wm-editor .wm-signature b:has-text("Société A")')
		assert.strictEqual(await page.inputValue('select[name=signature]'), '')
		await page.selectOption('select[name=signature]', 'Courte')
		await page.waitForSelector('.wm-editor .wm-signature:has-text("U. — Société A")')
		assert.strictEqual(await page.locator('.wm-editor .wm-signature').count(), 1)
		await page.selectOption('select[name=signature]', '-')
		await page.waitForSelector('.wm-editor .wm-signature', { state: 'detached' })
		await page.click('.wm-compose button:has-text("Annuler")')
		// an answer starts with « Courte »
		await page.click('.wm-folder >> nth=0')
		await page.click('.wm-message >> nth=0')
		await page.click('.wm-toolbar button:has-text("↩ Répondre")')
		await page.waitForSelector('.wm-editor .wm-signature:has-text("U. — Société A")')
		assert.strictEqual(await page.inputValue('select[name=signature]'), 'Courte')
		await page.click('.wm-compose button:has-text("Annuler")')
		// back to one signature
		await page.click('text=Comptes et signature')
		await page.waitForSelector(card + ' .wm-signature-row')
		await page.click(card + ' .wm-signature-remove')
		await page.click(card + ' .wm-signatures-save')
		await page.waitForSelector('.wm-toast:has-text("Signatures enregistrées")')
		await page.click('.wm-tab[data-module="mail"]')
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
		// the status of Nextcloud: « En vacances », away
		const ncStatus = () => page.evaluate(async () => {
			const r = await fetch('/ocs/v2.php/apps/user_status/api/v1/user_status?format=json', { headers: { 'OCS-APIREQUEST': 'true', requesttoken: OC.requestToken } })
			const d = (await r.json()).ocs.data
			return d.status + ' ' + (d.messageId || '')
		})
		assert.strictEqual(await ncStatus(), 'away vacationing')
		// reminder in the folder pane
		await page.waitForSelector('.wm-oof-banner:has-text("Réponse automatique activée jusqu\'au")')
		// saved as set
		await page.click('.wm-oof-banner a:has-text("Modifier")')
		await page.waitForSelector('.wm-modal input[name=oofState][value=Scheduled]:checked')
		assert.strictEqual(await page.inputValue('.wm-modal textarea[name=oofInternal]'), 'Bonjour,\nje suis absent. Pour toute urgence : Paul Durand.')
		assert.strictEqual(await page.inputValue('.wm-modal select[name=oofAudience]'), 'Known')
		assert.strictEqual(await page.inputValue('.wm-modal input[name=oofEnd]'), local(end))
		assert.ok(await page.isChecked('.wm-modal input[name=oofNextcloudStatus]'))
		await page.click('.wm-modal-close')
		// switched off from the reminder
		await page.click('.wm-oof-banner a:has-text("Désactiver")')
		await page.waitForSelector('.wm-toast:has-text("Réponse automatique désactivée")')
		await page.waitForSelector('.wm-oof-banner', { state: 'detached' })
		// the status from before is back
		assert.ok(!(await ncStatus()).includes('vacationing'), await ncStatus())
	})

	await step('security: a sender from outside, the name of a colleague borrowed, deceptive links', async () => {
		const fake = (path, body) => new Promise((resolve, reject) => {
			const data = JSON.stringify(body)
			const req = require('https').request({ host: 'localhost', port: 8443, path, method: 'POST', rejectUnauthorized: false, headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(data) } }, (res) => {
				let out = ''
				res.on('data', (c) => { out += c })
				res.on('end', () => resolve(JSON.parse(out || '{}')))
			})
			req.on('error', reject)
			req.end(data)
		})
		const occ = (args) => execSync(OCC + ' ' + args, { encoding: 'utf8' })
		// a colleague of the organisation: a Nextcloud account
		try { occ('user:delete helene-e2e') } catch (e) { /* none */ }
		occ('user:add --generate-password --display-name="Hélène Durand" helene-e2e')
		const sent = await fake('/_test/deliver', {
			subject: 'Virement urgent à faire',
			sender: ['Hélène Durand', 'helene.durand.direction@gmail.com'],
			body: '<p>Bonjour, merci de faire ce virement aujourd\'hui.</p><p>Connectez-vous sur <a href="https://evil.example.net/login">www.ma-banque.fr</a> puis voir <a href="https://www.societe-a.fr/rib">le RIB</a>.</p>',
			isRead: true,
		})
		try {
			await page.click('.wm-tab[data-module="mail"]')
			await page.click('.wm-folder:has-text("Boîte de réception")')
			await page.click('.wm-list button[title="Actualiser"]')
			await page.waitForSelector('.wm-message:has-text("Virement urgent à faire")')
			await page.click('.wm-message:has-text("Virement urgent à faire")')
			const banner = '.wm-security-banner.danger'
			await page.waitForSelector(banner)
			const text = await page.textContent(banner)
			assert.ok(text.includes('Usurpation possible') && text.includes('Hélène Durand') && text.includes('helene.durand.direction@gmail.com'), text)
			assert.ok(text.includes('Lien trompeur') && text.includes('www.ma-banque.fr') && text.includes('evil.example.net') && !text.includes('societe-a.fr/rib'), text)
			await page.waitForSelector('.wm-message-header .wm-external-tag:has-text("Externe")')
			// framed in the message, its destination on hover
			await page.frameLocator('.wm-body-frame').locator('a[data-wm-deceptive="evil.example.net"][title="⚠ evil.example.net"]').waitFor()
			await shot('106-security-banner')
			// a message of a colleague (an address of the organisation): nothing said
			await page.click('.wm-message:has-text("Newsletter n°1")')
			await page.waitForSelector('.wm-message-header h2:has-text("Newsletter n°1")')
			assert.ok(!(await page.$('.wm-security-banner')), 'no warning for an ordinary message')
		} finally {
			await fake('/_test/remove', { id: sent.id })
			occ('user:delete helene-e2e')
		}
	})

	await step('« Archiver »: to « Archives » (made at the first use), undone; the old read messages archived each night', async () => {
		const occ = (args) => execSync(OCC + ' ' + args, { encoding: 'utf8' })
		const fake = (path, body) => new Promise((resolve, reject) => {
			const data = JSON.stringify(body)
			const req = require('https').request({ host: 'localhost', port: 8443, path, method: 'POST', rejectUnauthorized: false, headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(data) } }, (res) => {
				let out = ''
				res.on('data', (c) => { out += c })
				res.on('end', () => resolve(JSON.parse(out || '{}')))
			})
			req.on('error', reject)
			req.end(data)
		})
		const recent = await fake('/_test/deliver', { subject: 'Compte rendu à archiver', sender: ['Paul Durand', 'paul.durand@societe-a.fr'], body: '<p>CR.</p>', isRead: true })
		const old = await fake('/_test/deliver', { subject: 'Ancien contrat 2024', sender: ['Paul Durand', 'paul.durand@societe-a.fr'], body: '<p>Contrat.</p>', isRead: true, minutesAgo: 60 * 24 * 400 })
		const folders = () => page.evaluate(async () => {
			const headers = { requesttoken: OC.requestToken }
			const accounts = await (await fetch('/apps/webmail/api/accounts', { headers })).json()
			const id = accounts.find((a) => a.email === 'user@societe-a.fr').id
			return { id, folders: (await (await fetch('/apps/webmail/api/accounts/' + id + '/folders', { headers })).json()).folders }
		})
		try {
			await page.click('.wm-tab[data-module="mail"]')
			await page.click('.wm-folder:has-text("Boîte de réception")')
			await page.click('.wm-list button[title="Actualiser"]')
			await page.click('.wm-message:has-text("Compte rendu à archiver")')
			await page.waitForSelector('.wm-message-header h2:has-text("Compte rendu à archiver")')
			await shot('109-archive-button')
			await page.click('.wm-message-toolbar .wm-archive-btn')
			await page.waitForSelector('.wm-toast:has-text("Message archivé")')
			await page.waitForSelector('.wm-folder .wm-folder-name:text-is("Archives")')
			await page.waitForFunction(() => ![...document.querySelectorAll('.wm-message-subject')].some((e) => e.textContent.includes('Compte rendu à archiver')))
			await page.click('.wm-folder:has(.wm-folder-name:text-is("Archives"))')
			await page.waitForSelector('.wm-message:has-text("Compte rendu à archiver")')
			// in « Archives » itself: no « Archiver »
			await page.click('.wm-message:has-text("Compte rendu à archiver")')
			await page.waitForSelector('.wm-message-header h2:has-text("Compte rendu à archiver")')
			assert.ok(!(await page.$('.wm-message-toolbar .wm-archive-btn')))
			// the nightly archiving: 12 months, chosen in the settings
			await page.click('text=Comptes et signature')
			const select = '.wm-archive-settings select[data-account]:not([disabled])'
			await page.waitForSelector(select)
			await page.selectOption(select, '12')
			await page.waitForSelector('.wm-toast:has-text("archivés chaque nuit, après 1 an")')
			const jobId = (occ("background-job:list --class='OCA\\Webmail\\BackgroundJob\\ArchiveJob'").match(/\|\s*(\d+)\s*\|\s*OCA/) || [])[1]
			assert(jobId, 'background job registered')
			occ('background-job:execute ' + jobId + ' --force-execute')
			const { folders: list } = await folders()
			const archive = list.find((f) => f.name === 'Archives')
			assert.ok(archive && archive.total === 2, JSON.stringify(archive))
			await page.selectOption(select, '0')
			await page.waitForSelector('.wm-toast:has-text("Plus d\'archivage automatique")')
		} finally {
			await fake('/_test/remove', { id: recent.id })
			await fake('/_test/remove', { id: old.id })
			// (the folder « Archives » taken away for good, for the steps after)
			await page.evaluate(async () => {
				const headers = { requesttoken: OC.requestToken, 'Content-Type': 'application/json' }
				const accounts = await (await fetch('/apps/webmail/api/accounts', { headers })).json()
				const base = '/apps/webmail/api/accounts/' + accounts.find((a) => a.email === 'user@societe-a.fr').id + '/folders'
				const list = (await (await fetch(base, { headers })).json()).folders
				const archive = list.find((f) => f.name === 'Archives' && !f.parentId)
				if (archive) await fetch(base + '/delete', { method: 'POST', headers, body: JSON.stringify({ folderId: archive.id, permanent: true }) })
			})
			await page.goto(NC + '/apps/webmail/')
			await page.waitForSelector('.wm-message')
		}
	})

	await step('« Pièces jointes »: every file received, by kind and words, a few saved to Files at once', async () => {
		await page.click('.wm-tab[data-module="mail"]')
		await page.click('.wm-files-link')
		await page.waitForSelector('.wm-files-row:has-text("devis-2027.pdf")')
		await page.waitForSelector('.wm-files-row:has-text("facade.png")')
		const names = () => page.$$eval('.wm-files-row .wm-files-name a', (els) => els.map((e) => e.textContent))
		let all = await names()
		assert.ok(!all.includes('logo.png'), 'not the images in the text: ' + all.join(', '))
		assert.ok(all.indexOf('devis-2027.pdf') < all.indexOf('facade.png'), 'newest first: ' + all.join(', '))
		await shot('110-attachments-view')
		// only the PDF
		await page.selectOption('.wm-files-kind', 'pdf')
		await page.waitForFunction(() => [...document.querySelectorAll('.wm-files-row')].every((r) => r.dataset.kind === 'pdf') && document.querySelectorAll('.wm-files-row').length === 2)
		assert.deepStrictEqual((await names()).sort(), ['devis-2027.pdf', 'plan.pdf'])
		// by words: the files of « Photos du chantier »
		await page.selectOption('.wm-files-kind', '')
		await page.fill('.wm-files-search', 'chantier')
		await page.waitForFunction(() => document.querySelectorAll('.wm-files-row').length === 5 && !document.querySelector('.wm-files-row[data-kind] a[download="devis-2027.pdf"]'))
		// two ticked: saved in the folder chosen, or downloaded in one .zip
		await page.check('.wm-files-row:has-text("plan.pdf") .wm-files-check')
		await page.check('.wm-files-row:has-text("releve.txt") .wm-files-check')
		await page.waitForSelector('.wm-files-zip:not([hidden])')
		assert.ok((await page.getAttribute('.wm-files-zip', 'href')).includes('ids%5B%5D='))
		await page.evaluate(() => { OC.dialogs.filepicker = (title, cb) => cb('/Pièces jointes') })
		await page.click('.wm-files-save')
		await page.waitForSelector('.wm-toast:has-text("2 fichiers enregistrés dans /Pièces jointes")')
		await page.evaluate(async () => {
			const uid = OC.getCurrentUser().uid
			for (const f of ['plan.pdf', 'releve.txt']) {
				await fetch('/remote.php/dav/files/' + encodeURIComponent(uid) + '/' + encodeURIComponent('Pièces jointes') + '/' + f, { method: 'DELETE', headers: { requesttoken: OC.requestToken } })
			}
		})
		// a message opened from its file
		await page.click('.wm-files-row:has-text("plan.pdf") .wm-files-open')
		await page.waitForSelector('.wm-message-header h2:has-text("Photos du chantier")')
		assert.ok(!(await page.$('.wm-files-link.active')))
		await page.goto(NC + '/apps/webmail/')
		await page.waitForSelector('.wm-message')
	})

	await step('« Envoi personnalisé »: one message per recipient of a list, filled with their name, a report and a notification', async () => {
		await page.click('.wm-tab[data-module="mail"]')
		await page.click('.wm-merge-link')
		await page.waitForSelector('.wm-merge')
		await page.fill('.wm-merge textarea[name=mergeList]', 'E-mail;Prénom;Nom;Société\nclaire.martin@client-exemple.fr;Claire;Martin;Client Exemple\npas une adresse\npaul.leroy@client-b.fr;Paul;Leroy;\n')
		await page.fill('.wm-merge input[name=mergeSubject]', 'Votre devis, {prenom}')
		await page.fill('.wm-merge textarea[name=mergeBody]', 'Bonjour {prenom} {nom},\n\nVoici le devis pour {societe}.')
		await page.waitForSelector('.wm-merge-info:has-text("2 destinataires")')
		assert.ok((await page.textContent('.wm-merge-info')).includes('1 lignes sans adresse valide ignorées'))
		await page.waitForSelector('.wm-merge-preview:has-text("Votre devis, Claire")')
		await page.waitForSelector('.wm-merge-preview:has-text("Bonjour Claire Martin")')
		await page.waitForSelector('.wm-merge-preview:has-text("Voici le devis pour Client Exemple.")')
		await page.waitForSelector('.wm-merge-send:not([disabled]):has-text("Envoyer à 2 destinataires")')
		await shot('111-merge')
		page.once('dialog', (d) => d.accept())
		await page.click('.wm-merge-send')
		await page.waitForSelector('.wm-toast:has-text("2 messages envoyés")')
		await page.waitForSelector('.wm-merge-report.done:has-text("terminé : 2 envoyés sur 2")')
		await page.click('.wm-merge .wm-modal-close')
		// each one sent alone, with their name; the empty field gone with its space
		const sent = await page.evaluate(async () => {
			const headers = { requesttoken: OC.requestToken }
			const accounts = await (await fetch('/apps/webmail/api/accounts', { headers })).json()
			const id = accounts.find((a) => a.email === 'user@societe-a.fr').id
			const r = await (await fetch('/apps/webmail/api/accounts/' + id + '/messages?folder=sentitems&q=devis', { headers })).json()
			return r.items.filter((m) => m.subject.startsWith('Votre devis, ')).map((m) => m.subject)
		})
		assert.deepStrictEqual(sent.sort(), ['Votre devis, Claire', 'Votre devis, Paul'])
		const bell = await page.evaluate(async () => (await (await fetch('/ocs/v2.php/apps/notifications/api/v2/notifications?format=json', { headers: { 'OCS-APIREQUEST': 'true' } })).json()).ocs.data.filter((n) => n.app === 'webmail' && n.subject.startsWith('Envoi personnalisé terminé')))
		assert.ok(bell.length >= 1 && bell[0].message.startsWith('2 messages envoyés'), JSON.stringify(bell))
	})

	await step('Assistant of Nextcloud: nothing without an AI; with one, a summary, a reply proposed, a translation', async () => {
		const occ = (args) => execSync(OCC + ' ' + args, { encoding: 'utf8' })
		const container = (OCC.match(/docker exec(?:\s+-u\s+\S+)?\s+(\S+)\s+php occ/) || [])[1]
		assert(container, 'container of the tests: ' + OCC)
		const fake = (path, body) => new Promise((resolve, reject) => {
			const data = JSON.stringify(body)
			const req = require('https').request({ host: 'localhost', port: 8443, path, method: 'POST', rejectUnauthorized: false, headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(data) } }, (res) => {
				let out = ''
				res.on('data', (c) => { out += c })
				res.on('end', () => resolve(JSON.parse(out || '{}')))
			})
			req.on('error', reject)
			req.end(data)
		})
		const mail = await fake('/_test/deliver', { subject: 'Question pour l\'Assistant', sender: ['Paul Durand', 'paul.durand@societe-a.fr'], body: '<p>Bonjour Paul,</p><p>Peux-tu me confirmer la réunion de jeudi ?</p>', isRead: true })
		const openMenu = async () => {
			await page.click('.wm-list button[title="Actualiser"]')
			await page.click('.wm-message:has-text("Question pour l\'Assistant")')
			await page.waitForSelector('.wm-message-header h2:has-text("Question pour l\'Assistant")')
			await page.click('.wm-message-toolbar .wm-more-btn')
			await page.waitForSelector('.wm-more-menu')
		}
		const work = () => occ("background-job:worker 'OC\\TaskProcessing\\SynchronousBackgroundJob' --once")
		await page.click('.wm-tab[data-module="mail"]')
		await page.click('.wm-folder:has-text("Boîte de réception")')
		// no AI in this Nextcloud: no « Assistant » in the menu
		await openMenu()
		assert.ok(!(await page.$('.wm-more-menu .wm-ai-summary')), 'no Assistant without an AI')
		await page.keyboard.press('Escape')
		// a fake AI (answers known in advance), for this test only
		execSync('docker cp ' + require('path').join(__dirname, 'testai') + ' ' + container + ':/var/www/html/custom_apps/webmail_testai')
		execSync('docker exec ' + container + ' chown -R www-data:www-data /var/www/html/custom_apps/webmail_testai')
		occ('app:enable webmail_testai')
		try {
			await page.goto(NC + '/apps/webmail/')
			await page.waitForSelector('.wm-message')
			await page.waitForFunction(async () => {
				const r = await fetch(OC.generateUrl('/apps/webmail/api/assistant'), { headers: { requesttoken: OC.requestToken } })
				const c = await r.json()
				return c.summary && c.reply && c.translate
			})
			// (Nextcloud keeps the list of what its AI can do for a minute: the page opened again until it shows)
			for (let i = 0; ; i++) {
				const asked = page.waitForResponse((r) => /\/apps\/webmail\/api\/assistant(\?|$)/.test(r.url()))
				await page.goto(NC + '/apps/webmail/')
				await asked
				await page.waitForSelector('.wm-message')
				await openMenu()
				if (await page.$('.wm-more-menu .wm-ai-summary')) break
				assert.ok(i < 18, 'the Assistant is offered once Nextcloud knows its AI')
				await page.keyboard.press('Escape')
				await page.waitForTimeout(5000)
			}
			await shot('112-assistant-menu')
			await page.click('.wm-more-menu .wm-ai-summary')
			await page.waitForSelector('.wm-assistant-panel[data-kind=summary]:has-text("L\'Assistant travaille")')
			work()
			await page.waitForSelector('.wm-assistant-panel[data-kind=summary] .wm-assistant-output:has-text("RÉSUMÉ : Bonjour Paul,")', { timeout: 20000 })
			// a reply proposed, then another one with a hint
			await page.click('.wm-message-toolbar .wm-more-btn')
			await page.click('.wm-more-menu .wm-ai-reply')
			work()
			await page.waitForSelector('.wm-assistant-panel[data-kind=reply] .wm-assistant-output:has-text("merci pour votre message")', { timeout: 20000 })
			await page.waitForSelector('.wm-assistant-panel .wm-assistant-use')
			await shot('113-assistant-reply')
			await page.fill('.wm-assistant-hint', 'accepter')
			await page.click('.wm-assistant-panel button:has-text("Autre proposition")')
			work()
			await page.waitForSelector('.wm-assistant-panel[data-kind=reply] .wm-assistant-output:has-text("(avec consigne)")', { timeout: 20000 })
			// translated into the language of the page
			await page.click('.wm-message-toolbar .wm-more-btn')
			await page.click('.wm-more-menu .wm-ai-translate')
			work()
			await page.waitForSelector('.wm-assistant-panel[data-kind=translate] .wm-assistant-output:has-text("[fr] Bonjour Paul,")', { timeout: 20000 })
		} finally {
			await fake('/_test/remove', { id: mail.id })
			occ('app:disable webmail_testai')
			execSync('docker exec ' + container + ' rm -rf /var/www/html/custom_apps/webmail_testai')
			await page.goto(NC + '/apps/webmail/')
			await page.waitForSelector('.wm-message')
		}
	})

	await step('right-click on a message of the list: its usual actions with their keys; on several ticked, for all of them', async () => {
		const fake = (path, body) => new Promise((resolve, reject) => {
			const data = JSON.stringify(body)
			const req = require('https').request({ host: 'localhost', port: 8443, path, method: 'POST', rejectUnauthorized: false, headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(data) } }, (res) => {
				let out = ''
				res.on('data', (c) => { out += c })
				res.on('end', () => resolve(JSON.parse(out || '{}')))
			})
			req.on('error', reject)
			req.end(data)
		})
		const made = []
		for (const x of ['A', 'B']) made.push(await fake('/_test/deliver', { subject: 'Menu clic droit ' + x, sender: ['Paul Durand', 'paul.durand@societe-a.fr'], body: '<p>Test.</p>', isRead: true }))
		await page.click('.wm-tab[data-module="mail"]')
		await page.click('.wm-folder:has-text("Boîte de réception")')
		await page.click('.wm-list button[title="Actualiser"]')
		const row = (subject) => '.wm-message:has(.wm-message-subject:text-is("' + subject + '"))'
		await page.waitForSelector(row('Menu clic droit A'))
		// one message: answered, read, flagged, moved… from its menu
		await page.click(row('Menu clic droit A'), { button: 'right' })
		await page.waitForSelector('.wm-context-menu')
		const labels = await page.$$eval('.wm-context-menu [role=menuitem] .wm-menu-label', (els) => els.map((e) => e.textContent))
		for (const l of ['Répondre', 'Répondre à tous', 'Transférer', 'Marquer comme non lu', 'Assurer un suivi', 'Archiver', 'Déplacer vers…', 'Supprimer']) assert.ok(labels.includes(l), l + ' in ' + labels.join(', '))
		assert.ok((await page.textContent('.wm-context-menu .wm-ctx-reply kbd')) === 'R')
		await shot('114-context-menu')
		await page.click('.wm-context-menu .wm-ctx-read')
		await page.waitForSelector(row('Menu clic droit A') + '.unread')
		await page.click(row('Menu clic droit A'), { button: 'right' })
		await page.click('.wm-context-menu .wm-ctx-flag')
		await page.waitForSelector(row('Menu clic droit A') + ' .wm-flag.on')
		// from the keyboard: the menu key on the focused message, Escape closes it
		await page.focus(row('Menu clic droit B'))
		await page.keyboard.press('ContextMenu')
		await page.waitForSelector('.wm-context-menu')
		await page.keyboard.press('Escape')
		await page.waitForFunction(() => !document.querySelector('.wm-context-menu'))
		// reply: the message opened, then its answer
		await page.click(row('Menu clic droit A'), { button: 'right' })
		await page.click('.wm-context-menu .wm-ctx-reply')
		await page.waitForSelector('.wm-compose h2:has-text("Répondre")')
		await page.click('.wm-compose-actions button:has-text("Annuler")')
		// two ticked: the menu acts on both (read again, then moved and back)
		await page.click(row('Menu clic droit A'), { modifiers: ['Control'] })
		await page.click(row('Menu clic droit B'), { modifiers: ['Control'] })
		await page.click(row('Menu clic droit B'), { button: 'right' })
		await page.waitForSelector('.wm-context-menu .wm-cat-title:has-text("2 messages sélectionnés")')
		assert.ok(!(await page.$('.wm-context-menu .wm-ctx-reply')), 'no answer for several')
		await page.click('.wm-context-menu .wm-ctx-flag')
		await page.waitForFunction(() => document.querySelectorAll('.wm-message .wm-flag.on').length >= 1)
		await page.click(row('Menu clic droit A'), { modifiers: ['Control'] })
		await page.click(row('Menu clic droit B'), { modifiers: ['Control'] })
		await page.click(row('Menu clic droit A'), { button: 'right' })
		await page.click('.wm-context-menu .wm-ctx-move')
		await page.fill('.wm-move-menu .wm-move-filter', 'factu')
		await page.waitForSelector('.wm-move-menu .wm-move-list button:has-text("Factures")')
		await page.keyboard.press('Enter')
		await page.waitForSelector('.wm-toast:has-text("2 messages déplacés vers « Factures »")')
		await page.click('.wm-toast:has-text("2 messages déplacés") .wm-toast-action')
		await page.waitForSelector(row('Menu clic droit A'))
		await page.waitForSelector(row('Menu clic droit B'))
		// (as before: read, not followed)
		for (const n of ['A', 'B']) {
			await page.click(row('Menu clic droit ' + n), { button: 'right' })
			if (await page.$('.wm-context-menu .wm-ctx-flag:has-text("Retirer le suivi")')) { await page.click('.wm-context-menu .wm-ctx-flag'); await page.click(row('Menu clic droit ' + n), { button: 'right' }) }
			if (await page.$('.wm-context-menu .wm-ctx-read:has-text("Marquer comme lu")')) await page.click('.wm-context-menu .wm-ctx-read'); else await page.keyboard.press('Escape')
		}
		for (const m of made) await fake('/_test/remove', { id: m.id })
	})

	await step('a message as a PDF: downloaded, or saved in Files with its attachments beside it', async () => {
		const fake = (path, body) => new Promise((resolve, reject) => {
			const data = JSON.stringify(body)
			const req = require('https').request({ host: 'localhost', port: 8443, path, method: 'POST', rejectUnauthorized: false, headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(data) } }, (res) => {
				let out = ''
				res.on('data', (c) => { out += c })
				res.on('end', () => resolve(JSON.parse(out || '{}')))
			})
			req.on('error', reject)
			req.end(data)
		})
		const mail = await fake('/_test/deliver', { subject: 'Facture F-2027-118', sender: ['Comptabilité', 'compta@fournisseur-exemple.fr'], body: '<p>Bonjour,</p><p>Veuillez trouver notre facture d\'électricité de novembre.</p>', isRead: true, attachments: [{ name: 'facture-F-2027-118.txt', text: 'Montant : 418,20 EUR' }] })
		try {
			await page.click('.wm-tab[data-module="mail"]')
			await page.click('.wm-folder:has-text("Boîte de réception")')
			await page.click('.wm-list button[title="Actualiser"]')
			await page.click('.wm-message:has-text("Facture F-2027-118")')
			await page.waitForSelector('.wm-message-header h2:has-text("Facture F-2027-118")')
			// downloaded: a PDF with its subject, its sender, its text and its files
			const pdf = await page.evaluate(async (id) => {
				const accounts = await (await fetch('/apps/webmail/api/accounts', { headers: { requesttoken: OC.requestToken } })).json()
				const acc = accounts.find((a) => a.email === 'user@societe-a.fr').id
				const r = await fetch('/apps/webmail/api/accounts/' + acc + '/message/pdf?itemId=' + encodeURIComponent(id))
				const bytes = new Uint8Array(await r.arrayBuffer())
				return { type: r.headers.get('Content-Type'), disposition: r.headers.get('Content-Disposition'), text: Array.from(bytes, (b) => String.fromCharCode(b)).join('') }
			}, mail.id)
			assert.ok(pdf.type.startsWith('application/pdf'), pdf.type)
			assert.ok(/Facture F-2027-118\.pdf/.test(pdf.disposition), pdf.disposition)
			assert.ok(pdf.text.startsWith('%PDF-1.4') && pdf.text.trimEnd().endsWith('%%EOF'))
			for (const t of ['(Facture F-2027-118) Tj', 'compta@fournisseur-exemple.fr', 'facture-F-2027-118.txt', 'lectricit']) assert.ok(pdf.text.includes(t), t)
			// saved in Files, with its attachment beside it
			await page.evaluate(() => { OC.dialogs.filepicker = (title, cb) => cb('/Courriers e2e') })
			page.once('dialog', (d) => { assert.ok(d.message().includes('pièce jointe'), d.message()); d.accept() })
			await page.click('.wm-message-toolbar .wm-more-btn')
			await shot('116-pdf-menu')
			await page.click('.wm-more-menu .wm-pdf-save-btn')
			await page.waitForSelector('.wm-toast:has-text("Facture F-2027-118.pdf enregistré, avec sa pièce jointe")')
			const files = await page.evaluate(async () => {
				const uid = OC.getCurrentUser().uid
				const r = await fetch('/remote.php/dav/files/' + encodeURIComponent(uid) + '/' + encodeURIComponent('Courriers e2e') + '/', { method: 'PROPFIND', headers: { requesttoken: OC.requestToken, Depth: '1' } })
				return decodeURIComponent(await r.text())
			})
			assert.ok(/\d{4}-\d{2}-\d{2} Facture F-2027-118\.pdf/.test(files) && files.includes('facture-F-2027-118.txt'), files.slice(0, 500))
		} finally {
			await fake('/_test/remove', { id: mail.id })
			await page.evaluate(async () => {
				const uid = OC.getCurrentUser().uid
				await fetch('/remote.php/dav/files/' + encodeURIComponent(uid) + '/' + encodeURIComponent('Courriers e2e'), { method: 'DELETE', headers: { requesttoken: OC.requestToken } })
			})
		}
	})

	await step('priority senders: a star in the list, their own view, told in the bell even when it is off for the others', async () => {
		const occ = (args) => execSync(OCC + ' ' + args, { encoding: 'utf8' })
		const fake = (path, body) => new Promise((resolve, reject) => {
			const data = JSON.stringify(body)
			const req = require('https').request({ host: 'localhost', port: 8443, path, method: 'POST', rejectUnauthorized: false, headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(data) } }, (res) => {
				let out = ''
				res.on('data', (c) => { out += c })
				res.on('end', () => resolve(JSON.parse(out || '{}')))
			})
			req.on('error', reject)
			req.end(data)
		})
		const account = () => page.evaluate(async () => {
			const accounts = await (await fetch('/apps/webmail/api/accounts', { headers: { requesttoken: OC.requestToken } })).json()
			return accounts.find((a) => a.email === 'user@societe-a.fr').id
		})
		const setBell = async (on) => {
			const id = await account()
			await page.evaluate(async ({ on, id }) => {
				await fetch('/apps/webmail/api/accounts/' + id, { method: 'PUT', headers: { requesttoken: OC.requestToken, 'Content-Type': 'application/json' }, body: JSON.stringify({ bell: on }) })
			}, { on, id })
		}
		const bell = () => page.evaluate(async () => (await (await fetch('/ocs/v2.php/apps/notifications/api/v2/notifications?format=json', { headers: { 'OCS-APIREQUEST': 'true' } })).json()).ocs.data.filter((n) => n.app === 'webmail'))
		const jobId = (occ("background-job:list --class='OCA\\Webmail\\BackgroundJob\\NewMailJob'").match(/\|\s*(\d+)\s*\|\s*OCA/) || [])[1]
		const runJob = () => occ('background-job:execute ' + jobId + ' --force-execute')
		const made = []
		try {
			made.push(await fake('/_test/deliver', { subject: 'Contrat cadre 2027', sender: ['Le Directeur', 'directeur@client-vip.fr'], body: '<p>Merci de relire le contrat.</p>', isRead: true }))
			await page.click('.wm-tab[data-module="mail"]')
			await page.click('.wm-folder:has-text("Boîte de réception")')
			await page.click('.wm-list button[title="Actualiser"]')
			await page.click('.wm-message:has-text("Contrat cadre 2027")')
			await page.waitForSelector('.wm-message-header h2:has-text("Contrat cadre 2027")')
			await page.click('.wm-message-header .wm-vip-btn')
			await page.waitForSelector('.wm-toast:has-text("est un expéditeur prioritaire")')
			await page.waitForSelector('.wm-message:has-text("Contrat cadre 2027") .wm-vip-star')
			await page.waitForSelector('.wm-message-header .wm-vip-btn.on')
			// its own view
			await page.click('.wm-vip-link')
			await page.waitForSelector('.wm-vip-list .wm-vip-open:has-text("Contrat cadre 2027")')
			await shot('117-priority-senders')
			// the bell off: only the priority senders are told
			await setBell(false)
			runJob() // (the first check only notes what is there)
			made.push(await fake('/_test/deliver', { subject: 'Avenant au contrat', sender: ['Le Directeur', 'directeur@client-vip.fr'], body: '<p>Voici l\'avenant.</p>' }))
			made.push(await fake('/_test/deliver', { subject: 'Lettre d\'info de décembre', sender: ['Lettre', 'news@exemple.org'], body: '<p>Info.</p>' }))
			runJob()
			const mine = (await bell()).filter((n) => n.message.includes('Avenant au contrat') || n.message.includes('Lettre d\'info de décembre'))
			assert.strictEqual(mine.length, 1, JSON.stringify(mine))
			assert.ok(mine[0].subject.startsWith('⭐ ') && mine[0].message.includes('Avenant au contrat'), JSON.stringify(mine[0]))
			// no longer a priority sender, from the right-click menu
			await page.click('.wm-message:has-text("Contrat cadre 2027")', { button: 'right' })
			await page.click('.wm-context-menu .wm-ctx-vip:has-text("Retirer des prioritaires")')
			await page.waitForSelector('.wm-toast:has-text("n\'est plus un expéditeur prioritaire")')
			await page.waitForFunction(() => !document.querySelector('.wm-vip-link') && !document.querySelector('.wm-vip-star'))
		} finally {
			await setBell(true)
			for (const m of made) await fake('/_test/remove', { id: m.id })
		}
	})

	await step('quiet hours: no new message in the bell during them, the priority senders if wished; settings kept', async () => {
		const occ = (args) => execSync(OCC + ' ' + args, { encoding: 'utf8' })
		const fake = (path, body) => new Promise((resolve, reject) => {
			const data = JSON.stringify(body)
			const req = require('https').request({ host: 'localhost', port: 8443, path, method: 'POST', rejectUnauthorized: false, headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(data) } }, (res) => {
				let out = ''
				res.on('data', (c) => { out += c })
				res.on('end', () => resolve(JSON.parse(out || '{}')))
			})
			req.on('error', reject)
			req.end(data)
		})
		const accountId = await page.evaluate(async () => (await (await fetch('/apps/webmail/api/accounts', { headers: { requesttoken: OC.requestToken } })).json()).find((a) => a.email === 'user@societe-a.fr').id)
		const bell = (subject) => page.evaluate(async (subject) => (await (await fetch('/ocs/v2.php/apps/notifications/api/v2/notifications?format=json', { headers: { 'OCS-APIREQUEST': 'true' } })).json()).ocs.data
			.filter((n) => n.app === 'webmail' && n.message.includes(subject)), subject)
		const vip = (email, on) => page.evaluate(async ({ id, email, on }) => {
			await fetch('/apps/webmail/api/accounts/' + id + '/vip', { method: 'POST', headers: { requesttoken: OC.requestToken, 'Content-Type': 'application/json' }, body: JSON.stringify({ email, vip: on }) })
		}, { id: accountId, email, on })
		const jobId = (occ("background-job:list --class='OCA\\Webmail\\BackgroundJob\\NewMailJob'").match(/\|\s*(\d+)\s*\|\s*OCA/) || [])[1]
		const runJob = () => occ('background-job:execute ' + jobId + ' --force-execute')
		const made = []
		let tz = ''
		try { tz = occ('user:setting admin core timezone').trim() } catch (e) { /* none */ }
		try {
			occ('user:setting admin core timezone UTC')
			await vip('directeur@client-calme.fr', true)
			const hour = new Date().getUTCHours()
			await page.click('.wm-tab[data-module="mail"]')
			await page.click('text=Comptes et signature')
			const card = '.wm-notify-settings'
			await page.waitForSelector(card + ' input[name=quietOn]:not([disabled])')
			assert.ok(await page.isHidden(card + ' .wm-quiet-more'), 'the details only once on')
			await page.check(card + ' input[name=quietOn]')
			await page.waitForSelector('.wm-toast:has-text("Heures calmes de 19 h à 8 h")')
			await page.waitForSelector(card + ' select[name=quietFrom]:not([disabled])')
			await page.selectOption(card + ' select[name=quietFrom]', String(hour))
			await page.waitForSelector(card + ' select[name=quietTo]:not([disabled])')
			await page.selectOption(card + ' select[name=quietTo]', String((hour + 2) % 24))
			await page.waitForSelector('.wm-toast:has-text("Heures calmes de ' + hour + ' h à ' + ((hour + 2) % 24) + ' h")')
			await shot('123-quiet-hours')
			// kept
			await page.reload()
			await page.click('text=Comptes et signature')
			await page.waitForSelector(card + ' input[name=quietOn]:checked:not([disabled])')
			assert.strictEqual(await page.inputValue(card + ' select[name=quietFrom]'), String(hour))
			assert.ok(await page.isChecked(card + ' input[name=quietVip]'))
			// now: the others silent, the priority sender told
			runJob()
			made.push(await fake('/_test/deliver', { subject: 'Calme : relance fournisseur', sender: ['Fournisseur', 'ventes@fournisseur-calme.fr'], body: '<p>Relance.</p>' }))
			made.push(await fake('/_test/deliver', { subject: 'Calme : urgent du directeur', sender: ['Le Directeur', 'directeur@client-calme.fr'], body: '<p>Urgent.</p>' }))
			runJob()
			assert.strictEqual((await bell('Calme : relance fournisseur')).length, 0, 'quiet')
			assert.strictEqual((await bell('Calme : urgent du directeur')).length, 1, 'a priority sender comes through')
			// not even them
			await page.uncheck(card + ' input[name=quietVip]')
			await page.waitForSelector(card + ' input[name=quietVip]:not([disabled])')
			made.push(await fake('/_test/deliver', { subject: 'Calme : second message du directeur', sender: ['Le Directeur', 'directeur@client-calme.fr'], body: '<p>Encore.</p>' }))
			runJob()
			assert.strictEqual((await bell('Calme : second message du directeur')).length, 0)
			// off: told again
			await page.uncheck(card + ' input[name=quietOn]')
			await page.waitForSelector('.wm-toast:has-text("Heures calmes désactivées")')
			made.push(await fake('/_test/deliver', { subject: 'Calme : après les heures calmes', sender: ['Fournisseur', 'ventes@fournisseur-calme.fr'], body: '<p>Après.</p>' }))
			runJob()
			assert.strictEqual((await bell('Calme : après les heures calmes')).length, 1)
		} finally {
			try { occ('user:setting --delete admin webmail quiet_hours') } catch (e) { /* none */ }
			await vip('directeur@client-calme.fr', false)
			if (tz) occ('user:setting admin core timezone ' + tz)
			else occ('user:setting --delete admin core timezone')
			for (const m of made) await fake('/_test/remove', { id: m.id })
			await page.goto(NC + '/apps/webmail/')
			await page.waitForSelector('.wm-message')
		}
	})

	await step('« Répondre plus tard »: in « À répondre » of « Aujourd\'hui », a reminder at its time, gone once answered', async () => {
		const occ = (args) => execSync(OCC + ' ' + args, { encoding: 'utf8' })
		const fake = (path, body) => new Promise((resolve, reject) => {
			const data = JSON.stringify(body)
			const req = require('https').request({ host: 'localhost', port: 8443, path, method: 'POST', rejectUnauthorized: false, headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(data) } }, (res) => {
				let out = ''
				res.on('data', (c) => { out += c })
				res.on('end', () => resolve(JSON.parse(out || '{}')))
			})
			req.on('error', reject)
			req.end(data)
		})
		const a = await fake('/_test/deliver', { subject: 'Question budget 2027', sender: ['Paul Durand', 'paul.durand@societe-a.fr'], body: '<p>Peux-tu me dire le budget ?</p>', isRead: true })
		const b = await fake('/_test/deliver', { subject: 'Devis à valider', sender: ['Client B', 'contact@client-b.fr'], body: '<p>Merci de valider le devis.</p>', isRead: true })
		const accountId = await page.evaluate(async () => (await (await fetch('/apps/webmail/api/accounts', { headers: { requesttoken: OC.requestToken } })).json()).find((x) => x.email === 'user@societe-a.fr').id)
		try {
			await page.click('.wm-tab[data-module="mail"]')
			await page.click('.wm-folder:has-text("Boîte de réception")')
			await page.click('.wm-list button[title="Actualiser"]')
			// from the toolbar: a time later today, or tomorrow
			await page.click('.wm-message:has-text("Question budget 2027")')
			await page.waitForSelector('.wm-message-header h2:has-text("Question budget 2027")')
			await page.click('.wm-message-toolbar .wm-reply-later-btn')
			await page.waitForSelector('.wm-reply-later-menu')
			await shot('118-reply-later')
			await page.click('.wm-reply-later-menu .wm-flag-choice >> nth=0')
			await page.waitForSelector('.wm-toast:has-text("Nextcloud vous le rappellera")')
			// the other one due already: told in the bell (not the first one, not due)
			await page.evaluate(async ({ id, itemId }) => {
				await fetch('/apps/webmail/api/accounts/' + id + '/reply-later', { method: 'POST', headers: { requesttoken: OC.requestToken, 'Content-Type': 'application/json' }, body: JSON.stringify({ itemId, until: new Date(Date.now() - 60000).toISOString() }) })
			}, { id: accountId, itemId: b.id })
			const jobId = (occ("background-job:list --class='OCA\\Webmail\\BackgroundJob\\ReplyLaterJob'").match(/\|\s*(\d+)\s*\|\s*OCA/) || [])[1]
			assert(jobId, 'background job registered')
			occ('background-job:execute ' + jobId + ' --force-execute')
			const bell = await page.evaluate(async () => (await (await fetch('/ocs/v2.php/apps/notifications/api/v2/notifications?format=json', { headers: { 'OCS-APIREQUEST': 'true' } })).json()).ocs.data.filter((n) => n.app === 'webmail' && n.subject.startsWith('À répondre')))
			assert.deepStrictEqual(bell.map((n) => n.subject), ['À répondre : Devis à valider'])
			// « Aujourd'hui »: both, the late one first
			await page.click('.wm-today-link')
			await page.waitForSelector('.wm-today-to-reply li:has-text("Devis à valider"):has-text("En retard")')
			await page.waitForSelector('.wm-today-to-reply li:has-text("Question budget 2027")')
			await shot('119-to-reply')
			// answered: out of the list by itself
			await page.click('.wm-today-to-reply li:has-text("Devis à valider") .wm-today-open')
			await page.waitForSelector('.wm-message-header h2:has-text("Devis à valider")')
			await page.click('.wm-reply-btn')
			await page.fill('.wm-editor', 'C\'est validé, merci.')
			await submitCompose()
			await page.click('.wm-today-link')
			await page.waitForSelector('.wm-today-to-reply li:has-text("Question budget 2027")')
			assert.ok(!(await page.$('.wm-today-to-reply li:has-text("Devis à valider")')), 'answered: gone')
			// no more reminder for the other one
			await page.click('.wm-today-to-reply li:has-text("Question budget 2027") .wm-today-done')
			await page.waitForFunction(() => !document.querySelector('.wm-today-to-reply li:not(.wm-muted)') || ![...document.querySelectorAll('.wm-today-to-reply li')].some((li) => li.textContent.includes('Question budget 2027')))
		} finally {
			for (const m of [a, b]) await fake('/_test/remove', { id: m.id })
		}
	})

	await step('saved searches: kept under a name, in the side pane with their unread messages, opened again, deleted', async () => {
		const fake = (path, body) => new Promise((resolve, reject) => {
			const data = JSON.stringify(body)
			const req = require('https').request({ host: 'localhost', port: 8443, path, method: 'POST', rejectUnauthorized: false, headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(data) } }, (res) => {
				let out = ''
				res.on('data', (c) => { out += c })
				res.on('end', () => resolve(JSON.parse(out || '{}')))
			})
			req.on('error', reject)
			req.end(data)
		})
		const sent = await fake('/_test/deliver', { subject: 'Rapport Zorglub trimestriel', sender: ['Paul Durand', 'paul.durand@societe-a.fr'], body: '<p>Le rapport.</p>', isRead: false })
		try {
			await page.click('.wm-tab[data-module="mail"]')
			await page.click('.wm-folder:has-text("Boîte de réception")')
			await page.fill('.wm-search', 'zorglub')
			await page.press('.wm-search', 'Enter')
			await page.waitForSelector('.wm-message:has-text("Rapport Zorglub trimestriel")')
			page.once('dialog', (d) => d.accept('Rapports Zorglub'))
			await page.click('.wm-search-info .wm-save-search')
			const entry = '.wm-saved-searches .wm-saved-search:has-text("Rapports Zorglub")'
			await page.waitForSelector(entry + ' .wm-badge:has-text("1")')
			// the search shown is the one saved: no second « Enregistrer »
			await page.waitForSelector(entry + '.active')
			assert.ok(!(await page.$('.wm-search-info .wm-save-search')), 'already saved')
			await shot('108-saved-search')
			// back to the whole folder, then the search again in one click
			await page.click('.wm-search-info a:has-text("effacer")')
			await page.waitForFunction(() => !document.querySelector('.wm-search-info'))
			await page.click(entry)
			await page.waitForSelector('.wm-search-info:has-text("zorglub")')
			assert.strictEqual(await page.inputValue('.wm-search'), 'zorglub')
			await page.waitForSelector('.wm-message:has-text("Rapport Zorglub trimestriel")')
			// kept on the server: there after a reload
			await page.reload()
			await page.waitForSelector(entry)
			await page.click('.wm-saved-searches .wm-folder-row:has-text("Rapports Zorglub") .wm-unsave-search')
			await page.waitForFunction(() => !document.querySelector('.wm-saved-searches'))
		} finally {
			await fake('/_test/remove', { id: sent.id })
		}
	})

	await step('absence of Nextcloud: the automatic reply scheduled on its dates (the model, then its own message), taken away with it', async () => {
		const ymd = (days) => { const d = new Date(Date.now() + days * 86400000); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0') }
		const absence = (method, body) => page.evaluate(async ({ method, body }) => {
			const r = await fetch('/ocs/v2.php/apps/dav/api/v1/outOfOffice/' + encodeURIComponent(OC.getCurrentUser().uid) + '?format=json', {
				method,
				headers: { 'OCS-APIREQUEST': 'true', requesttoken: OC.requestToken, 'Content-Type': 'application/json' },
				body: body ? JSON.stringify(body) : undefined,
			})
			return { status: r.status, text: await r.text() }
		}, { method, body })
		const oof = () => page.evaluate(async () => {
			const headers = { requesttoken: OC.requestToken }
			const accounts = await (await fetch('/apps/webmail/api/accounts', { headers })).json()
			const id = accounts.find((a) => a.email === 'user@societe-a.fr').id
			return (await fetch('/apps/webmail/api/accounts/' + id + '/oof', { headers })).json()
		})
		// the card in « Comptes et signature »: on, the model of the webmail shown
		await page.click('text=Comptes et signature')
		await page.waitForSelector('.wm-absence-settings:not([hidden]) input[name=absenceReplies]:checked')
		assert.ok((await page.getAttribute('.wm-absence-settings textarea', 'placeholder')).includes('{debut}'))
		await shot('105-absence-settings')
		// an invitation of a colleague on one of the days, not answered yet
		const invite = (body) => new Promise((resolve, reject) => {
			const data = JSON.stringify(body)
			const req = require('https').request({ host: 'localhost', port: 8443, path: '/_test/invite', method: 'POST', rejectUnauthorized: false, headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(data) } }, (res) => {
				let out = ''
				res.on('data', (c) => { out += c })
				res.on('end', () => resolve(JSON.parse(out || '{}')))
			})
			req.on('error', reject)
			req.end(data)
		})
		const inviteDay = new Date(Date.now() + 9 * 86400000).toISOString().slice(0, 10)
		await invite({ subject: 'Point annuel fournisseurs', start: inviteDay + 'T09:00:00Z', end: inviteDay + 'T10:00:00Z', organizer: ['Paul Durand', 'paul.durand@societe-a.fr'] })
		const invited = () => page.evaluate(async (day) => {
			const headers = { requesttoken: OC.requestToken }
			const accounts = await (await fetch('/apps/webmail/api/accounts', { headers })).json()
			const id = accounts.find((a) => a.email === 'user@societe-a.fr').id
			const q = new URLSearchParams({ start: day + 'T00:00:00Z', end: day + 'T23:59:00Z', tz: 'UTC' })
			return (await (await fetch('/apps/webmail/api/accounts/' + id + '/calendar/events?' + q, { headers })).json()).events.filter((e) => e.subject === 'Point annuel fournisseurs')
		}, inviteDay)
		assert.strictEqual((await invited()).length, 1, 'the invitation is there')
		// no model: the message of the absence as it is, on its dates
		const first = ymd(7)
		const last = ymd(14)
		const saved = await absence('POST', { firstDay: first, lastDay: last, status: 'Congés', message: 'En congés, je lirai vos messages à mon retour.', replacementUserId: null })
		assert.strictEqual(saved.status, 200, saved.text)
		let o = await oof()
		assert.strictEqual(o.state, 'Scheduled', JSON.stringify(o))
		assert.strictEqual(o.audience, 'All')
		assert.strictEqual(o.internal, 'En congés, je lirai vos messages à mon retour.')
		assert.ok(new Date(o.start).getTime() <= new Date(first + 'T00:00:00').getTime() + 86400000 && new Date(o.end) > new Date(last + 'T12:00:00'), o.start + ' ' + o.end)
		// the invitation of those days declined (the organiser told), by default
		assert.strictEqual((await invited()).length, 0, 'declined: gone from the calendar')
		assert.strictEqual(await page.inputValue('.wm-absence-settings select[name=absenceInvites]'), 'decline')
		const bell = await page.evaluate(async () => (await (await fetch('/ocs/v2.php/apps/notifications/api/v2/notifications?format=json', { headers: { 'OCS-APIREQUEST': 'true' } })).json()).ocs.data.filter((n) => n.app === 'webmail' && n.subject.startsWith('Réponse automatique programmée')))
		assert.ok(bell.length === 1 && bell[0].message.includes('user@societe-a.fr'), JSON.stringify(bell))
		// a model of one's own: the dates and the message of the absence filled in
		await page.fill('.wm-absence-settings textarea', 'Absent jusqu\'au {fin}, de retour le {retour}.\n\n{message}{remplacement}\n\n{nom}')
		await page.click('.wm-absence-settings button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("programmeront la réponse automatique")')
		assert.strictEqual((await absence('POST', { firstDay: first, lastDay: last, status: 'Congés', message: 'Bonnes fêtes !', replacementUserId: null })).status, 200)
		o = await oof()
		assert.ok(o.internal.startsWith('Absent jusqu\'au ') && o.internal.includes(', de retour le ') && o.internal.includes('Bonnes fêtes !'), o.internal)
		// removed: the reply taken away
		assert.strictEqual((await absence('DELETE')).status, 200)
		o = await oof()
		assert.strictEqual(o.state, 'Disabled', JSON.stringify(o))
		// switched off: an absence no longer changes it
		await page.fill('.wm-absence-settings textarea', '')
		await page.uncheck('.wm-absence-settings input[name=absenceReplies]')
		await page.click('.wm-absence-settings button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("ne changeront plus la réponse automatique")')
		assert.strictEqual((await absence('POST', { firstDay: first, lastDay: last, status: 'Congés', message: 'x', replacementUserId: null })).status, 200)
		assert.strictEqual((await oof()).state, 'Disabled')
		await absence('DELETE')
		await page.check('.wm-absence-settings input[name=absenceReplies]')
		await page.click('.wm-absence-settings button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("programmeront la réponse automatique")')
		await page.evaluate(() => fetch('/ocs/v2.php/apps/notifications/api/v2/notifications', { method: 'DELETE', headers: { 'OCS-APIREQUEST': 'true', requesttoken: OC.requestToken } }))
		await page.click('.wm-folder:has-text("Boîte de réception")')
	})

	await step('signature of the organisation: a model of the administrator filled in with the Nextcloud profile, proposed or imposed', async () => {
		const profile = (key, value) => page.evaluate(async ({ key, value }) => {
			const r = await fetch('/ocs/v2.php/cloud/users/' + encodeURIComponent(OC.getCurrentUser().uid) + '?format=json', {
				method: 'PUT',
				headers: { 'OCS-APIREQUEST': 'true', requesttoken: OC.requestToken, 'Content-Type': 'application/json' },
				body: JSON.stringify({ key, value }),
			})
			return r.status
		}, { key, value })
		assert.strictEqual(await profile('role', 'Responsable achats'), 200)
		// (Nextcloud may keep it as +33493000000)
		assert.strictEqual(await profile('phone', '+33 4 93 00 00 00'), 200)
		const admin = async (template, mode) => {
			await page.goto(NC + '/settings/admin/webmail')
			await page.fill('#webmail-org-signature', template)
			await page.selectOption('#webmail-org-signature-mode', mode)
			await page.click('#webmail-org-signature-save')
			await page.waitForSelector('#webmail-org-signature-msg.success')
		}
		try {
			// a line whose field is empty (no address in the profile) goes away
			await admin('<p><b>{nom}</b><br>{fonction}<br>Tél. : {telephone}<br>Adresse : {adresse}</p><p><i>Société A — mentions légales</i></p>', 'forced')
			const preview = await page.textContent('#webmail-org-signature-preview')
			assert.ok(preview.includes('Responsable achats') && /Tél\. : \+33 ?4 ?93 ?00 ?00 ?00/.test(preview) && !preview.includes('Adresse') && preview.includes('mentions légales'), preview)
			await shot('107-org-signature-admin')
			// imposed: every new message ends with it
			await page.goto(NC + '/apps/webmail/')
			await page.waitForSelector('.wm-message')
			for (let i = 0; i < 5 && !(await page.$('.wm-compose input[name=to]')); i++) {
				await page.click('.wm-compose-btn')
				await page.waitForSelector('.wm-compose input[name=to]', { timeout: 3000 }).catch(() => {})
			}
			await page.waitForSelector('.wm-editor .wm-signature:has-text("Responsable achats")')
			const sig = await page.textContent('.wm-editor .wm-signature')
			assert.ok(/Tél\. : \+33 ?4 ?93 ?00 ?00 ?00/.test(sig) && sig.includes('mentions légales'), sig)
			// (the message left unwritten: nothing kept)
			await page.goto(NC + '/apps/webmail/')
			await page.waitForSelector('.wm-message')
			// shown in « Comptes et signature »
			await page.click('text=Comptes et signature')
			await page.waitForSelector('.wm-account-card:has-text("user@societe-a.fr") .wm-org-signature-note:has-text("Votre organisation impose sa signature")')
			await shot('107-org-signature-account')
		} finally {
			await admin('', 'off')
			await profile('role', '')
			await profile('phone', '')
			await page.goto(NC + '/apps/webmail/')
			await page.waitForSelector('.wm-message')
		}
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
		await more('.wm-rule-from-msg')
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
		// in Nextcloud's bell (its panel asks for them on its own: once more if it asked too early)
		for (let i = 0; i < 3; i++) {
			await page.reload()
			await page.click('.notifications-button')
			if (await page.waitForSelector('.notification:has-text("Budget du chantier")', { timeout: 15000 }).then(() => true, () => false)) break
		}
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
				// (the visio in the description only: found there for the meetings of the next hours)
				body: JSON.stringify({ event: { subject: 'Appel fournisseur', start: iso(10), end: iso(40), location: 'Bureau 2', reminder: 15, body: 'Ordre du jour : tarifs.\n\nRejoindre la visio : https://visio.example.org/r/e2e-fournisseur' } }),
			})
			if (!r.ok) throw new Error('event not created: ' + r.status)
			return { account: id, event: (await r.json()).id }
		})
		runJob()
		let mine = (await reminders()).filter((n) => n.subject === 'Rappel : Appel fournisseur')
		assert.strictEqual(mine.length, 1, JSON.stringify(await reminders()))
		// (« Demain » when the test runs just before midnight)
		assert(/^(Aujourd'hui|Demain) \d\d:\d\d · Bureau 2 · user@societe-a\.fr$/.test(mine[0].message), mine[0].message)
		// joined from the bell
		const join = (mine[0].actions || []).find((a) => a.label === 'Rejoindre')
		assert(join && join.link === 'https://visio.example.org/r/e2e-fournisseur' && join.type === 'WEB' && join.primary, JSON.stringify(mine[0].actions))
		// once only
		runJob()
		assert.strictEqual((await reminders()).filter((n) => n.subject === 'Rappel : Appel fournisseur').length, 1)
		await page.reload()
		// « Réunion dans 10 min · Rejoindre », in the corner of the webmail
		const banner = '.wm-meeting-banner:has-text("Appel fournisseur")'
		await page.waitForSelector(banner)
		assert(/« Appel fournisseur » commence (dans \d+ minutes?|maintenant)/.test(await page.textContent(banner + ' .wm-meeting-text')), await page.textContent(banner))
		assert.strictEqual(await page.getAttribute(banner + ' a.wm-meeting-join', 'href'), 'https://visio.example.org/r/e2e-fournisseur')
		await shot('49-meeting-banner')
		await page.click('.notifications-button')
		await page.waitForSelector('.notification:has-text("Appel fournisseur")')
		await shot('49-reminder')
		await page.keyboard.press('Escape')
		// hidden for this meeting, until the page is opened again in another tab
		await page.click(banner + ' .wm-meeting-close')
		await page.waitForSelector(banner, { state: 'detached' })
		await page.reload()
		await page.waitForSelector('.wm-message')
		await page.waitForTimeout(1500)
		assert(!(await page.$(banner)), 'hidden after a reload')
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

	await step('« Aujourd\'hui »: the meetings, tasks, follow-ups and snoozed messages of the day, all accounts', async () => {
		const ctx = await page.evaluate(async () => {
			const headers = { requesttoken: OC.requestToken, 'Content-Type': 'application/json' }
			const accounts = await (await fetch('/apps/webmail/api/accounts', { headers })).json()
			const id = accounts.find((a) => a.email === 'user@societe-a.fr').id
			const post = async (path, body) => {
				const r = await fetch('/apps/webmail/api/accounts/' + id + path, { method: 'POST', headers, body: JSON.stringify(body) })
				if (!r.ok) throw new Error(path + ' ' + r.status + ' ' + await r.text())
				return r.json()
			}
			const iso = (min) => new Date(Date.now() + min * 60000).toISOString().slice(0, 16)
			const local = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0')
			const today = local(new Date())
			// a meeting under way, with its visio (always « today », whatever the hour)
			const ev = await post('/calendar/events?tz=UTC', { event: { subject: 'Point du jour', start: iso(-20), end: iso(40), location: 'https://visio.example.org/r/point-du-jour', reminder: -1 } })
			const task = await post('/tasks', { task: { subject: 'Rappeler le notaire', due: today, importance: 'Normal' } })
			const late = await post('/tasks', { task: { subject: 'Envoyer le bilan', due: local(new Date(Date.now() - 3 * 86400000)), importance: 'Normal' } })
			const inbox = await (await fetch('/apps/webmail/api/accounts/' + id + '/messages?folder=inbox&offset=0&limit=50&q=', { headers })).json()
			const news1 = inbox.items.find((m) => m.subject === 'Newsletter n°1')
			const news2 = inbox.items.find((m) => m.subject === 'Newsletter n°2')
			await post('/flags', { itemIds: [news1.id], flag: 'flagged', value: true, due: today })
			// back in two hours: only while that is still today
			const back = new Date(Date.now() + 2 * 3600000)
			let snoozed = null
			if (local(back) === today) snoozed = (await post('/snooze', { itemIds: [news2.id], until: back.toISOString() })).ids[0]
			return { id, ev: ev.id, task: task.id, late: late.id, news1: news1.id, snoozed }
		})
		try {
			await page.click('.wm-tab[data-module="mail"]')
			// (the banner of the meeting under way, out of the way)
			await page.waitForSelector('.wm-meeting-banner:has-text("Point du jour")', { timeout: 70000 }).catch(() => {})
			if (await page.$('.wm-meeting-close')) await page.click('.wm-meeting-close')
			await page.click('.wm-today-link')
			await page.waitForSelector('.wm-today-section.wm-today-meetings li:has-text("Point du jour")')
			const meeting = '.wm-today-meetings li:has-text("Point du jour")'
			assert.strictEqual(await page.getAttribute(meeting + ' a.wm-today-join', 'href'), 'https://visio.example.org/r/point-du-jour')
			assert.ok(await page.$(meeting + '.now'), 'under way')
			await page.waitForSelector('.wm-today-tasks li:has-text("Rappeler le notaire")')
			await page.waitForSelector('.wm-today-tasks li.late:has-text("Envoyer le bilan") .wm-today-when:has-text("En retard")')
			await page.waitForSelector('.wm-today-flagged li:has-text("Newsletter n°1")')
			if (ctx.snoozed) await page.waitForSelector('.wm-today-snoozed li:has-text("Newsletter n°2") .wm-today-when:has-text("Revient à")')
			await shot('102-today')
			// one click: the message followed up opens
			await page.click('.wm-today-flagged li:has-text("Newsletter n°1") .wm-today-open')
			await page.waitForSelector('.wm-message-header h2:has-text("Newsletter n°1")')
			assert.ok(!(await page.$('.wm-today')), 'the message instead')
			// and a task in its module
			await page.click('.wm-today-link')
			await page.click('.wm-today-tasks li:has-text("Rappeler le notaire") .wm-today-open')
			await page.waitForFunction(() => { const i = document.querySelector('.wm-detail input[name=subject]'); return i && i.value === 'Rappeler le notaire' })
			await page.click('.wm-tab[data-module="mail"]')
		} finally {
			await page.evaluate(async (c) => {
				const headers = { requesttoken: OC.requestToken, 'Content-Type': 'application/json' }
				const post = (path, body) => fetch('/apps/webmail/api/accounts/' + c.id + path, { method: 'POST', headers, body: JSON.stringify(body) })
				await post('/calendar/delete', { eventId: c.ev })
				await post('/tasks/delete', { taskId: c.task })
				await post('/tasks/delete', { taskId: c.late })
				await post('/flags', { itemIds: [c.news1], flag: 'flagged', value: false })
				if (c.snoozed) await post('/snooze/back', { itemId: c.snoozed })
			}, ctx)
		}
	})

	await step('« Proposer des créneaux »: free slots in a message, the guest picks one on a public page, the meeting is made', async () => {
		await page.click('.wm-tab[data-module="mail"]')
		for (let i = 0; i < 5 && !(await page.$('.wm-compose input[name=to]')); i++) {
			await page.click('.wm-compose-btn')
			await page.waitForSelector('.wm-compose input[name=to]', { timeout: 3000 }).catch(() => {})
		}
		await page.fill('.wm-compose input[name=subject]', 'Point devis toiture')
		await page.click('.wm-editor')
		await page.keyboard.type('Bonjour,')
		await page.keyboard.press('Enter')
		await page.click('.wm-editor-bar button[title^="Proposer des créneaux"]')
		await page.waitForSelector('.wm-slots-dialog')
		assert.strictEqual(await page.inputValue('.wm-slots-dialog input[name=subject]'), 'Point devis toiture')
		await page.selectOption('.wm-slots-dialog select[name=minutes]', '60')
		await page.selectOption('.wm-slots-dialog select[name=count]', '3')
		await shot('103-slots-dialog')
		await page.click('.wm-slots-dialog button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("3 créneaux insérés")')
		const links = await page.$$eval('.wm-editor ul.wm-slots a', (els) => els.map((a) => ({ href: a.getAttribute('href'), text: a.textContent })))
		assert.strictEqual(links.length, 3, JSON.stringify(links))
		assert.ok(links.every((l) => /\/apps\/webmail\/rdv\/[A-Za-z0-9_-]+\.[A-Za-z0-9]{24}\?slot=\d$/.test(l.href)), JSON.stringify(links))
		await shot('103-slots-inserted')
		// the guest, without an account: the second slot of the message
		const guest = await browser.newContext({ locale: 'fr-FR', timezoneId: 'Europe/Paris' })
		const gp = await guest.newPage()
		try {
			await gp.goto(links[1].href)
			await gp.waitForSelector('.wm-booking h2:has-text("Point devis toiture")')
			assert.strictEqual(await gp.$$eval('.wm-booking-slot', (els) => els.length), 3)
			assert.ok(await gp.isChecked('.wm-booking-slot input[value="1"]'), 'the slot clicked in the message')
			await gp.fill('#wm-booking-name', 'Jean Client')
			await gp.fill('#wm-booking-email', 'jean.client@exterieur-e2e.fr')
			await shot('104-booking-page', gp)
			await gp.click('.wm-booking button[type=submit]')
			await gp.waitForSelector('.wm-booking h2:has-text("Rendez-vous confirmé")')
			assert.ok((await gp.textContent('.wm-booking')).includes('jean.client@exterieur-e2e.fr'))
			await shot('104-booking-done', gp)
			// taken: the other slots are no longer offered
			await gp.goto(links[0].href)
			await gp.waitForSelector('.wm-booking:has-text("Un créneau a déjà été choisi")')
			// an unknown link
			const gone = await gp.goto(links[0].href.replace(/\.[A-Za-z0-9]{24}/, '.' + 'x'.repeat(24)))
			assert.strictEqual(gone.status(), 404)
		} finally {
			await guest.close()
		}
		// the meeting in the organiser's calendar, the guest invited; the organiser told in the bell
		const made = await page.evaluate(async () => {
			const headers = { requesttoken: OC.requestToken }
			const accounts = await (await fetch('/apps/webmail/api/accounts', { headers })).json()
			const id = accounts.find((a) => a.email === 'user@societe-a.fr').id
			const q = new URLSearchParams({ start: new Date().toISOString(), end: new Date(Date.now() + 86400000 * 10).toISOString(), tz: 'UTC' })
			const r = await (await fetch('/apps/webmail/api/accounts/' + id + '/calendar/events?' + q, { headers })).json()
			const ev = r.events.find((e) => e.subject === 'Point devis toiture')
			const detail = ev ? await (await fetch('/apps/webmail/api/accounts/' + id + '/calendar/event?' + new URLSearchParams({ eventId: ev.id, tz: 'UTC' }), { headers })).json() : null
			const n = await (await fetch('/ocs/v2.php/apps/notifications/api/v2/notifications?format=json', { headers: { 'OCS-APIREQUEST': 'true' } })).json()
			return { id, ev, detail, bell: n.ocs.data.filter((x) => x.app === 'webmail' && x.subject.startsWith('Rendez-vous pris')) }
		})
		assert.ok(made.ev, 'the meeting made')
		assert.strictEqual(Date.parse(made.ev.end) - Date.parse(made.ev.start), 3600000)
		assert.ok(made.detail.attendees.some((a) => a.email === 'jean.client@exterieur-e2e.fr'), JSON.stringify(made.detail.attendees))
		assert.ok(made.bell.some((x) => x.subject === 'Rendez-vous pris : Point devis toiture' && x.message.startsWith('Jean Client (jean.client@exterieur-e2e.fr) · ')), JSON.stringify(made.bell))
		await page.evaluate(async (m) => {
			const headers = { requesttoken: OC.requestToken, 'Content-Type': 'application/json' }
			await fetch('/apps/webmail/api/accounts/' + m.id + '/calendar/delete', { method: 'POST', headers, body: JSON.stringify({ eventId: m.ev.id }) })
			await fetch('/ocs/v2.php/apps/notifications/api/v2/notifications', { method: 'DELETE', headers: { 'OCS-APIREQUEST': 'true', requesttoken: OC.requestToken } })
		}, made)
		// the message itself thrown away
		await page.waitForSelector('.wm-draft-status:has-text("Brouillon enregistré à")')
		page.once('dialog', (d) => d.accept())
		await page.click('.wm-compose button:has-text("Supprimer le brouillon")')
		await page.waitForSelector('.wm-toast:has-text("Brouillon supprimé")')
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
			// (the new account opens on its inbox: once shown, to the settings)
			await page.waitForSelector('.wm-message')
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
		// (a click while the list is drawn again may be lost: clicked again until the window is there)
		for (let i = 0; i < 5 && !(await page.$('.wm-compose input[name=to]')); i++) {
			await page.click('.wm-compose-btn')
			await page.waitForSelector('.wm-compose input[name=to]', { timeout: 3000 }).catch(() => {})
		}
		// (the window of the message may be drawn again just after opening: typed in the field that stays)
		await page.waitForSelector('.wm-compose input[name=to]')
		await page.locator('.wm-compose input[name=to]').pressSequentially('dup')
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
		// (the list of a moment ago shown first, then the one of Exchange)
		await page.waitForSelector('.wm-agenda-item:has-text("Comité de pilotage"):has-text("Accepté")')
		await page.click('.wm-segmented button:has-text("Mois")')
		await page.waitForSelector('.wm-month-cell.today .wm-event')
		await shot('10-calendar-month')
	})

	await step('calendar: duplicate an event (same title, place, people, length) on another day', async () => {
		await page.click('.wm-month-cell.today .wm-event:has-text("Point hebdo équipe")')
		await page.waitForSelector('.wm-modal .wm-attendees')
		await page.click('.wm-modal .wm-duplicate-btn')
		await page.waitForSelector('.wm-modal h2:has-text("Nouvel événement")')
		assert.strictEqual(await page.inputValue('.wm-modal input[name=subject]'), 'Point hebdo équipe')
		assert.strictEqual(await page.inputValue('.wm-modal input[name=location]'), 'Salle Méditerranée')
		assert.ok((await page.inputValue('.wm-modal input[name=attendees]')).includes('marie.martin@societe-a.fr'))
		// one hour, as the original
		assert.strictEqual(await page.inputValue('.wm-modal input[name=startTime]'), '09:00')
		assert.strictEqual(await page.inputValue('.wm-modal input[name=endTime]'), '10:00')
		// to the next day
		const d = new Date()
		d.setDate(d.getDate() + 1)
		const day = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0')
		await page.fill('.wm-modal input[name=startDate]', day)
		await page.fill('.wm-modal input[name=endDate]', day)
		await page.click('.wm-modal button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Événement créé")')
		await shot('93-duplicate-event')
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

	await step('calendar: a second time zone beside the hours (week and day views), then none', async () => {
		await page.selectOption('select[name=tz2]', 'Asia/Tokyo')
		await page.waitForSelector('.wm-with-tz2 .wm-week-head .wm-week-tz2:has-text("Tokyo UTC+9")')
		// the hour of Tokyo at 08:00 here, that day (summer or winter time)
		const first = await page.getAttribute('.wm-week-col >> nth=0', 'data-date')
		const expected = await page.evaluate((d) => {
			const [y, m, day] = d.split('-').map(Number)
			return new Date(y, m - 1, day, 8).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'Asia/Tokyo' })
		}, first)
		assert.strictEqual(await page.textContent('.wm-week-grid .wm-week-tz2 .wm-week-hour >> nth=8'), expected)
		assert.strictEqual(await page.textContent('.wm-week-grid .wm-week-hours:not(.wm-week-tz2) .wm-week-hour >> nth=8'), '08:00')
		// the events stay where they were
		assert.strictEqual(await page.locator('.wm-week-col.today .wm-week-event:has-text("Point hebdo équipe")').evaluate((e) => e.style.top), '432px')
		await shot('17b-week-second-zone')
		// kept: the day view has it too
		await page.click('.wm-segmented button:has-text("Jour")')
		await page.waitForSelector('.wm-day-view.wm-with-tz2 .wm-week-tz2')
		await page.click('.wm-segmented button:has-text("Semaine")')
		await page.selectOption('select[name=tz2]', '')
		await page.waitForSelector('.wm-week-tz2', { state: 'detached' })
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
		// (the week may be drawn again after the move before: the edge taken once it is there)
		// (the week may be drawn again meanwhile: asked until the edge is there)
		let handle = null
		for (let i = 0; i < 20 && !handle; i++) {
			await page.locator(ev + ' .wm-resize').waitFor()
			handle = await page.locator(ev + ' .wm-resize').boundingBox()
			if (!handle) await page.waitForTimeout(250)
		}
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
		await page.click('.wm-modal button.wm-delete-action:text-is("Supprimer")')
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
		await page.click('.wm-modal button.wm-delete-action:text-is("Supprimer")')
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
		const own = await page.$$eval('.wm-cal-row:not([data-key^="nc:"]) .wm-cal-name', (els) => els.map((e) => e.textContent))
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
		const rows = await page.$$eval('.wm-cal-row:not([data-key^="nc:"])', (els) => els.map((e) => e.textContent.replace('✕', '').replace('🔗', '').trim()))
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
		// (and the Nextcloud calendar of the user; not the birthdays, read only)
		assert.deepStrictEqual(options, ['Calendrier', 'Congés équipe', 'Agenda de Marie Martin', 'Personal (Nextcloud)'], 'read-only calendars are not offered')
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

	await step('contacts: a dot shows who is on line (the Nextcloud status of the account with their address)', async () => {
		const occ = (args) => execSync(OCC + ' ' + args, { encoding: 'utf8' })
		const setStatus = (statusType) => page.evaluate(async (type) => {
			const r = await fetch(OC.linkToOCS('apps/user_status/api/v1/user_status', 2) + 'status?format=json', {
				method: 'PUT', headers: { requesttoken: OC.requestToken, 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }, body: JSON.stringify({ statusType: type }),
			})
			if (!r.ok) throw new Error('status ' + r.status)
		}, statusType)
		const dot = '.wm-contact:has-text("Jean Dupont") .wm-presence'
		const reload = async () => {
			await page.click('.wm-tab[data-module="mail"]')
			await page.click('.wm-tab[data-module="contacts"]')
			await page.waitForSelector('.wm-contact:has-text("Jean Dupont")')
		}
		// Jean Dupont has a Nextcloud account (here: admin, with his address)
		occ('user:setting admin settings email jean.dupont@client-exemple.fr')
		try {
			await setStatus('dnd')
			await reload()
			await page.waitForSelector(dot + '.wm-presence-dnd:not([hidden])')
			assert.strictEqual(await page.getAttribute(dot, 'title'), 'Ne pas déranger')
			// the others have no Nextcloud account: no dot
			assert.strictEqual(await page.$$eval('.wm-contacts .wm-presence:not([hidden])', (els) => els.length), 1)
			await setStatus('online')
			await reload()
			await page.waitForSelector(dot + '.wm-presence-online:not([hidden])')
			// on his card too
			await page.click('.wm-contact:has-text("Jean Dupont")')
			await page.waitForSelector('.wm-contact-head .wm-presence-online[title="En ligne"]')
			await shot('14b-contacts-presence')
			// « Invisible »: shown as off line, as Nextcloud does
			await setStatus('invisible')
			await reload()
			await page.waitForSelector(dot + '.wm-presence-offline[title="Hors ligne"]')
		} finally {
			await setStatus('online').catch(() => {})
			occ('user:setting admin settings email --delete')
		}
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

	await step('contacts: duplicates found (same address, same name) and merged into one card, nothing lost', async () => {
		await page.click('.wm-tab[data-module="contacts"]')
		await page.waitForSelector('.wm-contact:has-text("Jean Dupont")')
		const made = await page.evaluate(async () => {
			const accounts = await (await fetch(OC.generateUrl('/apps/webmail/api/accounts'), { headers: { requesttoken: OC.requestToken } })).json()
			const id = accounts.find((a) => a.email === 'user@societe-a.fr').id
			const create = (contact) => fetch(OC.generateUrl('/apps/webmail/api/accounts/' + id + '/contacts'), {
				method: 'POST', headers: { requesttoken: OC.requestToken, 'Content-Type': 'application/json' }, body: JSON.stringify({ book: 'contacts', contact }),
			}).then((r) => r.status)
			return [
				await create({ givenName: 'Hugo', surname: 'Lefèvre', emails: ['hugo@lefevre.example'], mobilePhone: '06 11 22 33 44' }),
				await create({ givenName: 'HUGO', surname: 'Lefevre', company: 'Lefèvre Menuiserie', businessPhone: '04 93 44 55 66', emails: [], notes: 'Rappeler le lundi' }),
			]
		})
		assert.deepStrictEqual(made, [200, 200])
		await page.click('.wm-contact >> nth=0')
		await page.click('.wm-dup-btn')
		const group = '.wm-dup-group:has-text("hugo@lefevre.example")'
		await page.waitForSelector(group)
		assert.strictEqual(await page.locator(group + ' .wm-dup-item').count(), 2)
		await shot('53-duplicates')
		// the card with the address is kept by default
		assert.ok(await page.isChecked(group + ' .wm-dup-item:has-text("hugo@lefevre.example") input[type=radio]'))
		await page.click(group + ' .wm-dup-merge')
		await page.waitForSelector('.wm-toast:has-text("2 fiches fusionnées")')
		await page.waitForSelector(group, { state: 'detached' })
		await page.click('.wm-dup-dialog .wm-modal-close')
		await page.waitForSelector('.wm-contact:has-text("Hugo Lefèvre")')
		assert.strictEqual(await page.locator('.wm-contact:has-text("Lef")').count(), 1)
		await page.click('.wm-contact:has-text("Hugo Lefèvre")')
		const card = await (await page.waitForSelector('.wm-contact-card:has-text("Lefèvre Menuiserie")')).textContent()
		for (const v of ['hugo@lefevre.example', '06 11 22 33 44', '04 93 44 55 66', 'Rappeler le lundi']) assert.ok(card.includes(v), v + ' in ' + card)
		page.once('dialog', (d) => d.accept())
		await page.click('.wm-toolbar button:has-text("Supprimer")')
		await page.waitForSelector('.wm-toast:has-text("Contact supprimé")')
	})

	await step('contacts: birthdays, the upcoming ones above the list, set and cleared', async () => {
		// Anne Bernard: in 3 days (saved by Outlook in Paris, the day before at 23:00 UTC: the right day here)
		const soon = new Date()
		soon.setDate(soon.getDate() + 3)
		const age = soon.getFullYear() - 1980
		await page.click('.wm-tab[data-module="contacts"]')
		await page.waitForSelector('.wm-birthdays li:has-text("Anne Bernard"):has-text("dans 3 jours · ' + age + ' ans")')
		await shot('90-birthdays')
		await page.click('.wm-birthdays a:has-text("Anne Bernard")')
		const label = soon.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' }) + ' 1980 (' + (age - 1) + ' ans)'
		await page.waitForSelector('.wm-contact-card:has-text("' + label + '")')
		// set on another contact, then cleared
		await page.click('.wm-contact:has-text("Lucas Petit")')
		await page.click('.wm-toolbar button:has-text("Modifier")')
		await page.fill('input[name=birthday]', '1990-01-15')
		await page.click('.wm-contact-form button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Contact enregistré")')
		await page.waitForSelector('.wm-contact-card:has-text("15 janvier 1990")')
		await page.click('.wm-toolbar button:has-text("Modifier")')
		assert.strictEqual(await page.inputValue('input[name=birthday]'), '1990-01-15')
		await page.fill('input[name=birthday]', '')
		await page.click('.wm-contact-form button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Contact enregistré")')
		await page.waitForSelector('.wm-contact-card:has-text("Lucas Petit")')
		assert.ok(!(await page.textContent('.wm-contact-card')).includes('Anniversaire'))
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
		const bookLink = (name) => '.wm-book-row:not([data-key^="nc:"]):has-text("' + name + '") .wm-book-link'
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

	await step('contacts: copied into Nextcloud Contacts (CardDAV), brought up to date, removed', async () => {
		await page.click('.wm-tab[data-module="contacts"]')
		await page.waitForSelector('.wm-contact:has-text("Jean Dupont")')
		const people = await page.$$eval('.wm-contact', (els) => els.filter((e) => !e.textContent.includes('· groupe')).length)
		await page.click('.wm-nc-copy')
		await page.click('.wm-nc-copy-run:has-text("Copier mes contacts")')
		await page.waitForSelector('.wm-toast:has-text("Nextcloud Contacts à jour : ' + people + ' ajoutés")')
		await page.waitForSelector('.wm-nc-copy-state:has-text("' + people + ' contacts copiés")')
		await shot('52b-nextcloud-contacts-copy')
		// the address book, seen by any CardDAV client (the Contacts app, a phone…)
		const dav = () => page.evaluate(async () => {
			// (the name only when asked for: Nextcloud gives no « allprop »)
			const list = await (await fetch('/remote.php/dav/addressbooks/users/' + OC.getCurrentUser().uid + '/', {
				method: 'PROPFIND',
				headers: { Depth: '1', requesttoken: OC.requestToken, 'Content-Type': 'application/xml' },
				body: '<d:propfind xmlns:d="DAV:"><d:prop><d:displayname/></d:prop></d:propfind>',
			})).text()
			const m = /addressbooks\/users\/[^/]+\/(webmail-exchange-\d+)\//.exec(list)
			if (!m) return null
			const r = await fetch('/remote.php/dav/addressbooks/users/' + OC.getCurrentUser().uid + '/' + m[1] + '/?export', { headers: { requesttoken: OC.requestToken } })
			return { name: list.includes('Exchange — ') , vcf: await r.text() }
		})
		let copy = await dav()
		assert(copy && copy.name, 'address book not found')
		assert.strictEqual((copy.vcf.match(/BEGIN:VCARD/g) || []).length, people, copy.vcf.slice(0, 300))
		assert(copy.vcf.includes('FN:Jean Dupont'), copy.vcf.slice(0, 300))
		assert(/UID:webmail-[0-9a-f]{32}/.test(copy.vcf))
		// again: nothing changed, nothing written
		await page.click('.wm-nc-copy-run:has-text("Mettre à jour maintenant")')
		await page.waitForSelector('.wm-toast:text-is("Nextcloud Contacts à jour")')
		// stopped: the address book goes
		await page.click('.wm-nc-copy-remove')
		await page.waitForSelector('.wm-toast:has-text("Carnet retiré de Nextcloud Contacts")')
		await page.waitForSelector('.wm-nc-copy-run:has-text("Copier mes contacts")')
		copy = await dav()
		assert.strictEqual(copy, null)
		await page.keyboard.press('Escape')
		await page.waitForSelector('.wm-nc-copy-dialog', { state: 'detached' })
	})

	// ------------------------------------------------------------------ shared contacts
	// (the Exchange books: the address books of Nextcloud Contacts are listed after them)
	const book = (name) => '.wm-book-row:not([data-key^="nc:"]):has-text("' + name + '") .wm-book-link'

	await step('contacts: own address books', async () => {
		await page.click('.wm-tab[data-module="contacts"]')
		await page.waitForSelector('.wm-book-row')
		const books = await page.$$eval('.wm-book-row:not([data-key^="nc:"]) .wm-cal-name', (els) => els.map((e) => e.textContent))
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

	await step('contacts: a search in every address book at once, each result in its book', async () => {
		await page.click(book('Contacts'))
		await page.waitForSelector('.wm-contact:has-text("Jean Dupont")')
		await page.fill('.wm-list .wm-search', 'azur')
		await page.press('.wm-list .wm-search', 'Enter')
		await page.waitForSelector('.wm-empty:has-text("Aucun contact trouvé")')
		await page.click('.wm-search-everywhere')
		await page.waitForSelector('.wm-search-info:has-text("» dans tous les carnets")')
		const found = '.wm-contact:has-text("Électricité Azur")'
		await page.waitForSelector(found + ' .wm-contact-book:has-text("Fournisseurs")')
		await shot('31-contacts-everywhere')
		// opened in its own book
		await page.click(found)
		await page.waitForSelector('.wm-contact-card:has-text("Électricité Azur")')
		await page.waitForSelector('.wm-book-row.active:has-text("Fournisseurs")')
		// back to one book
		await page.click('.wm-search-everywhere')
		await page.waitForSelector('.wm-search-everywhere:has-text("chercher dans tous les carnets")')
		await page.click('.wm-search-info a:has-text("effacer")')
		await page.click(book('Contacts'))
		await page.waitForSelector('.wm-contact:has-text("Jean Dupont")')
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
		const badges = await page.$$eval('.wm-book-row:not([data-key^="nc:"]) .wm-cal-mode', (els) => els.map((e) => e.textContent))
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

	await step('tasks: every task list of Exchange (made in Outlook), its tasks, a task added to it', async () => {
		await page.click('.wm-tab[data-module="tasks"]')
		await page.waitForSelector('.wm-task-list[data-list="tasks"].active')
		const lists = await page.$$eval('.wm-task-list .wm-folder-name', (els) => els.map((e) => e.textContent))
		assert.deepStrictEqual(lists, ['Tâches', 'Chantier Nice'])
		await page.click('.wm-task-list:has-text("Chantier Nice")')
		await page.waitForSelector('.wm-task:has-text("Commander les menuiseries")')
		assert.strictEqual(await page.locator('.wm-task:has-text("Renouveler le nom de domaine")').count(), 0, 'only the tasks of this list')
		// a task added here goes to this list
		await page.fill('input[name=quickTask]', 'Valider les plans')
		await page.press('input[name=quickTask]', 'Enter')
		await page.waitForSelector('.wm-toast:has-text("Tâche ajoutée")')
		await page.waitForSelector('.wm-task:has-text("Valider les plans")')
		await shot('59-task-lists')
		// the form: the list can be chosen
		await page.click('.wm-compose-btn:has-text("Nouvelle tâche")')
		assert.strictEqual(await page.inputValue('.wm-task-form select[name=list]'), 'F-TASKS2')
		await page.click('.wm-task-form button:has-text("Annuler")')
		// the list shown is kept for the next time
		await page.click('.wm-tab[data-module="mail"]')
		await page.click('.wm-tab[data-module="tasks"]')
		await page.waitForSelector('.wm-task-list[data-list="F-TASKS2"].active')
		await page.waitForSelector('.wm-task:has-text("Valider les plans")')
		// clean: deleted, back to « Tâches »
		await page.click('.wm-task:has-text("Valider les plans") .wm-task-subject')
		page.once('dialog', (d) => d.accept())
		await page.click('.wm-task-form button:has-text("Supprimer")')
		await page.waitForSelector('.wm-toast:has-text("Tâche supprimée")')
		await page.click('.wm-task-list[data-list="tasks"]')
		await page.waitForSelector('.wm-task:has-text("Renouveler le nom de domaine")')
	})

	await step('tasks: the lists of the Tasks app of Nextcloud here, and the Exchange lists there (CalDAV)', async () => {
		const dav = (method, path, body = null, extra = {}) => page.evaluate(async ({ method, path, body, extra }) => {
			const r = await fetch('/remote.php/dav/' + path.replace('{user}', OC.getCurrentUser().uid), {
				method, body, headers: Object.assign({ requesttoken: OC.requestToken }, extra),
			})
			return { status: r.status, text: (await r.text()).replace(/\r?\n[ \t]/g, '') }
		}, { method, path, body, extra })
		// a list of the Tasks app, with a task
		const uri = 'e2e-courses-' + Date.now()
		const made = await dav('MKCALENDAR', 'calendars/{user}/' + uri + '/', '<c:mkcalendar xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:set><d:prop>'
			+ '<d:displayname>Courses</d:displayname><c:supported-calendar-component-set><c:comp name="VTODO"/></c:supported-calendar-component-set></d:prop></d:set></c:mkcalendar>', { 'Content-Type': 'application/xml' })
		assert.strictEqual(made.status, 201, made.text)
		try {
			const put = await dav('PUT', 'calendars/{user}/' + uri + '/pain.ics', 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//e2e//FR\r\nBEGIN:VTODO\r\nUID:e2e-pain\r\nDTSTAMP:20260101T000000Z\r\n'
				+ 'SUMMARY:Acheter du pain\r\nPRIORITY:1\r\nCATEGORIES:Maison\r\nX-E2E-KEPT:oui\r\nEND:VTODO\r\nEND:VCALENDAR\r\n', { 'Content-Type': 'text/calendar' })
			assert([201, 204].includes(put.status), 'PUT ' + put.status)
			await page.reload()
			await page.click('.wm-tab[data-module="tasks"]')
			await page.click('.wm-nc-task-lists .wm-task-list:has-text("Courses")')
			await page.waitForSelector('.wm-task:has-text("Acheter du pain") .wm-important')
			// added here, in that list
			await page.fill('input[name=quickTask]', 'Acheter du lait')
			await page.press('input[name=quickTask]', 'Enter')
			await page.waitForSelector('.wm-toast:has-text("Tâche ajoutée")')
			await page.waitForSelector('.wm-task:has-text("Acheter du lait")')
			// done here, done there; the rest of the task kept
			await page.check('.wm-task:has-text("Acheter du pain") .wm-task-done')
			await page.waitForSelector('.wm-toast:has-text("Tâche terminée")')
			const pain = await dav('GET', 'calendars/{user}/' + uri + '/pain.ics')
			assert(pain.text.includes('STATUS:COMPLETED') && pain.text.includes('X-E2E-KEPT:oui') && pain.text.includes('CATEGORIES:Maison'), pain.text)
			const all = await dav('REPORT', 'calendars/{user}/' + uri + '/', '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><c:calendar-data/></d:prop>'
				+ '<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VTODO"/></c:comp-filter></c:filter></c:calendar-query>', { Depth: '1', 'Content-Type': 'application/xml' })
			assert(all.text.includes('SUMMARY:Acheter du lait'), all.text.slice(0, 1200))
			await shot('60-nextcloud-tasks')
			// and deleted
			await page.click('.wm-task:has-text("Acheter du lait") .wm-task-subject')
			page.once('dialog', (d) => d.accept())
			await page.click('.wm-task-form button:has-text("Supprimer")')
			await page.waitForSelector('.wm-toast:has-text("Tâche supprimée")')

			// the Exchange lists in the Tasks app of Nextcloud (read only)
			await page.click('.wm-task-list[data-list="tasks"]')
			await page.waitForSelector('.wm-tasks-nc input:not([disabled])')
			await page.check('.wm-tasks-nc input')
			await page.waitForSelector('.wm-toast:has-text("Listes Exchange visibles dans Tâches de Nextcloud")')
			const home = await dav('PROPFIND', 'calendars/{user}/', '<d:propfind xmlns:d="DAV:"><d:prop><d:displayname/></d:prop></d:propfind>', { Depth: '1', 'Content-Type': 'application/xml' })
			const m = /calendars\/[^/]+\/(app-generated--[a-z-]+--webmail-exchange-tasks-\d+)\//.exec(home.text)
			assert(m, 'the list « Tâches » of Exchange in Nextcloud: ' + home.text.slice(0, 800))
			assert(/webmail-exchange-tasks-\d+-[0-9a-f]{8}/.test(home.text), 'the other lists too')
			const warned = uidWarnings()
			const todos = await dav('REPORT', 'calendars/{user}/' + m[1] + '/', '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><c:calendar-data/></d:prop>'
				+ '<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VTODO"/></c:comp-filter></c:filter></c:calendar-query>', { Depth: '1', 'Content-Type': 'application/xml' })
			assert(todos.text.includes('SUMMARY:Renouveler le nom de domaine') && todos.text.includes('PRIORITY:1'), todos.text.slice(0, 1200))
			// (each task read by its UID: no warning of PHP)
			assert.strictEqual(uidWarnings(), warned, 'warnings « Undefined array key UID » in the log')
			await page.uncheck('.wm-tasks-nc input')
			await page.waitForSelector('.wm-toast:has-text("Listes Exchange retirées de Tâches de Nextcloud")')
		} finally {
			await dav('DELETE', 'calendars/{user}/' + uri + '/')
		}
	})

	await step('task reminder: set in the form, rings in the bell of Nextcloud, its link opens the task, gone when removed', async () => {
		const occ = (args) => execSync(OCC + ' ' + args, { encoding: 'utf8' })
		const jobId = (occ("background-job:list --class='OCA\\Webmail\\BackgroundJob\\ReminderJob'").match(/\|\s*(\d+)\s*\|\s*OCA/) || [])[1]
		assert(jobId, 'background job registered')
		const runJob = () => occ('background-job:execute ' + jobId + ' --force-execute')
		const mine = () => page.evaluate(async () => {
			const r = await fetch('/ocs/v2.php/apps/notifications/api/v2/notifications?format=json', { headers: { 'OCS-APIREQUEST': 'true' } })
			// (the account tested: the fake server gives the same tasks to every mailbox)
			return (await r.json()).ocs.data.filter((n) => n.app === 'webmail' && n.subject === 'Tâche : Renouveler le nom de domaine' && n.message.includes('user@societe-a.fr'))
		})
		const task = '.wm-task:has-text("Renouveler le nom de domaine")'
		const open = async () => {
			await page.click('.wm-tab[data-module="tasks"]')
			await page.click(task + ' .wm-task-subject')
			await page.waitForFunction(() => document.querySelector('.wm-task-form input[name=subject]')?.value === 'Renouveler le nom de domaine')
		}
		await open()
		// a minute ago: due at once
		const d = new Date(Date.now() - 60000)
		const pad = (n) => String(n).padStart(2, '0')
		await page.fill('input[name=reminder]', d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()))
		await page.click('.wm-task-form button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Tâche enregistrée")')
		await page.waitForSelector(task + ' .wm-task-reminder')
		runJob()
		let found = await mine()
		assert.strictEqual(found.length, 1, 'one notification')
		assert.ok(found[0].message.includes('user@societe-a.fr'), found[0].message)
		// once only
		runJob()
		assert.strictEqual((await mine()).length, 1)
		// its link: the task open
		await page.goto(found[0].link)
		await page.waitForFunction(() => document.querySelector('.wm-task-form input[name=subject]')?.value === 'Renouveler le nom de domaine')
		assert.ok(await page.inputValue('input[name=reminder]'))
		// removed: gone from the bell
		await page.click('.wm-task-form button:text-is("Aucun")')
		await page.click('.wm-task-form button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Tâche enregistrée")')
		await page.locator(task + ' .wm-task-reminder').waitFor({ state: 'detached' })
		runJob()
		assert.strictEqual((await mine()).length, 0)
		await page.click('.wm-tab[data-module="mail"]')
	})

	await step('no answer: « Me rappeler si pas de réponse », the bell rings for an unanswered message only', async () => {
		const occ = (args) => execSync(OCC + ' ' + args, { encoding: 'utf8' })
		const send = async (to, subject) => {
			await page.click('.wm-compose-btn')
			await page.fill('input[name=to]', to)
			await page.fill('input[name=subject]', subject)
			await page.fill('.wm-editor', 'Merci de me dire ce que vous en pensez.')
			await page.check('input[name=followUp]')
			await page.selectOption('select[name=followUpDays]', '3')
			await submitCompose()
			await page.waitForSelector('.wm-toast:has-text("Nextcloud vous le rappellera")')
		}
		await page.click('.wm-tab[data-module="mail"]')
		await send('paul@societe-a.fr', 'Devis toiture Cannes')
		// to oneself: its copy arrives, from one of the people written to: answered
		await send('user@societe-a.fr', 'Point budget Cannes')
		const accountId = await page.evaluate(async () => (await (await fetch('/apps/webmail/api/accounts', { headers: { requesttoken: OC.requestToken } })).json()).find((a) => a.email === 'user@societe-a.fr').id)
		const key = 'followup_' + accountId
		const list = JSON.parse(occ('user:setting admin webmail ' + key).trim())
		assert.deepStrictEqual(list.map((e) => e.subject), ['Devis toiture Cannes', 'Point budget Cannes'])
		assert.deepStrictEqual(list[0].to, ['paul@societe-a.fr'])
		// three days later
		list.forEach((e) => { e.until = new Date(Date.now() - 60000).toISOString().slice(0, 19) + 'Z' })
		occ('user:setting admin webmail ' + key + " '" + JSON.stringify(list) + "'")
		const jobId = (occ("background-job:list --class='OCA\\Webmail\\BackgroundJob\\FollowUpJob'").match(/\|\s*(\d+)\s*\|\s*OCA/) || [])[1]
		assert(jobId, 'background job registered')
		occ('background-job:execute ' + jobId + ' --force-execute')
		const mine = await page.evaluate(async () => {
			const r = await fetch('/ocs/v2.php/apps/notifications/api/v2/notifications?format=json', { headers: { 'OCS-APIREQUEST': 'true' } })
			return (await r.json()).ocs.data.filter((n) => n.app === 'webmail' && n.subject.startsWith('Pas de réponse')).map((n) => n.subject + ' | ' + n.message)
		})
		assert.strictEqual(mine.length, 1, mine.join(' / '))
		assert.ok(mine[0].startsWith('Pas de réponse : Devis toiture Cannes | Envoyé le ') && mine[0].includes('paul@societe-a.fr'), mine[0])
		// done with: no longer waited for
		assert.throws(() => occ('user:setting admin webmail ' + key + ' 2>/dev/null'))
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

	await step('recent searches: a search made (Enter, or a result opened) is offered again under the field', async () => {
		const offered = () => page.$$eval('#wm-recent-searches option', (els) => els.map((e) => e.value))
		// (the searches of the steps before forgotten)
		await page.evaluate(() => Object.keys(localStorage).filter((k) => k.startsWith('webmail.searches')).forEach((k) => localStorage.removeItem(k)))
		await page.click('.wm-folder >> nth=0')
		await page.fill('.wm-list .wm-search', 'maintenance')
		await page.press('.wm-list .wm-search', 'Enter')
		await page.waitForSelector('.wm-search-info:has-text("maintenance")')
		// a word typed then left without looking at a result: not kept
		await page.fill('.wm-list .wm-search', 'zzz')
		await page.waitForSelector('.wm-search-info:has-text("zzz")')
		await page.fill('.wm-list .wm-search', 'Devis')
		await page.waitForSelector('.wm-search-info:has-text("Devis")')
		await page.click('.wm-message:has-text("Devis pour la maintenance annuelle")')
		await page.click('.wm-search-info a:has-text("effacer")')
		await page.waitForSelector('.wm-list-period')
		assert.deepStrictEqual(await offered(), ['Devis', 'maintenance'])
		assert.strictEqual(await page.getAttribute('.wm-list .wm-search', 'list'), 'wm-recent-searches')
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
		// (the answers of the page loaded now: those of the page before, still loading ahead, do not count)
		await page.goto(NC + '/apps/webmail/?module=mail')
		const ahead = ['/calendar/events', '/tasks?', '/contacts?'].map((part) => page.waitForResponse((r) => r.url().includes(part) && r.status() === 200, { timeout: 30000 }))
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
		// the button of the calendar, not the one of the mail still drawn a moment
		await page.waitForSelector('.wm-compose-btn:has-text("Nouvel événement")')
		// the calendar draws its side again once its data is there: the focus on the new button
		for (let i = 0; i < 5 && !(await page.$('.wm-modal input[name=subject]')); i++) {
			await page.focus('.wm-compose-btn')
			await page.keyboard.press('Enter')
			await page.waitForSelector('.wm-modal input[name=subject]', { timeout: 3000 }).catch(() => {})
		}
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
		await openMore()
		const [download] = await Promise.all([page.waitForEvent('download'), page.click('.wm-more-menu .wm-eml-btn')])
		assert.strictEqual(download.suggestedFilename(), 'Votre facture OVH.eml')
		const eml = fs.readFileSync(await download.path(), 'utf8')
		assert.ok(/^Subject: Votre facture OVH$/m.test(eml) && /^From: OVHcloud <noreply@ovh\.example>$/m.test(eml), eml.slice(0, 300))
		await page.evaluate(() => { OC.dialogs.filepicker = (t, cb) => cb('/Documents') })
		await more('.wm-eml-save-btn')
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
		await more('.wm-event-from-msg')
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
		await more('.wm-event-from-msg')
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
		await more('.wm-task-from-msg')
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

	await step('ignore a conversation: to the deleted items, « Annuler », then the next messages go there directly', async () => {
		const sendToSelf = async (subject) => {
			await page.click('.wm-compose-btn')
			await page.fill('input[name=to]', 'user@societe-a.fr')
			await page.fill('input[name=subject]', subject)
			await page.fill('.wm-editor', 'Où en est le chantier ?')
			await submitCompose()
		}
		const inbox = async () => { await page.click('.wm-folder >> nth=0'); await page.waitForSelector('.wm-messages .wm-message') }
		const row = (subject) => '.wm-message:has(.wm-message-subject:text-is("' + subject + '"))'
		await page.click('.wm-folder >> nth=0')
		await sendToSelf('Point travaux Antibes')
		await inbox()
		await page.click(row('Point travaux Antibes'))
		await page.waitForSelector('.wm-message-header h2:has-text("Point travaux Antibes")')
		await more('.wm-ignore-btn')
		await page.waitForSelector('.wm-toast:has-text("Conversation ignorée")')
		await page.locator(row('Point travaux Antibes')).waitFor({ state: 'detached' })
		// changed one's mind: back in the inbox
		await page.click('.wm-toast:has-text("Conversation ignorée") .wm-toast-action')
		await page.waitForSelector('.wm-toast:has-text("La conversation n\'est plus ignorée")')
		await page.waitForSelector(row('Point travaux Antibes'))
		// ignored for good: the answer goes straight to the deleted items
		await page.click(row('Point travaux Antibes'))
		await more('.wm-ignore-btn')
		await page.locator(row('Point travaux Antibes')).waitFor({ state: 'detached' })
		await sendToSelf('RE: Point travaux Antibes')
		await inbox()
		assert.strictEqual(await page.$(row('RE: Point travaux Antibes')), null)
		await page.click('.wm-folder:has-text("Éléments supprimés")')
		await page.waitForSelector(row('RE: Point travaux Antibes'))
		await page.waitForSelector(row('Point travaux Antibes'))
		// (in the deleted items: nothing to ignore)
		await page.click(row('Point travaux Antibes'))
		await page.waitForSelector('.wm-message-header h2:has-text("Point travaux Antibes")')
		await openMore()
		assert.strictEqual(await page.$('.wm-more-menu .wm-ignore-btn'), null)
		await page.keyboard.press('Escape')
		await inbox()
	})

	await step('forward as attachment: one message as an .eml, several from the selection', async () => {
		const msg = (n) => '.wm-message:has(.wm-message-subject:text-is("Newsletter n°' + n + '"))'
		await page.click('.wm-folder >> nth=0')
		await page.click(msg(25))
		await page.waitForSelector('.wm-message-header h2:has-text("Newsletter n°25")')
		await more('.wm-forward-attach')
		await page.waitForSelector('.wm-compose .wm-compose-attachments li:has-text("Newsletter n°25.eml")')
		assert.strictEqual(await page.inputValue('.wm-compose input[name=subject]'), 'TR : Newsletter n°25')
		await page.fill('input[name=to]', 'paul@societe-a.fr')
		await page.fill('.wm-editor', 'Pour info, la lettre de la semaine.')
		await submitCompose()
		// sent with the message attached
		await page.click('.wm-folder:has-text("Éléments envoyés")')
		await page.click('.wm-message:has-text("TR : Newsletter n°25")')
		await page.waitForSelector('.wm-attachment-name:has-text("Newsletter n°25.eml")')
		// several, from the selection: one new message, each attached
		await page.click('.wm-folder >> nth=0')
		await page.click(msg(26) + ' .wm-check')
		await page.click(msg(27) + ' .wm-check')
		await page.click('.wm-bulk .wm-forward-attach')
		await page.waitForSelector('.wm-compose .wm-compose-attachments li:has-text("Newsletter n°27.eml")')
		await page.waitForSelector('.wm-compose .wm-compose-attachments li:has-text("Newsletter n°26.eml")')
		assert.strictEqual(await page.inputValue('.wm-compose input[name=subject]'), 'TR : 2 messages')
		await shot('89-forward-attachment')
		await page.waitForSelector('.wm-draft-status:has-text("Pièces jointes enregistrées dans le brouillon")')
		page.once('dialog', (d) => d.accept())
		await page.click('.wm-compose button:has-text("Supprimer le brouillon")')
		await page.waitForSelector('.wm-toast:has-text("Brouillon supprimé")')
		await page.click('.wm-folder >> nth=0')
	})

	await step('favorites: a folder pinned at the top of the pane, kept, then removed', async () => {
		const tree = '.wm-folders:not(.wm-favorites) .wm-folder-row:has(.wm-folder-name:text-is("Clients"))'
		await page.click('.wm-folder >> nth=0')
		assert.strictEqual(await page.$('.wm-favorites'), null)
		await page.click(tree + ' .wm-folder-menu-btn')
		await page.click('.wm-folder-menu-item:has-text("Ajouter aux favoris")')
		await page.waitForSelector('.wm-toast:has-text("« Clients » ajouté aux favoris")')
		await page.waitForSelector('.wm-nav-section:text-is("Favoris")')
		// one click away: its messages
		await page.click('.wm-favorites .wm-folder:has-text("Clients")')
		await page.waitForSelector('.wm-message:has-text("Photos du chantier")')
		assert.ok(await page.$eval('.wm-favorites .wm-folder', (a) => a.classList.contains('active')))
		await shot('88-favorites')
		// kept after a reload; the menu offers to remove it
		await page.reload()
		await page.waitForSelector('.wm-favorites .wm-folder:has-text("Clients")')
		await page.click(tree + ' .wm-folder-menu-btn')
		await page.waitForSelector('.wm-folder-menu-item:has-text("Retirer des favoris")')
		await page.keyboard.press('Escape')
		await page.click('.wm-favorites .wm-unfavorite')
		await page.waitForSelector('.wm-toast:has-text("« Clients » retiré des favoris")')
		await page.locator('.wm-favorites').waitFor({ state: 'detached' })
		await page.click('.wm-folder >> nth=0')
	})

	await step('attachments to Files: those of a sender put into a folder of Files by themselves', async () => {
		const occ = (args) => execSync(OCC + ' ' + args, { encoding: 'utf8' })
		const dav = (path, method) => page.evaluate(async ([p, m]) => {
			const r = await fetch('/remote.php/dav/files/admin' + p.split('/').map(encodeURIComponent).join('/'), { method: m, headers: { requesttoken: OC.requestToken } })
			return { status: r.status, text: m === 'GET' ? await r.text() : '' }
		}, [path, method])
		await page.click('.wm-tab[data-module="mail"]')
		await page.click('text=Comptes et signature')
		await page.click('.wm-account-card:has-text("user@societe-a.fr") .wm-autofile-btn')
		await page.waitForSelector('.wm-autofile li:has-text("Aucune règle")')
		await page.fill('input[name=autofileFrom]', '@Societe-A.fr')
		await page.fill('input[name=autofileFolder]', '/Documents/Classement test')
		await page.click('.wm-autofile button[type=submit]')
		await page.waitForSelector('.wm-autofile li[data-from="@societe-a.fr"]:has-text("/Documents/Classement test")')
		await shot('94-attachments-to-files')
		await page.click('.wm-autofile .wm-modal-close')
		// a message arrives (to oneself) with a file
		await page.click('.wm-compose-btn')
		await page.fill('input[name=to]', 'user@societe-a.fr')
		await page.fill('input[name=subject]', 'Facture novembre')
		await page.fill('.wm-editor', 'Ci-joint la facture.')
		await page.setInputFiles('.wm-file-input', { name: 'facture-novembre.txt', mimeType: 'text/plain', buffer: Buffer.from('Facture n°2026-11\nTotal : 1 200 €\n') })
		await submitCompose()
		const jobId = (occ("background-job:list --class='OCA\\Webmail\\BackgroundJob\\AutoFileJob'").match(/\|\s*(\d+)\s*\|\s*OCA/) || [])[1]
		assert(jobId, 'background job registered')
		occ('background-job:execute ' + jobId + ' --force-execute')
		const got = await dav('/Documents/Classement test/facture-novembre.txt', 'GET')
		assert.strictEqual(got.status, 200)
		assert.strictEqual(got.text, 'Facture n°2026-11\nTotal : 1 200 €\n')
		// once only: run again, no second copy
		occ('background-job:execute ' + jobId + ' --force-execute')
		assert.strictEqual((await dav('/Documents/Classement test/facture-novembre (2).txt', 'GET')).status, 404)
		// the rule removed, the test folder too
		await page.click('text=Comptes et signature')
		await page.click('.wm-account-card:has-text("user@societe-a.fr") .wm-autofile-btn')
		await page.click('.wm-autofile li[data-from="@societe-a.fr"] button')
		await page.waitForSelector('.wm-autofile li:has-text("Aucune règle")')
		await page.click('.wm-autofile .wm-modal-close')
		await dav('/Documents/Classement test', 'DELETE')
	})

	await step('open in Nextcloud: an attachment copied in Files and opened with the viewer of Nextcloud', async () => {
		await page.click('.wm-folder >> nth=0')
		await page.click('.wm-message:has-text("Devis pour la maintenance annuelle")')
		const btn = '.wm-attachment:has-text("devis-2027.pdf") .wm-open-nc'
		await page.waitForSelector(btn)
		const [tab] = await Promise.all([page.waitForEvent('popup'), page.click(btn)])
		const copied = await (await page.waitForSelector('.wm-toast:has-text("Copié dans /Pièces jointes/devis-2027")')).textContent()
		const path = copied.match(/\/Pièces jointes\/[^]*?\.pdf/)[0]
		// the copy opened in Files, which shows it with its viewer
		await tab.waitForURL(/\/apps\/files\/.*openfile=true|\/f\/\d+\?openfile=true/, { timeout: 30000 })
		await tab.close()
		const status = await page.evaluate(async (p) => (await fetch('/remote.php/dav/files/admin' + p.split('/').map(encodeURIComponent).join('/'), { method: 'DELETE', headers: { requesttoken: OC.requestToken } })).status, path)
		assert.strictEqual(status, 204, 'the copy was there')
	})

	await step('note: a message kept as a note (Markdown in the Notes folder), opened in Files without the Notes app', async () => {
		await page.click('.wm-folder >> nth=0')
		await page.click('.wm-message:has-text("Devis pour la maintenance annuelle")')
		await page.waitForSelector('.wm-message-header h2:has-text("Devis pour la maintenance annuelle")')
		await more('.wm-note-btn')
		await page.waitForSelector('.wm-toast:has-text("Note créée : /Notes/Devis pour la maintenance annuelle")')
		await page.waitForSelector('.wm-toast .wm-toast-action:text-is("Ouvrir dans Fichiers")')
		const path = (await page.textContent('.wm-toast:has-text("Note créée")')).match(/\/Notes\/[^]*?\.md/)[0]
		// what is in the file
		const md = await page.evaluate(async (p) => {
			const r = await fetch('/remote.php/dav/files/admin' + p.split('/').map(encodeURIComponent).join('/'), { headers: { requesttoken: OC.requestToken } })
			return r.ok ? r.text() : 'HTTP ' + r.status
		}, path)
		assert.ok(md.startsWith('# Devis pour la maintenance annuelle\n\n**De :** Jean Dupont <jean.dupont@client-exemple.fr>'), md)
		assert.ok(md.includes('Veuillez trouver ci-joint notre devis pour la maintenance 2027.'), md)
		assert.ok(!md.includes('alert('), 'no script')
		assert.ok(/\[Ouvrir dans le webmail\]\(http[^)]+\/apps\/webmail\/\?account=\d+&message=/.test(md), md)
		// removed: the test leaves nothing behind
		await page.evaluate(async (p) => {
			await fetch('/remote.php/dav/files/admin' + p.split('/').map(encodeURIComponent).join('/'), { method: 'DELETE', headers: { requesttoken: OC.requestToken } })
		}, path)
	})

	await step('Deck: a message as a card of a board (the list chosen, a due date), from « Plus » or a right-click', async () => {
		const occ = (args) => execSync(OCC + ' ' + args, { encoding: 'utf8' })
		const container = (OCC.match(/docker exec(?:\s+-u\s+\S+)?\s+(\S+)\s+php occ/) || [])[1]
		const subject = 'Devis pour la maintenance annuelle'
		const openIt = async () => {
			await page.click('.wm-folder >> nth=0')
			await page.click('.wm-message:has-text("' + subject + '")')
			await page.waitForSelector('.wm-message-header h2:has-text("' + subject + '")')
		}
		// no Deck in this Nextcloud: not offered
		await openIt()
		await openMore()
		assert.strictEqual(await page.$('.wm-more-menu .wm-deck-btn'), null)
		await page.keyboard.press('Escape')
		// the routes of Deck's API the webmail uses, for this test only
		execSync('docker cp ' + require('path').join(__dirname, 'testdeck') + ' ' + container + ':/var/www/html/custom_apps/deck')
		execSync('docker exec ' + container + ' chown -R www-data:www-data /var/www/html/custom_apps/deck')
		occ('app:enable deck')
		occ('config:app:delete deck test_cards')
		try {
			// (Nextcloud may keep the list of its apps for a while: the page opened again until Deck is there)
			for (let i = 0; ; i++) {
				await page.goto(NC + '/apps/webmail/')
				await page.waitForSelector('.wm-message')
				if (await page.$eval('#webmail-app', (e) => e.dataset.deck === '1')) break
				assert.ok(i < 24, 'Deck seen once enabled')
				await page.waitForTimeout(5000)
			}
			await openIt()
			await more('.wm-deck-btn')
			await page.waitForSelector('.wm-deck-dialog select[name=stack]:not([disabled])')
			// only the boards where a card can be added (not a read-only nor an archived one)
			assert.deepStrictEqual(await page.$$eval('.wm-deck-dialog select[name=board] option', (o) => o.map((x) => x.textContent)), ['Projets'])
			assert.deepStrictEqual(await page.$$eval('.wm-deck-dialog select[name=stack] option', (o) => o.map((x) => x.textContent)), ['À faire', 'En cours'])
			assert.strictEqual(await page.inputValue('.wm-deck-dialog input[name=title]'), subject)
			await page.selectOption('.wm-deck-dialog select[name=stack]', { label: 'En cours' })
			await page.fill('.wm-deck-dialog input[name=due]', '2030-12-02')
			await shot('121-deck')
			await page.click('.wm-deck-dialog button[type=submit]')
			await page.waitForSelector('.wm-toast:has-text("Carte créée dans Projets › En cours")')
			await page.waitForSelector('.wm-deck-dialog', { state: 'detached' })
			const cards = await page.evaluate(async () => (await fetch(OC.generateUrl('/apps/deck/api/v1.0/test/cards'), { headers: { 'OCS-APIRequest': 'true', requesttoken: OC.requestToken } })).json())
			assert.strictEqual(cards.length, 1, JSON.stringify(cards))
			const card = cards[0]
			assert.strictEqual(card.title, subject)
			assert.deepStrictEqual([card.boardId, card.stackId, card.type], [1, 12, 'plain'])
			assert.ok(card.description.startsWith('**De :** Jean Dupont <jean.dupont@client-exemple.fr>'), card.description)
			assert.ok(card.description.includes('Veuillez trouver ci-joint notre devis pour la maintenance 2027.'), card.description)
			assert.ok(/\[Ouvrir dans le webmail\]\(http[^)]+\/apps\/webmail\/\?account=\d+&message=/.test(card.description), card.description)
			assert.ok(card.duedate && new Date(card.duedate).getFullYear() === 2030, card.duedate)
			// « Ouvrir dans Deck »: the card itself
			await page.evaluate(() => { window.__opened = []; window.open = (u) => { window.__opened.push(u); return null } })
			await page.click('.wm-toast .wm-toast-action:text-is("Ouvrir dans Deck")')
			const opened = await page.evaluate(() => window.__opened)
			assert.ok(/\/apps\/deck\/board\/1\/card\/100$/.test(opened[0]), opened.join(' '))
			assert.strictEqual(await page.evaluate(async (u) => (await fetch(u)).text(), opened[0]), 'Carte 100 du tableau 1')
			// a right-click: the same, the list used last chosen again
			await page.click('.wm-message:has-text("' + subject + '")', { button: 'right' })
			await page.click('.wm-ctx-deck')
			await page.waitForSelector('.wm-deck-dialog select[name=stack]:not([disabled])')
			assert.strictEqual(await page.$eval('.wm-deck-dialog select[name=stack]', (s) => s.selectedOptions[0].textContent), 'En cours')
			await page.keyboard.press('Escape')
			await page.waitForSelector('.wm-deck-dialog', { state: 'detached' })
		} finally {
			occ('config:app:delete deck test_cards')
			occ('app:disable deck')
			execSync('docker exec ' + container + ' rm -rf /var/www/html/custom_apps/deck')
			await page.goto(NC + '/apps/webmail/')
			await page.waitForSelector('.wm-message')
		}
	})

	await step('rich links: a webmail link previewed by Nextcloud (Talk, Text, Deck…), and offered by the Smart Picker', async () => {
		await page.click('.wm-folder >> nth=0')
		const id = await page.getAttribute('.wm-message:has-text("Devis pour la maintenance annuelle")', 'data-id')
		const r = await page.evaluate(async (messageId) => {
			const accounts = await (await fetch('/apps/webmail/api/accounts', { headers: { requesttoken: OC.requestToken } })).json()
			const account = accounts.find((a) => a.email === 'user@societe-a.fr').id
			const link = window.location.origin + OC.generateUrl('/apps/webmail/') + '?' + new URLSearchParams({ account, message: messageId })
			const ocs = (path) => fetch('/ocs/v2.php' + path, { headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json', requesttoken: OC.requestToken } }).then((x) => x.json())
			const resolved = await ocs('/references/resolve?' + new URLSearchParams({ reference: link }))
			const providers = await ocs('/references/providers')
			const ref = resolved.ocs.data.references[link]
			return { link, ref: ref && ref.openGraphObject, providers: providers.ocs.data.map((p) => p.id + ' ' + p.title + ' ' + (p.search_providers_ids || []).join(',')) }
		}, id)
		assert.ok(r.ref, JSON.stringify(r))
		assert.strictEqual(r.ref.name, 'Devis pour la maintenance annuelle')
		assert.ok(r.ref.description.includes('Jean Dupont'), r.ref.description)
		assert.strictEqual(r.ref.link, r.link)
		assert.ok(r.providers.some((p) => p.startsWith('webmail-exchange Courrier et agenda Exchange webmail-mail,webmail-events')), r.providers.join(' | '))
	})

	await step('Internet headers: the SPF, DKIM and DMARC checks, the way of the message, every header', async () => {
		await page.click('.wm-message:has-text("Devis pour la maintenance annuelle")')
		await page.waitForSelector('.wm-message-header h2:has-text("Devis pour la maintenance annuelle")')
		await more('.wm-headers-btn')
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
		assert.ok(await page.evaluate(() => document.activeElement && document.activeElement.classList.contains('wm-more-btn')), 'the focus goes back to the button')

		// a forged-looking sender: a warning
		await page.click('.wm-message:has(.wm-message-subject:text-is("Newsletter n°1"))')
		await page.waitForSelector('.wm-message-header h2:has-text("Newsletter n°1")')
		await more('.wm-headers-btn')
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
		await more('.wm-headers-btn')
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

	await step('display: compact list, one line per message, kept, back to comfortable', async () => {
		const setDensity = async (v) => {
			await page.click('text=Comptes et signature')
			await page.selectOption('select[name=density]', v)
			await page.waitForSelector('.wm-toast:has-text("Affichage enregistré")')
			await page.click('.wm-folder >> nth=0')
			await page.waitForSelector('.wm-messages .wm-message')
		}
		const row = '.wm-messages .wm-message:has-text("Devis pour la maintenance annuelle")'
		// (the row on screen now: a refresh of the list may replace it between two calls)
		const look = async () => (await page.waitForFunction(() => {
			const li = [...document.querySelectorAll('.wm-messages .wm-message')].find((e) => e.textContent.includes('Devis pour la maintenance annuelle'))
			const height = li ? li.getBoundingClientRect().height : 0
			if (!height) return null
			return { preview: getComputedStyle(li.querySelector('.wm-message-preview')).display, display: getComputedStyle(li).display, height }
		})).jsonValue()
		await page.click('.wm-folder >> nth=0')
		await page.waitForSelector(row)
		const comfortable = await look()
		assert.notStrictEqual(comfortable.preview, 'none')
		await setDensity('compact')
		const compact = await look()
		assert.strictEqual(compact.preview, 'none')
		assert.ok(compact.height < comfortable.height, JSON.stringify([comfortable, compact]))
		await setDensity('line')
		const line = await look()
		assert.strictEqual(line.display, 'flex')
		assert.ok(line.height < compact.height, JSON.stringify([compact, line]))
		// the date at the end of the line, after the subject
		const [subject, date] = await (await page.waitForFunction(() => {
			const li = [...document.querySelectorAll('.wm-messages .wm-message')].find((e) => e.textContent.includes('Devis pour la maintenance annuelle'))
			return li && li.getBoundingClientRect().height ? [li.querySelector('.wm-message-subject').getBoundingClientRect().right, li.querySelector('.wm-message-date').getBoundingClientRect().left] : null
		})).jsonValue()
		assert.ok(date >= subject, subject + ' / ' + date)
		await shot('87-one-line')
		// kept after a reload
		await page.reload()
		await page.waitForSelector(row)
		assert.ok(await page.$eval('#webmail-app', (e) => e.classList.contains('wm-density-line')))
		await setDensity('comfortable')
		assert.notStrictEqual((await look()).preview, 'none')
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

	await step('remote images by folder: shown at once in the inbox by default, not in the junk mail; the choice in the settings', async () => {
		const row = '.wm-message:has-text("Devis pour la maintenance annuelle")'
		const tracker = () => page.evaluate(() => { const f = document.querySelector('.wm-body-frame'); return !!(f && f.contentDocument && f.contentDocument.querySelector('img[src*="tracker.example"]')) })
		const open = async () => {
			await page.click('.wm-folder >> nth=0')
			await page.click(row)
			await page.waitForSelector('.wm-message-header h2:has-text("Devis pour la maintenance annuelle")')
			await page.waitForSelector('.wm-body-frame')
		}
		// the choice of a user who never set it: the defaults
		execSync(OCC + ' user:setting --delete admin webmail images_folders')
		await page.reload()
		await page.waitForSelector('.wm-message')
		await open()
		await page.waitForFunction(() => { const f = document.querySelector('.wm-body-frame'); return f && f.contentDocument && f.contentDocument.querySelector('img[src*="tracker.example"]') })
		assert.strictEqual(await page.locator('.wm-remote-banner').count(), 0, 'no banner: shown at once')
		// the settings: inbox, sent items, other folders ticked, junk mail not
		await page.click('.wm-nav-footer a:has-text("Comptes et signature")')
		await page.waitForSelector('.wm-image-settings')
		const ticked = await page.$$eval('.wm-image-folders input', (els) => els.map((e) => e.name + '=' + e.checked))
		assert.deepStrictEqual(ticked, ['images_inbox=true', 'images_sentitems=true', 'images_other=true', 'images_junkemail=false'])
		await shot('93-image-folders')
		// the inbox unticked: blocked again, the banner back
		await page.uncheck('.wm-image-folders input[name=images_inbox]')
		await page.waitForSelector('.wm-toast:has-text("Préférence des images enregistrée")')
		assert.ok(execSync(OCC + ' user:setting admin webmail images_folders', { encoding: 'utf8' }).includes('"inbox":false'))
		await open()
		await page.waitForSelector('.wm-remote-banner')
		assert.strictEqual(await tracker(), false, 'blocked')
		// back for the next steps: blocked everywhere
		execSync(OCC + ' user:setting admin webmail images_folders \'{"inbox":false,"sentitems":false,"other":false,"junkemail":false}\'')
		await page.reload()
		await page.waitForSelector('.wm-message')
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

	await step('morning summary: the day in one notification, at the hour chosen (once a day) or at once; it opens « Aujourd\'hui »', async () => {
		const occ = (args) => execSync(OCC + ' ' + args, { encoding: 'utf8' })
		const bell = () => page.evaluate(async () => (await (await fetch('/ocs/v2.php/apps/notifications/api/v2/notifications?format=json', { headers: { 'OCS-APIREQUEST': 'true' } })).json()).ocs.data.filter((n) => n.app === 'webmail' && n.subject.includes('Votre journée')))
		let tz = ''
		try { tz = occ('user:setting admin core timezone').trim() } catch (e) { /* none */ }
		try {
			await page.click('.wm-tab[data-module="mail"]')
			await page.click('text=Comptes et signature')
			const sel = '.wm-notify-settings select[name=digestHour]'
			await page.waitForSelector(sel + ':not([disabled])')
			assert.strictEqual(await page.inputValue(sel), '0', 'off at first')
			await page.selectOption(sel, '8')
			await page.waitForSelector('.wm-toast:has-text("Résumé du matin chaque jour à 8 h")')
			assert.strictEqual(occ('user:setting admin webmail digest_hour').trim(), '8')
			await shot('122-digest-settings')
			// at once: in the bell, a click opens « Aujourd'hui »
			await page.click('.wm-digest-now')
			await page.waitForSelector('.wm-toast:has-text("Résumé envoyé")')
			let told = await bell()
			assert.strictEqual(told.length, 1, JSON.stringify(told))
			assert.strictEqual(told[0].subject, '☀️ Votre journée')
			assert.match(told[0].message, /\d+ non lus?/, told[0].message)
			assert.ok(told[0].link.endsWith('/apps/webmail/?view=today'), told[0].link)
			// again: the new one replaces it
			const again = page.waitForResponse((r) => r.url().includes('/api/notify-settings/digest-now'))
			await page.click('.wm-digest-now')
			assert.strictEqual((await (await again).json()).sent, true)
			assert.strictEqual((await bell()).length, 1)
			await page.goto(told[0].link)
			await page.waitForSelector('.wm-today-link.active')
			// the background job: at its hour (the user's time zone), once a day
			await page.evaluate(async (ids) => {
				for (const id of ids) await fetch('/ocs/v2.php/apps/notifications/api/v2/notifications/' + id, { method: 'DELETE', headers: { 'OCS-APIREQUEST': 'true', requesttoken: OC.requestToken } })
			}, (await bell()).map((n) => n.notification_id))
			let off = (8 - new Date().getUTCHours() + 24) % 24
			if (off > 14) off -= 24
			occ('user:setting admin core timezone ' + (off >= 0 ? 'Etc/GMT-' + off : 'Etc/GMT+' + -off))
			occ('user:setting --delete admin webmail digest_last')
			const jobId = (occ("background-job:list --class='OCA\\Webmail\\BackgroundJob\\DigestJob'").match(/\|\s*(\d+)\s*\|\s*OCA/) || [])[1]
			assert(jobId, 'background job registered')
			occ('background-job:execute ' + jobId + ' --force-execute')
			assert.strictEqual((await bell()).length, 1, 'sent at its hour')
			await page.evaluate(async (ids) => {
				for (const id of ids) await fetch('/ocs/v2.php/apps/notifications/api/v2/notifications/' + id, { method: 'DELETE', headers: { 'OCS-APIREQUEST': 'true', requesttoken: OC.requestToken } })
			}, (await bell()).map((n) => n.notification_id))
			occ('background-job:execute ' + jobId + ' --force-execute')
			assert.strictEqual((await bell()).length, 0, 'once a day')
			// off: none
			await page.click('text=Comptes et signature')
			await page.waitForSelector(sel + ':not([disabled])')
			assert.strictEqual(await page.inputValue(sel), '8')
			await page.selectOption(sel, '0')
			await page.waitForSelector('.wm-toast:has-text("Plus de résumé du matin")')
			occ('user:setting --delete admin webmail digest_last')
			occ('background-job:execute ' + jobId + ' --force-execute')
			assert.strictEqual((await bell()).length, 0, 'off')
		} finally {
			if (tz) occ('user:setting admin core timezone ' + tz)
			else occ('user:setting --delete admin core timezone')
			for (const k of ['digest_hour', 'digest_last']) occ('user:setting --delete admin webmail ' + k)
			await page.goto(NC + '/apps/webmail/')
			await page.waitForSelector('.wm-message')
		}
	})

	await step('export a folder: its messages as .eml files in a .zip, downloaded or put into Files', async () => {
		const row = (name) => '.wm-folder-row:has(.wm-folder-name:text-is("' + name + '"))'
		const subjects = await (async () => {
			await page.click(row('Clients') + ' .wm-folder')
			await page.waitForSelector('.wm-message')
			const total = await page.evaluate(() => document.querySelectorAll('.wm-messages .wm-message').length)
			return total
		})()
		const openExport = async () => {
			await page.hover(row('Clients'))
			await page.click(row('Clients') + ' .wm-folder-menu-btn')
			await page.click('.wm-folder-menu button:has-text("Exporter (.zip)")')
			await page.waitForSelector('.wm-export-dialog')
		}
		await openExport()
		assert.match(await page.textContent('.wm-export-dialog'), /\d+ messages?/)
		await shot('124-export-folder')
		const [download] = await Promise.all([page.waitForEvent('download'), page.click('.wm-export-download')])
		assert.match(download.suggestedFilename(), /^Clients \(\d{4}-\d{2}-\d{2}\)\.zip$/)
		await page.waitForSelector('.wm-toast:has-text("exporté")')
		const list = execSync('unzip -Z1 ' + JSON.stringify(await download.path()), { encoding: 'utf8' }).trim().split('\n')
		assert.ok(list.length >= subjects && list.every((n) => /^\d{4}-\d{2}-\d{2} \d{4} .+\.eml$/.test(n)), list.join(' | '))
		// each one a message, named after its subject
		const first = list[0]
		const eml = execSync('unzip -p ' + JSON.stringify(await download.path()) + ' ' + JSON.stringify(first.replace(/[[\]*?]/g, '\\$&')), { encoding: 'utf8' })
		assert.ok(/^Subject: /m.test(eml) && /^From: /m.test(eml), eml.slice(0, 300))
		// into Files
		await openExport()
		await page.evaluate(() => { OC.dialogs.filepicker = (t, cb) => cb('/Exports webmail') })
		await page.click('.wm-export-files')
		await page.waitForSelector('.wm-toast:has-text("messages enregistrés dans /Exports webmail/Clients (")')
		const path = (await page.textContent('.wm-toast:has-text("messages enregistrés dans")')).match(/\/Exports webmail\/[^]*?\.zip/)[0]
		const size = await page.evaluate(async (p) => {
			const r = await fetch('/remote.php/dav/files/admin' + p.split('/').map(encodeURIComponent).join('/'), { headers: { requesttoken: OC.requestToken } })
			return r.ok ? (await r.arrayBuffer()).byteLength : -r.status
		}, path)
		assert.ok(size > 100, 'the .zip in Files: ' + size)
		await page.evaluate(async () => {
			await fetch('/remote.php/dav/files/admin/' + encodeURIComponent('Exports webmail'), { method: 'DELETE', headers: { requesttoken: OC.requestToken } })
		})
	})

	await step('display: « Supprimer » in red with a red trash can; the dark theme chosen in the settings, kept, then Nextcloud\'s again', async () => {
		const occ = (args) => execSync(OCC + ' ' + args, { encoding: 'utf8' })
		await page.click('.wm-tab[data-module="mail"]')
		await page.click('.wm-folder >> nth=0')
		await page.click('.wm-messages .wm-message >> nth=0')
		await page.waitForSelector('.wm-message-toolbar .wm-delete-btn')
		// red, and its trash can drawn in the same red (not a grey emoji)
		const del = await page.$eval('.wm-message-toolbar .wm-delete-btn', (b) => {
			const st = getComputedStyle(b)
			const icon = getComputedStyle(b, '::before')
			return { color: st.color, icon: icon.backgroundColor, mask: icon.webkitMaskImage || icon.maskImage, text: b.textContent }
		})
		const [r, g, bl] = del.color.match(/\d+/g).map(Number)
		assert.ok(r > 150 && g < 100 && bl < 100, 'red: ' + del.color)
		assert.strictEqual(del.icon, del.color)
		assert.ok(/svg/.test(del.mask), del.mask)
		assert.ok(!del.text.includes('🗑'), del.text)
		// the dark theme
		await page.click('text=Comptes et signature')
		await page.waitForSelector('.wm-display-settings select[name=theme]')
		assert.strictEqual(await page.inputValue('.wm-display-settings select[name=theme]'), '')
		const before = await page.evaluate(() => [...document.body.attributes].filter((a) => a.name.startsWith('data-theme')).map((a) => a.name + '=' + a.value).sort())
		await page.selectOption('.wm-display-settings select[name=theme]', 'dark')
		await page.waitForSelector('.wm-toast:has-text("Mode sombre activé")')
		const bg = () => page.evaluate(() => getComputedStyle(document.body).getPropertyValue('--color-main-background').trim())
		assert.strictEqual(await bg(), '#171717')
		assert.strictEqual(occ('user:setting admin webmail theme').trim(), 'dark')
		// kept: dark from the start
		await page.reload()
		await page.waitForSelector('.wm-message')
		assert.ok(await page.evaluate(() => document.body.hasAttribute('data-theme-dark')))
		assert.strictEqual(await bg(), '#171717')
		await page.click('.wm-messages .wm-message >> nth=0')
		await page.waitForSelector('.wm-body-frame')
		await shot('125-dark-theme')
		// light, then as Nextcloud again: its own attributes back
		await page.click('text=Comptes et signature')
		await page.selectOption('.wm-display-settings select[name=theme]', 'light')
		await page.waitForSelector('.wm-toast:has-text("Mode clair activé")')
		assert.ok(await page.evaluate(() => document.body.hasAttribute('data-theme-light') && !document.body.hasAttribute('data-theme-dark')))
		await page.selectOption('.wm-display-settings select[name=theme]', '')
		await page.waitForSelector('.wm-toast:has-text("Thème de Nextcloud")')
		assert.deepStrictEqual(await page.evaluate(() => [...document.body.attributes].filter((a) => a.name.startsWith('data-theme')).map((a) => a.name + '=' + a.value).sort()), before)
		assert.throws(() => occ('user:setting admin webmail theme'), 'nothing kept for « Comme Nextcloud »')
		await page.click('.wm-folder >> nth=0')
	})

	await step('newsletters: the lists of the inbox by sender; one left and its messages deleted, another kept', async () => {
		const fake = (path, body) => new Promise((resolve, reject) => {
			const data = JSON.stringify(body)
			const req = require('https').request({ host: 'localhost', port: 8443, path, method: 'POST', rejectUnauthorized: false, headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(data) } }, (res) => {
				let out = ''
				res.on('data', (c) => { out += c })
				res.on('end', () => resolve(JSON.parse(out || '{}')))
			})
			req.on('error', reject)
			req.end(data)
		})
		const promo = 'offres@boutique-test.example'
		for (const n of [1, 2, 3]) {
			await fake('/_test/deliver', { subject: 'Promo boutique ' + n, sender: ['Boutique Test', promo], body: '<p>Promo ' + n + '.</p>', minutesAgo: n, listUnsubscribe: '<mailto:stop@boutique-test.example?subject=STOP%20boutique>' })
		}
		await page.click('.wm-folder >> nth=0')
		await page.waitForSelector('.wm-message:has-text("Promo boutique 1")')
		const open = async () => {
			await page.click('.wm-news-link')
			await page.waitForSelector('.wm-news-dialog .wm-news-row[data-email="' + promo + '"]')
		}
		await open()
		const row = '.wm-news-row[data-email="' + promo + '"]'
		const news = '.wm-news-row[data-email="news@exemple.org"]'
		assert.match(await page.textContent(row), /Boutique Test.*3 messages/)
		// never opened: said so
		await page.waitForSelector(row + ' .wm-news-unread:text-is("ouverts : 0 %")')
		// the list sending the most first, all read
		assert.strictEqual(await page.$eval('.wm-news-row', (e) => e.dataset.email), 'news@exemple.org')
		assert.match(await page.textContent(news), /\d+ messages.*ouverts : 100 %/)
		assert.strictEqual(await page.$(news + ' .wm-news-unread'), null)
		await shot('120-newsletters')
		// « Garder »: no longer shown, even after reopening; then shown again
		await page.click(news + ' .wm-news-keep')
		await page.waitForSelector(news, { state: 'detached' })
		await page.click('.wm-news-dialog .wm-modal-close')
		await open()
		assert.strictEqual(await page.$(news), null)
		await page.click('.wm-news-kept summary:text-is("1 liste gardée")')
		await page.click('.wm-news-kept li:has-text("news@exemple.org") .wm-news-unkeep')
		await page.waitForSelector(news)
		assert.strictEqual(await page.$('.wm-news-kept'), null)
		// « Se désabonner et tout supprimer »: asked first; nothing done when declined
		let question = ''
		page.once('dialog', (d) => { question = d.message(); d.dismiss() })
		await page.click(row + ' .wm-news-clean')
		await page.waitForFunction((s) => !document.querySelector(s).disabled, row + ' .wm-news-clean')
		assert.ok(question.includes('Se désabonner de Boutique Test et supprimer ses 3 messages'), question)
		page.once('dialog', (d) => d.accept())
		await page.click(row + ' .wm-news-clean')
		await page.waitForSelector('.wm-toast:has-text("Boutique Test : désabonnement demandé, 3 messages supprimés")')
		await page.waitForSelector(row, { state: 'detached' })
		await page.click('.wm-news-dialog .wm-modal-close')
		// gone from the inbox; the message asking to leave sent from the mailbox
		await page.waitForSelector('.wm-message:has-text("Promo boutique 1")', { state: 'detached' })
		await page.click('.wm-folder:has-text("Éléments envoyés")')
		await page.waitForSelector('.wm-message:has-text("STOP boutique")')
		// also from « Libérer de l'espace »
		await page.click('.wm-quota')
		await page.click('.wm-space-news')
		await page.waitForSelector('.wm-news-dialog ' + news)
		await page.click('.wm-news-dialog .wm-modal-close')
	})

	await step('sort: « Trier par » subject, size, then back to the date (kept after a reload)', async () => {
		const subjects = () => page.$$eval('.wm-messages .wm-message-subject', (els) => els.map((e) => e.textContent))
		await page.click('.wm-folder >> nth=0')
		await page.waitForSelector('.wm-list-period')
		await page.selectOption('.wm-sort', 'subject-asc')
		await page.waitForFunction(() => !document.querySelector('.wm-list-period') && document.querySelector('.wm-messages .wm-message'))
		await page.waitForFunction(() => {
			const s = [...document.querySelectorAll('.wm-messages .wm-message-subject')].map((e) => e.textContent.toLowerCase())
			return s.length > 3 && s.every((x, i) => i === 0 || s[i - 1] <= x)
		})
		const sorted = await subjects()
		assert.ok(sorted[0].toLowerCase() <= sorted[1].toLowerCase(), sorted.slice(0, 5).join(' | '))
		// kept: a reload shows the same order
		await page.reload()
		await page.waitForSelector('.wm-messages .wm-message')
		assert.strictEqual(await page.$eval('.wm-sort', (e) => e.value), 'subject-asc')
		await page.waitForFunction((first) => document.querySelector('.wm-messages .wm-message-subject').textContent === first, sorted[0])
		// the largest first, its size where the date was
		await page.selectOption('.wm-sort', 'size-desc')
		await page.waitForSelector('.wm-messages .wm-message:first-child .wm-message-subject:text-is("Devis pour la maintenance annuelle")')
		const size = await page.textContent('.wm-messages .wm-message:first-child .wm-message-date')
		assert.ok(/\d.*(o|Ko|Mo)$/.test(size.trim()), size)
		await shot('86-sort')
		// back to the date: the headings again
		await page.selectOption('.wm-sort', 'date-desc')
		await page.waitForSelector('.wm-list-period')
		assert.ok(!(await page.$eval('.wm-sort', (e) => e.classList.contains('active'))))
	})

	await step('unified inbox: the inboxes of both accounts in one list, a message opened in its account', async () => {
		await page.click('.wm-tab[data-module="mail"]')
		await page.click('.wm-unified-link')
		const other = '.wm-unified-list .wm-message:has(.wm-message-account:text-is("societe-b.fr"))'
		await page.waitForSelector(other)
		const accounts = await page.$$eval('.wm-unified-list .wm-message', (els) => [...new Set(els.map((e) => e.dataset.account))])
		assert.strictEqual(accounts.length, 2, accounts.join(','))
		// one list, newest first, whatever the account
		const times = await page.$$eval('.wm-unified-list .wm-message', (els) => els.length)
		assert.ok(times > 5)
		await shot('91-unified-inbox')
		const subject = await page.textContent(other + ' >> nth=0 >> .wm-message-subject')
		await page.click(other + ' >> nth=0')
		await page.waitForSelector('.wm-message-header h2:has-text("' + subject.replace(/"/g, '\\"') + '")')
		assert.strictEqual(await page.inputValue('.wm-account-select'), await page.$eval('.wm-account-select option:text-is("compta@societe-b.fr")', (o) => o.value))
		assert.ok(!(await page.$('.wm-unified-link.active')))
		await page.selectOption('.wm-account-select', { label: 'user@societe-a.fr' })
		await page.waitForSelector('.wm-message')
	})

	await step('invitation: « Proposer un nouvel horaire » (provisional), then accepted again', async () => {
		await page.click('.wm-tab[data-module="calendar"]')
		await page.click('.wm-segmented button:has-text("Liste")')
		await page.click('.wm-agenda-item:has-text("Comité de pilotage")')
		await page.click('.wm-modal .wm-propose-btn')
		await page.waitForSelector('.wm-modal .wm-propose')
		const later = new Date(Date.now() + 20 * 86400000)
		const day = later.getFullYear() + '-' + String(later.getMonth() + 1).padStart(2, '0') + '-' + String(later.getDate()).padStart(2, '0')
		await page.fill('.wm-modal input[name=proposeDate]', day)
		await page.fill('.wm-modal input[name=proposeStart]', '14:00')
		await page.fill('.wm-modal input[name=proposeEnd]', '15:30')
		await page.fill('.wm-modal textarea[name=proposeMessage]', 'Plutôt l\'après-midi ?')
		await shot('18b-propose-new-time')
		// the end before the start: refused
		await page.fill('.wm-modal input[name=proposeEnd]', '13:00')
		await page.click('.wm-modal .wm-propose button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("La fin de l\'événement doit être après son début")')
		await page.fill('.wm-modal input[name=proposeEnd]', '15:30')
		await page.click('.wm-modal .wm-propose button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Nouvel horaire proposé à l\'organisateur")')
		await page.waitForSelector('.wm-modal', { state: 'detached' })
		await page.click('.wm-agenda-item:has-text("Comité de pilotage")')
		await page.waitForSelector('.wm-modal:has-text("Votre réponse : provisoire")')
		await page.click('.wm-modal button:has-text("Accepter")')
		await page.waitForSelector('.wm-toast:has-text("Réponse envoyée : accepté")')
		await page.click('.wm-tab[data-module="mail"]')
	})

	await step('.ics received: « Ajouter à mon agenda » puts its event in the Exchange calendar', async () => {
		await page.click('.wm-tab[data-module="mail"]')
		await page.click('.wm-folder >> nth=0')
		const day = new Date(Date.now() + 9 * 86400000)
		const ymd = (d) => d.getUTCFullYear() + String(d.getUTCMonth() + 1).padStart(2, '0') + String(d.getUTCDate()).padStart(2, '0')
		const ics = 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//SNCF//Billet//FR\r\nBEGIN:VEVENT\r\nUID:billet-4242\r\n'
			+ 'DTSTART:' + ymd(day) + 'T070000Z\r\nDTEND:' + ymd(day) + 'T100000Z\r\nSUMMARY:Train Nice → Paris\r\nLOCATION:Gare de Nice-Ville\, voie B\r\n'
			+ 'DESCRIPTION:Voiture 12\\, place 45\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n'
		await page.click('.wm-compose-btn')
		await page.fill('input[name=to]', 'user@societe-a.fr')
		await page.fill('input[name=subject]', 'Votre billet de train')
		await page.fill('.wm-editor', 'Bon voyage !')
		await page.setInputFiles('.wm-file-input', { name: 'billet.ics', mimeType: 'text/calendar', buffer: Buffer.from(ics) })
		await submitCompose()
		// (sent to oneself: in the inbox at the next refresh)
		await page.click('.wm-list-header button[title="Actualiser"]')
		await page.click('.wm-message:has-text("Votre billet de train")')
		await page.click('.wm-attachment:has-text("billet.ics") .wm-ics-add')
		await page.waitForSelector('.wm-toast:has-text("« Train Nice → Paris » ajouté à votre agenda")')
		await page.waitForSelector('.wm-attachment:has-text("billet.ics") .wm-ics-add:has-text("Ajouté à l\'agenda")')
		await shot('90-ics-added')
		await page.click('.wm-toast:has-text("Train Nice") button:has-text("Voir")')
		await page.waitForSelector('.wm-modal h2:has-text("Train Nice → Paris")')
		// (the details come a moment later)
		await page.waitForSelector('.wm-modal:has-text("Gare de Nice-Ville, voie B")')
		await page.waitForSelector('.wm-modal:has-text("Voiture 12, place 45")')
		await page.click('.wm-modal-close')
		await page.click('.wm-tab[data-module="mail"]')
	})

	await step('free space: the heaviest folders from the gauge, their largest messages, the junk mail emptied', async () => {
		await page.click('.wm-folder >> nth=0')
		await page.waitForSelector('.wm-list-period')
		// one message in « Courrier indésirable », to empty it
		await page.fill('.wm-list .wm-search', 'Newsletter n°52')
		await page.press('.wm-list .wm-search', 'Enter')
		await page.click('.wm-message:has(.wm-message-subject:text-is("Newsletter n°52"))')
		await page.click('.wm-toolbar .wm-junk-btn')
		await page.waitForSelector('.wm-toast:has-text("Courrier indésirable")')
		await page.click('.wm-search-info a:has-text("effacer")')
		await page.click('.wm-quota')
		await page.waitForSelector('.wm-space-dialog')
		const names = await page.$$eval('.wm-space-name', (els) => els.map((e) => e.textContent))
		assert.strictEqual(names[0], 'Boîte de réception', names.join('|'))
		assert.ok((await page.textContent('.wm-space-dialog p >> nth=0')).includes('utilisés sur 2 Go'))
		await shot('89-free-space')
		page.once('dialog', (d) => d.accept())
		await page.click('.wm-space-row[data-role="junkemail"] .wm-space-empty')
		await page.waitForSelector('.wm-toast:has-text("Courrier indésirable vidé")')
		await page.waitForSelector('.wm-space-row[data-role="junkemail"] .wm-space-empty', { state: 'detached' })
		// the largest messages of the inbox: the list sorted by size
		await page.click('.wm-space-row:has(.wm-space-name:text-is("Boîte de réception")) .wm-space-largest')
		await page.waitForSelector('.wm-space-dialog', { state: 'detached' })
		await page.waitForFunction(() => document.querySelector('.wm-sort') && document.querySelector('.wm-sort').value === 'size-desc')
		await page.selectOption('.wm-sort', 'date-desc')
		await page.waitForSelector('.wm-list-period')
	})

	await step('right-click on « Éléments supprimés »: « Vider les éléments supprimés »', async () => {
		// one message in « Éléments supprimés »
		await page.click('.wm-folder >> nth=0')
		await page.fill('.wm-list .wm-search', 'Newsletter n°53')
		await page.press('.wm-list .wm-search', 'Enter')
		await page.click('.wm-message:has(.wm-message-subject:text-is("Newsletter n°53"))')
		await page.waitForSelector('.wm-message-header h2:has-text("Newsletter n°53")')
		await page.click('.wm-toolbar button:has-text("Supprimer")')
		await page.waitForSelector('.wm-toast:has-text("éléments supprimés")')
		await page.click('.wm-search-info a:has-text("effacer")')
		const trash = '.wm-folder:has(.wm-folder-name:text-is("Éléments supprimés"))'
		// folders deleted earlier are in it too: « Vieux projets » (with « 2019 » inside) and « Brouillons perso »
		await page.evaluate(async () => {
			const headers = { requesttoken: OC.requestToken, 'Content-Type': 'application/json' }
			const accounts = await (await fetch('/apps/webmail/api/accounts', { headers })).json()
			const base = '/apps/webmail/api/accounts/' + accounts.find((a) => a.email === 'user@societe-a.fr').id + '/folders'
			const post = async (path, body) => (await fetch(base + path, { method: 'POST', headers, body: JSON.stringify(body) })).json()
			const old = await post('', { name: 'Vieux projets', parentId: '' })
			await post('', { name: '2019', parentId: old.id })
			const perso = await post('', { name: 'Brouillons perso', parentId: '' })
			await post('/delete', { folderId: old.id })
			await post('/delete', { folderId: perso.id })
		})
		await page.click('.wm-list button[title="Actualiser"]')
		await page.waitForSelector('.wm-folder-name:text-is("Vieux projets")')
		// a folder already in « Éléments supprimés »: deleted for good
		await page.click('.wm-folder:has(.wm-folder-name:text-is("Brouillons perso"))', { button: 'right' })
		page.once('dialog', (d) => { assert.ok(d.message().includes('Supprimer définitivement le dossier « Brouillons perso »'), d.message()); d.accept() })
		await page.click('.wm-folder-menu-item:has-text("Supprimer")')
		await page.waitForSelector('.wm-toast:has-text("supprimé définitivement")')
		await page.waitForFunction(() => ![...document.querySelectorAll('.wm-folder-name')].some((e) => e.textContent === 'Brouillons perso'))
		// the menu of the folder, from a right-click (no such entry for another folder)
		await page.click('.wm-folder:has(.wm-folder-name:text-is("Clients"))', { button: 'right' })
		await page.waitForSelector('.wm-folder-menu')
		assert.strictEqual(await page.locator('.wm-folder-menu-item:has-text("Vider")').count(), 0)
		await page.keyboard.press('Escape')
		await page.click(trash, { button: 'right' })
		await page.waitForSelector('.wm-folder-menu .wm-folder-menu-item:has-text("Vider les éléments supprimés")')
		await shot('90-empty-deleted-items')
		page.once('dialog', (d) => { assert.ok(d.message().includes('Supprimer définitivement') && /et les \d+ dossiers qu'ils contiennent/.test(d.message()), d.message()); d.accept() })
		await page.click('.wm-folder-menu-item:has-text("Vider les éléments supprimés")')
		await page.waitForSelector('.wm-toast:has-text("Éléments supprimés vidés")')
		// its folders gone with it (also the one deleted in an earlier step)
		await page.waitForFunction(() => ![...document.querySelectorAll('.wm-folder-name')].some((e) => e.textContent === 'Vieux projets' || e.textContent === '2019'))
		const leftInTrash = await page.evaluate(async () => {
			const headers = { requesttoken: OC.requestToken }
			const accounts = await (await fetch('/apps/webmail/api/accounts', { headers })).json()
			const id = accounts.find((a) => a.email === 'user@societe-a.fr').id
			const folders = (await (await fetch('/apps/webmail/api/accounts/' + id + '/folders', { headers })).json()).folders
			const trash = folders.find((f) => f.role === 'deleteditems')
			return folders.filter((f) => f.parentId === trash.id).map((f) => f.name)
		})
		assert.deepStrictEqual(leftInTrash, [])
		await page.click(trash)
		await page.waitForSelector('.wm-empty:has-text("Aucun message")')
		// nothing left: no such entry any more
		await page.click(trash, { button: 'right' })
		await page.waitForSelector('.wm-folder-menu')
		assert.strictEqual(await page.locator('.wm-folder-menu-item:has-text("Vider")').count(), 0)
		await page.keyboard.press('Escape')
		await page.click('.wm-folder >> nth=0')
	})

	await step('separators: the columns widened by dragging, kept after a reload, reset by a double-click', async () => {
		await page.click('.wm-tab[data-module="mail"]')
		await page.waitForSelector('.wm-message')
		const width = (sel) => page.$eval(sel, (el) => Math.round(el.getBoundingClientRect().width))
		const drag = async (pane, dx) => {
			const box = await page.locator('.wm-splitter[data-pane="' + pane + '"]').boundingBox()
			const x = box.x + box.width / 2
			const y = box.y + 200
			await page.mouse.move(x, y)
			await page.mouse.down()
			await page.mouse.move(x + dx / 2, y, { steps: 3 })
			await page.mouse.move(x + dx, y, { steps: 3 })
			await page.mouse.up()
		}
		const nav0 = await width('.wm-nav')
		const list0 = await width('.wm-list')
		await drag('nav', 100)
		assert.ok(Math.abs(await width('.wm-nav') - (nav0 + 100)) <= 2, 'folders wider: ' + await width('.wm-nav'))
		await drag('list', -80)
		assert.ok(Math.abs(await width('.wm-list') - (list0 - 80)) <= 2, 'list narrower: ' + await width('.wm-list'))
		// the separator follows the edge of its column
		const edge = await page.$eval('.wm-list', (el) => Math.round(el.getBoundingClientRect().right))
		const sep = await page.locator('.wm-splitter[data-pane="list"]').boundingBox()
		assert.ok(Math.abs(sep.x + sep.width / 2 - edge) <= 2)
		await shot('91-separators')
		// kept in this browser
		await page.reload()
		await page.waitForSelector('.wm-message')
		assert.ok(Math.abs(await width('.wm-nav') - (nav0 + 100)) <= 2)
		assert.ok(Math.abs(await width('.wm-list') - (list0 - 80)) <= 2)
		// the keyboard: 16 px a press
		await page.focus('.wm-splitter[data-pane="nav"]')
		await page.keyboard.press('ArrowLeft')
		assert.ok(Math.abs(await width('.wm-nav') - (nav0 + 84)) <= 2)
		// never so wide that the message can no longer be read
		await drag('list', 3000)
		const detail = await width('.wm-detail')
		assert.ok(detail >= 318, 'reading pane kept: ' + detail)
		// the calendar has no list: no separator for it
		await page.click('.wm-tab[data-module="calendar"]')
		await page.waitForSelector('.wm-splitter[data-pane="list"][hidden]', { state: 'attached' })
		assert.ok(await page.isVisible('.wm-splitter[data-pane="nav"]'))
		await page.click('.wm-tab[data-module="mail"]')
		await page.waitForSelector('.wm-splitter[data-pane="list"]:not([hidden])')
		// a double-click: the widths of the page again
		await page.dblclick('.wm-splitter[data-pane="nav"]')
		await page.dblclick('.wm-splitter[data-pane="list"]')
		assert.strictEqual(await width('.wm-nav'), nav0)
		assert.strictEqual(await width('.wm-list'), list0)
		assert.strictEqual(await page.evaluate(() => localStorage.getItem('webmail.panes')), '{}')
	})

	await step('headings of the list: « Aujourd\'hui » folded by a click, its count shown, kept after a reload', async () => {
		await page.click('.wm-folder >> nth=0')
		await page.waitForSelector('.wm-list-period')
		const first = page.locator('.wm-messages > .wm-list-period >> nth=0')
		const label = (await first.textContent()).trim()
		// the rows under the first heading: those before the second one
		const under = () => page.evaluate(() => {
			const out = []
			let el = document.querySelector('.wm-messages > .wm-list-period').nextElementSibling
			while (el && !el.classList.contains('wm-list-period')) { out.push(el.hidden); el = el.nextElementSibling }
			return out
		})
		const before = await under()
		assert.ok(before.length > 0 && before.every((h) => !h))
		await first.locator('.wm-period-toggle').click()
		await page.waitForSelector('.wm-list-period.wm-collapsed .wm-period-toggle[aria-expanded="false"]')
		assert.ok((await under()).every((h) => h), 'folded')
		assert.ok((await page.textContent('.wm-list-period.wm-collapsed')).includes(before.length > 1 ? before.length + ' messages' : '1 message'))
		await shot('92-folded-heading')
		// kept in this browser
		await page.reload()
		await page.waitForSelector('.wm-list-period.wm-collapsed')
		assert.ok((await under()).every((h) => h))
		// unfolded again: as before
		await page.click('.wm-list-period.wm-collapsed .wm-period-toggle')
		await page.waitForSelector('.wm-list-period.wm-collapsed', { state: 'detached' })
		assert.ok((await under()).every((h) => !h))
		assert.strictEqual((await first.textContent()).trim(), label)
		assert.strictEqual(await page.evaluate(() => localStorage.getItem('webmail.collapsedPeriods')), '[]')
	})

	await step('one sender: « Tous ses messages… » shows them, moves them all, and back', async () => {
		// (one of the newsletters, found by a search: far down the inbox by now)
		const openNewsletter = async () => {
			await page.fill('.wm-list .wm-search', 'Newsletter n°36')
			await page.press('.wm-list .wm-search', 'Enter')
			await page.click('.wm-message:has(.wm-message-subject:text-is("Newsletter n°36"))')
			await page.waitForSelector('.wm-message-header h2:has-text("Newsletter n°36")')
		}
		await page.click('.wm-folder >> nth=0')
		await page.waitForSelector('.wm-list-period')
		await openNewsletter()
		await more('.wm-sender-btn')
		const text = await (await page.waitForSelector('.wm-sender-dialog p:has-text("de cet expéditeur dans « Boîte de réception »")')).textContent()
		const count = parseInt(text, 10)
		assert.ok(count > 20, text)
		assert.ok((await page.textContent('.wm-sender-dialog h2')).includes('news@exemple.org'))
		await shot('88-sender-messages')
		// shown: a search on the address, his messages only
		await page.click('.wm-sender-show')
		await page.waitForSelector('.wm-search-info:has-text("from:\\"news@exemple.org\\"")')
		await page.waitForSelector('.wm-message .wm-message-from')
		const froms = await page.$$eval('.wm-message-from', (els) => [...new Set(els.map((e) => e.textContent))])
		assert.deepStrictEqual(froms, ['Lettre d\'info'])
		await page.click('.wm-search-info a:has-text("effacer")')
		await page.waitForSelector('.wm-list-period')
		// all moved to « Clients », then all back
		await openNewsletter()
		await more('.wm-sender-btn')
		await page.waitForSelector('.wm-sender-dialog .wm-sender-move select')
		await page.selectOption('.wm-sender-dialog .wm-sender-move select', { label: 'Clients' })
		await page.click('.wm-sender-move-btn')
		await page.waitForSelector('.wm-toast:has-text("' + count + ' messages déplacés dans « Clients »")')
		await page.click('.wm-folder:has-text("Clients")')
		await page.click('.wm-message:has(.wm-message-subject:text-is("Newsletter n°36"))')
		await more('.wm-sender-btn')
		await page.waitForSelector('.wm-sender-dialog p:has-text("' + count + ' messages de cet expéditeur dans « Clients »")')
		await page.selectOption('.wm-sender-dialog .wm-sender-move select', { label: 'Boîte de réception' })
		await page.click('.wm-sender-move-btn')
		await page.waitForSelector('.wm-toast:has-text("' + count + ' messages déplacés dans « Boîte de réception »")')
		await page.click('.wm-folder >> nth=0')
		await openNewsletter()
		await page.click('.wm-search-info a:has-text("effacer")')
	})

	await step('pinned: a message pinned stays at the top of its folder, whatever the order, until unpinned', async () => {
		await page.click('.wm-folder >> nth=0')
		await page.waitForSelector('.wm-list-period')
		const row = '.wm-message:has(.wm-message-subject:text-is("Newsletter n°44"))'
		// (found by a search: it may be far down the inbox), then the whole inbox again
		await page.fill('.wm-list .wm-search', 'Newsletter n°44')
		await page.press('.wm-list .wm-search', 'Enter')
		await page.click(row)
		await page.click('.wm-toolbar .wm-pin-btn')
		await page.waitForSelector('.wm-toast:has-text("Message épinglé en haut de « Boîte de réception »")')
		await page.click('.wm-search-info a:has-text("effacer")')
		await page.waitForSelector('.wm-list-period')
		const first = () => page.$eval('.wm-messages .wm-message', (e) => e.textContent)
		assert.ok((await first()).includes('Newsletter n°44'), await first())
		assert.strictEqual(await page.textContent('.wm-list-period >> nth=0'), '📌 Épinglés')
		assert.ok(await page.$eval(row, (e) => e.classList.contains('wm-pinned')))
		assert.strictEqual(await page.locator(row).count(), 1, 'shown once')
		assert.strictEqual(await page.getAttribute('.wm-toolbar .wm-pin-btn', 'aria-pressed'), 'true')
		// another order: still first, the others under « Autres messages »
		await page.selectOption('.wm-sort', 'subject-asc')
		await page.waitForSelector('.wm-list-period .wm-period-toggle:text-is("Autres messages")')
		assert.ok((await first()).includes('Newsletter n°44'))
		await page.selectOption('.wm-sort', 'date-desc')
		// kept by Nextcloud: after a reload too
		await page.reload()
		await page.waitForSelector('.wm-message.wm-pinned:has-text("Newsletter n°44")')
		await shot('87-pinned')
		await page.click(row)
		await page.click('.wm-toolbar .wm-pin-btn')
		await page.waitForSelector('.wm-toast:has-text("Message désépinglé")')
		await page.waitForSelector('.wm-message.wm-pinned', { state: 'detached' })
		assert.notStrictEqual(await page.textContent('.wm-list-period >> nth=0'), '📌 Épinglés')
	})

	await step('completion: the people of Nextcloud (an account of this Nextcloud) are offered too', async () => {
		const occ = (args, env) => execSync((env ? OCC.replace('docker exec ', 'docker exec ' + env + ' ') : OCC) + ' ' + args, { encoding: 'utf8' })
		try { occ('user:delete sophie') } catch (e) { /* not there */ }
		occ('user:add --password-from-env --display-name "Sophie Nextcloud" sophie', '-e OC_PASS=Sophie-Pass-2026')
		try {
			occ('user:setting sophie settings email sophie@nc.example')
			occ('dav:sync-system-addressbook')
			await page.click('.wm-tab[data-module="mail"]')
			await page.click('.wm-compose-btn')
			await page.click('input[name=to]')
			await page.keyboard.type('Sophie')
			await page.waitForSelector('.wm-ac-item:has-text("Sophie Nextcloud"):has-text("sophie@nc.example · compte Nextcloud")')
			await page.keyboard.press('ArrowDown')
			await page.click('.wm-ac-item:has-text("Sophie Nextcloud")')
			assert.strictEqual(await page.inputValue('input[name=to]'), 'Sophie Nextcloud <sophie@nc.example>, ')
			await page.click('.wm-compose button:has-text("Annuler")')
			await page.waitForSelector('.wm-compose', { state: 'detached' })
		} finally {
			occ('user:delete sophie')
		}
	})

	await step('completion: a Nextcloud group, chosen, adds all its members (each once, the user aside)', async () => {
		const occ = (args, env) => execSync((env ? OCC.replace('docker exec ', 'docker exec ' + env + ' ') : OCC) + ' ' + args, { encoding: 'utf8' })
		const people = [['lea', 'Léa Compta', 'lea@nc.example'], ['marc', 'Marc Compta', 'marc@nc.example'], ['nomail', 'Sans Adresse', '']]
		for (const [uid] of people) { try { occ('user:delete ' + uid) } catch (e) { /* not there */ } }
		try { occ('group:delete comptabilite') } catch (e) { /* not there */ }
		occ('group:add --display-name "Comptabilité" comptabilite')
		try {
			for (const [uid, name, email] of people) {
				occ('user:add --password-from-env --display-name "' + name + '" ' + uid, '-e OC_PASS=Compta-Pass-2026')
				if (email) occ('user:setting ' + uid + ' settings email ' + email)
				occ('group:adduser comptabilite ' + uid)
			}
			occ('group:adduser comptabilite admin')
			await page.click('.wm-tab[data-module="mail"]')
			await page.click('.wm-compose-btn')
			await page.fill('input[name=to]', 'Jean Dupont <jean.dupont@client-exemple.fr>, marc@nc.example, ')
			// (typed at the end of the field)
			await page.focus('input[name=to]')
			await page.keyboard.press('End')
			await page.keyboard.type('Compta')
			const item = '.wm-ac-item:has-text("Comptabilité"):has-text("groupe Nextcloud · 4 membres")'
			await page.waitForSelector(item)
			await shot('09b-autocomplete-group')
			await page.click(item)
			// Léa added; Marc already there; the user and the member without an address left out
			await page.waitForSelector('.wm-toast:has-text("1 membre du groupe « Comptabilité » ajouté ; 1 sans adresse e-mail")')
			assert.strictEqual(await page.inputValue('input[name=to]'), 'Jean Dupont <jean.dupont@client-exemple.fr>, marc@nc.example, Léa Compta <lea@nc.example>, ')
			// a group one cannot see (sharing limited to one's groups): refused
			const r = await page.evaluate(async () => (await fetch(OC.generateUrl('/apps/webmail/api/nextcloud-groups/members') + '?gid=nexistepas', { headers: { requesttoken: OC.requestToken } })).status)
			assert.ok(r >= 400, String(r))
			await page.click('.wm-compose button:has-text("Annuler")')
			await page.waitForSelector('.wm-compose', { state: 'detached' })
		} finally {
			for (const [uid] of people) { try { occ('user:delete ' + uid) } catch (e) { /* gone */ } }
			occ('group:delete comptabilite')
		}
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

	await step('Matrix: the account connected, a message and an event sent to rooms (Element)', async () => {
		const occ = (args) => execSync(OCC + ' ' + args, { encoding: 'utf8' })
		// what the fake homeserver received (it runs here, with its own certificate)
		const sent = () => new Promise((resolve, reject) => {
			require('https').get('https://localhost:8443/_matrix/test/sent', { rejectUnauthorized: false }, (res) => {
				let data = ''
				res.on('data', (c) => { data += c })
				res.on('end', () => resolve(JSON.parse(data)))
			}).on('error', reject)
		})
		// (the fake servers are on the local network: allowed here only)
		occ('config:system:set allow_local_remote_servers --value=true --type=boolean')
		try {
			await page.click('.wm-tab[data-module="mail"]')
			await page.click('text=Comptes et signature')
			await page.fill('input[name=matrixHomeserver]', 'ex3.fake.test:8443')
			await page.fill('input[name=matrixToken]', 'pas-le-bon')
			await page.click('.wm-matrix-form button[type=submit]')
			await page.waitForSelector('.wm-toast:has-text("Le serveur Matrix n\'a pas reconnu ce jeton")')
			await page.fill('input[name=matrixToken]', 'syt_test_token')
			await page.fill('input[name=matrixRoom]', '#equipe:fake.test')
			await page.click('.wm-matrix-form button[type=submit]')
			await page.waitForSelector('.wm-toast:has-text("Compte Matrix enregistré")')
			await page.waitForSelector('.wm-matrix-status:has-text("Connecté en tant que @user:fake.test")')
			assert.strictEqual(await page.inputValue('input[name=matrixHomeserver]'), 'https://ex3.fake.test:8443')
			// (the token is never shown again)
			assert.strictEqual(await page.inputValue('input[name=matrixToken]'), '')

			// a message: its summary, to the default room
			await page.click('.wm-folder >> nth=0')
			await page.click('.wm-message:has-text("Devis pour la maintenance annuelle")')
			await page.waitForSelector('.wm-message-header h2:has-text("Devis pour la maintenance annuelle")')
			await more('.wm-matrix-send-btn')
			await page.waitForSelector('.wm-matrix-send select[name=matrixSendRoom] option:has-text("Équipe chantier")', { state: 'attached' })
			const text = await page.inputValue('textarea[name=matrixText]')
			assert.ok(text.startsWith('✉️ Devis pour la maintenance annuelle\nDe : Jean Dupont <jean.dupont@client-exemple.fr>'), text)
			assert.ok(text.includes('Veuillez trouver ci-joint'), text)
			await shot('92-matrix-send')
			await page.click('.wm-matrix-send button[type=submit]')
			await page.waitForSelector('.wm-toast:has-text("Envoyé sur Matrix")')
			let last = (await sent()).pop()
			assert.strictEqual(last.room, '!equipe:fake.test')
			assert.ok(last.content.body.startsWith('✉️ Devis pour la maintenance annuelle'), last.content.body)
			assert.ok(last.content.formatted_body.includes('&lt;jean.dupont@client-exemple.fr&gt;'), last.content.formatted_body)

			// an event, to another room
			await page.click('.wm-tab[data-module="calendar"]')
			await page.click('.wm-segmented button:has-text("Liste")')
			await page.click('.wm-agenda-item:has-text("Comité de pilotage")')
			await page.click('.wm-modal .wm-matrix-send-btn')
			await page.waitForSelector('.wm-matrix-send select[name=matrixSendRoom] option:has-text("Mes notes")', { state: 'attached' })
			await page.selectOption('.wm-matrix-send select[name=matrixSendRoom]', { label: 'Mes notes' })
			assert.ok((await page.inputValue('textarea[name=matrixText]')).startsWith('📅 Comité de pilotage\n🕑 '))
			await page.click('.wm-matrix-send button[type=submit]')
			await page.waitForSelector('.wm-toast:has-text("Envoyé sur Matrix dans Mes notes")')
			last = (await sent()).pop()
			assert.strictEqual(last.room, '!notes:fake.test')
			await page.click('.wm-segmented button:has-text("Mois")')

			// alerts of new messages in the default room, sent by the background job
			await page.click('.wm-tab[data-module="mail"]')
			await page.click('text=Comptes et signature')
			// (the account loaded: the alerts are offered once it is connected)
			await page.waitForSelector('.wm-matrix-status:has-text("Compte Matrix enregistré")')
			await page.selectOption('select[name=matrixAlerts]', 'all')
			await page.waitForSelector('.wm-toast:has-text("Les nouveaux messages seront signalés dans votre salon Matrix")')
			// (a first check of the account only notes where it stands: messages from now on are new)
			const newMailJob = (occ("background-job:list --class='OCA\\Webmail\\BackgroundJob\\NewMailJob'").match(/\|\s*(\d+)\s*\|\s*OCA/) || [])[1]
			occ('background-job:execute ' + newMailJob + ' --force-execute')
			await page.click('.wm-compose-btn')
			await page.fill('input[name=to]', 'user@societe-a.fr')
			await page.fill('input[name=subject]', 'Livraison des fenêtres')
			await page.fill('.wm-editor', 'Les fenêtres arrivent jeudi matin.')
			await submitCompose()
			occ('background-job:execute ' + newMailJob + ' --force-execute')
			last = (await sent()).pop()
			assert.strictEqual(last.room, '!equipe:fake.test')
			assert.ok(last.content.body.startsWith('📬 ') && last.content.body.includes('« Livraison des fenêtres »'), last.content.body)
			await page.click('text=Comptes et signature')
			await page.selectOption('select[name=matrixAlerts]', 'off')
			await page.waitForSelector('.wm-toast:has-text("Plus d\'alertes sur Matrix")')

			// « Visio Element » with an Element Web without Element Call: preset by the administration
			// from the « Sites externes » of Nextcloud, a room made for the meeting with this Matrix account
			const created = () => new Promise((resolve, reject) => {
				require('https').get('https://localhost:8443/_matrix/test/created', { rejectUnauthorized: false }, (res) => {
					let data = ''
					res.on('data', (c) => { data += c })
					res.on('end', () => resolve(JSON.parse(data)))
				}).on('error', reject)
			})
			occ('config:app:delete webmail visio')
			occ('config:app:delete webmail visio_mode')
			occ('config:app:set external sites --value=\'' + JSON.stringify({ 1: { id: 1, name: 'Chat PBCAN', url: 'https://ex3.fake.test:8443/element/', lang: '', type: 'link', device: '', icon: 'external.svg', groups: [], redirect: false } }) + '\'')
			// (Nextcloud 33 and later keep the app settings 3 s in each web process: changed by occ, seen after)
			await page.waitForTimeout(3500)
			try {
				await page.goto(NC + '/settings/admin/webmail')
				await page.waitForSelector('#webmail-visio-detected')
				assert.strictEqual(await page.inputValue('#webmail-visio'), 'https://ex3.fake.test:8443/element')
				assert.ok(await page.isChecked('#webmail-visio-room'))
				const detected = await page.textContent('#webmail-visio-detected')
				assert.ok(detected.includes('Chat PBCAN') && detected.includes('https://ex3.fake.test:8443'), detected)
				await shot('94-visio-element-preset')
				await page.click('#webmail-save')
				await page.waitForSelector('#webmail-admin-msg.success')
				assert.strictEqual(occ('config:app:get webmail visio_mode').trim(), 'element-room')
				assert.strictEqual(occ('config:app:get webmail visio').trim(), 'https://ex3.fake.test:8443/element')
				// the button of the event form makes the room
				await page.goto(NC + '/apps/webmail/')
				await page.waitForSelector('.wm-tab')
				await page.click('.wm-tab[data-module="calendar"]')
				await page.waitForSelector('.wm-compose-btn:has-text("Nouvel événement")')
				for (let i = 0; i < 5 && !(await page.$('.wm-modal input[name=subject]')); i++) {
					await page.click('.wm-compose-btn')
					await page.waitForSelector('.wm-modal input[name=subject]', { timeout: 3000 }).catch(() => {})
				}
				await page.fill('.wm-modal input[name=subject]', 'Point visio chantier')
				await page.click('.wm-visio-btn')
				await page.waitForSelector('.wm-toast:has-text("Salle de visio ajoutée")')
				const place = await page.inputValue('.wm-modal input[name=location]')
				assert.ok(place.startsWith('https://ex3.fake.test:8443/element/#/room/%21meet') && place.endsWith('?via=fake.test'), place)
				assert.ok((await page.inputValue('.wm-modal textarea[name=body]')).includes(place))
				const room = (await created()).pop()
				assert.strictEqual(room.name, 'Point visio chantier')
				assert.strictEqual(room.creation_content['m.federate'], false)
				assert.strictEqual(room.creation_content.type, 'org.matrix.msc3417.call')
				await page.click('.wm-modal .wm-modal-close')
			} finally {
				occ('config:app:delete webmail visio')
				occ('config:app:delete webmail visio_mode')
				occ('config:app:delete external sites')
			}
			await page.click('.wm-tab[data-module="mail"]')
			await page.click('text=Comptes et signature')

			// disconnected: the button asks to connect
			await page.click('.wm-matrix-form button:has-text("Déconnecter")')
			await page.waitForSelector('.wm-toast:has-text("Compte Matrix déconnecté")')
			await page.click('.wm-folder >> nth=0')
			await page.click('.wm-message:has-text("Devis pour la maintenance annuelle")')
			await more('.wm-matrix-send-btn')
			await page.waitForSelector('.wm-matrix-send button:has-text("Connecter mon compte Matrix")')
			await page.click('.wm-matrix-send .wm-modal-close')

			// the chat of the organisation among the « Sites externes », nothing set by the administrator:
			// its visio comes before Talk, and the account is linked by Nextcloud (the chat's single sign-on), nothing to copy
			const ssoSeen = () => new Promise((resolve, reject) => {
				require('https').get('https://localhost:8443/_matrix/test/sso', { rejectUnauthorized: false }, (res) => {
					let data = ''
					res.on('data', (c) => { data += c })
					res.on('end', () => resolve(JSON.parse(data)))
				}).on('error', reject)
			})
			// (as on a real server: the admin page once saved with the visio field empty,
			// and Element declared on one of its pages)
			occ('config:app:set webmail visio --value=')
			occ('config:app:set external sites --value=\'' + JSON.stringify({ 1: { id: 1, name: 'Chat PBCAN', url: 'https://ex3.fake.test:8443/element/#/home', lang: '', type: 'link', device: '', icon: 'external.svg', groups: [], redirect: false } }) + '\'')
			// (Nextcloud 33 and later keep the app settings 3 s in each web process)
			await page.waitForTimeout(3500)
			try {
				await page.goto(NC + '/apps/webmail/')
				await page.waitForSelector('.wm-tab')
				await page.click('.wm-tab[data-module="calendar"]')
				await page.waitForSelector('.wm-compose-btn:has-text("Nouvel événement")')
				for (let i = 0; i < 5 && !(await page.$('.wm-modal input[name=subject]')); i++) {
					await page.click('.wm-compose-btn')
					await page.waitForSelector('.wm-modal input[name=subject]', { timeout: 3000 }).catch(() => {})
				}
				await page.fill('.wm-modal input[name=subject]', 'Réunion par le chat')
				assert.ok(await page.$('.wm-modal .wm-visio-btn'), 'the visio of the chat, set by nobody')
				assert.ok(!(await page.$('.wm-modal .wm-talk-btn')), 'the chat before Talk')
				let asked = ''
				page.once('dialog', (d) => { asked = d.message(); d.accept() })
				const popup = page.waitForEvent('popup')
				await page.click('.wm-visio-btn')
				const win = await popup
				// the window of the chat's sign-in closes by itself, the room is then made
				await win.waitForEvent('close', { timeout: 30000 }).catch(() => {})
				await page.waitForSelector('.wm-toast:has-text("Salle de visio ajoutée")')
				assert.ok(asked.includes('Chat PBCAN') && asked.includes('Nextcloud'), asked)
				const place = await page.inputValue('.wm-modal input[name=location]')
				assert.ok(place.startsWith('https://ex3.fake.test:8443/element/#/room/%21meet'), place)
				assert.ok((await ssoSeen()).some((u) => u.includes('/apps/webmail/matrix/sso/callback?state=')), 'back to the webmail')
				await shot('95-visio-chat-sso')
				await page.click('.wm-modal .wm-modal-close')
				// the session ends on the chat's side: the next visio asks to sign in again, then makes the room
				await new Promise((resolve, reject) => require('https').get('https://localhost:8443/_matrix/test/revoke', { rejectUnauthorized: false }, (res) => { res.resume(); res.on('end', resolve) }).on('error', reject))
				for (let i = 0; i < 5 && !(await page.$('.wm-modal input[name=subject]')); i++) {
					await page.click('.wm-compose-btn')
					await page.waitForSelector('.wm-modal input[name=subject]', { timeout: 3000 }).catch(() => {})
				}
				await page.fill('.wm-modal input[name=subject]', 'Réunion après la fin de session')
				let again = ''
				page.once('dialog', (d) => { again = d.message(); d.accept() })
				const popup2 = page.waitForEvent('popup')
				await page.click('.wm-visio-btn')
				const win2 = await popup2
				await win2.waitForEvent('close', { timeout: 30000 }).catch(() => {})
				await page.waitForSelector('.wm-toast:has-text("Salle de visio ajoutée")')
				assert.ok(again.includes('a expiré'), again)
				await page.click('.wm-modal .wm-modal-close')
				// the participants with an account (of Nextcloud, so of the chat) invited in the room; the others keep the link
				// (left by a run stopped midway)
				try { occ('user:delete marie-e2e') } catch (e) { /* none */ }
				occ('user:add --generate-password --display-name="Marie Martin" marie-e2e')
				try {
					occ('user:setting marie-e2e settings email marie.martin@societe-a.fr')
					for (let i = 0; i < 5 && !(await page.$('.wm-modal input[name=subject]')); i++) {
						await page.click('.wm-compose-btn')
						await page.waitForSelector('.wm-modal input[name=subject]', { timeout: 3000 }).catch(() => {})
					}
					await page.fill('.wm-modal input[name=subject]', 'Revue avec Marie')
					// (in the own calendar, whatever was chosen last)
					if (await page.$('.wm-modal select[name=calendar]')) await page.selectOption('.wm-modal select[name=calendar]', 'calendar')
					await page.click('.wm-visio-btn')
					// (the room of this event, not the toast of the one before)
					await page.waitForFunction(() => (document.querySelector('.wm-modal input[name=location]') || {}).value.includes('/#/room/'))
					await page.fill('.wm-modal input[name=attendees]', 'marie.martin@societe-a.fr, client@exterieur-e2e.fr')
					// (the suggestions of the completion closed by a click elsewhere)
					await page.click('.wm-modal input[name=subject]')
					await page.click('.wm-modal button[type=submit]')
					await page.waitForSelector('.wm-toast:has-text("1 participant invité dans le salon du chat")')
					const invited = await new Promise((resolve, reject) => require('https').get('https://localhost:8443/_matrix/test/invited', { rejectUnauthorized: false }, (res) => {
						let data = ''
						res.on('data', (c) => { data += c })
						res.on('end', () => resolve(JSON.parse(data)))
					}).on('error', reject))
					const last = invited[invited.length - 1]
					assert.ok(last && last.user_id === '@marie.martin:fake.test' && last.room.startsWith('!meet'), JSON.stringify(invited))

					// the room follows the meeting: a new time said in it (and as its topic), the start told, the cancellation closes it
					const fake = (what) => new Promise((resolve, reject) => require('https').get('https://localhost:8443/_matrix/test/' + what, { rejectUnauthorized: false }, (res) => {
						let data = ''
						res.on('data', (c) => { data += c })
						res.on('end', () => resolve(JSON.parse(data)))
					}).on('error', reject))
					const room = last.room
					const notices = async () => (await fake('sent')).filter((x) => x.room === room && x.content.msgtype === 'm.notice').map((x) => x.content.body)
					const states = async () => (await fake('state')).filter((x) => x.room === room)
					const cal = await page.evaluate(async () => {
						const accounts = await (await fetch('/apps/webmail/api/accounts', { headers: { requesttoken: OC.requestToken } })).json()
						const id = accounts.find((a) => a.email === 'user@societe-a.fr').id
						const q = new URLSearchParams({ start: new Date(Date.now() - 86400000 * 40).toISOString(), end: new Date(Date.now() + 86400000 * 40).toISOString(), tz: 'UTC' })
						const r = await (await fetch('/apps/webmail/api/accounts/' + id + '/calendar/events?' + q, { headers: { requesttoken: OC.requestToken } })).json()
						const ev = r.events.find((e) => e.subject === 'Revue avec Marie')
						return { id, ev }
					})
					assert.ok(cal.ev, 'the meeting saved')
					const call = (path, body) => page.evaluate(async ({ path, body }) => {
						const r = await fetch('/apps/webmail/api' + path, { method: 'POST', headers: { requesttoken: OC.requestToken, 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
						if (!r.ok) throw new Error(path + ' ' + r.status + ' ' + await r.text())
						return r.json()
					}, { path, body })
					// moved to the next day, 9:00 – 10:00 (UTC)
					const day = new Date(Date.parse(cal.ev.start) + 86400000).toISOString().slice(0, 10)
					await call('/accounts/' + cal.id + '/calendar/move', { eventId: cal.ev.id, start: day + 'T09:00', end: day + 'T10:00', tz: 'UTC' })
					assert.ok((await notices()).some((t) => t.startsWith('Nouvel horaire de la réunion : ')), JSON.stringify(await notices()))
					const topic = (await states()).filter((x) => x.type === 'm.room.topic').pop()
					assert.ok(topic && /10:00/.test(topic.content.topic), JSON.stringify(await states()))
					// a meeting with its room beginning: told by the reminder job, once
					const started = await call('/accounts/' + cal.id + '/calendar/events?tz=UTC', {
						event: { subject: 'Point qui commence', start: new Date(Date.now() - 60000).toISOString().slice(0, 16), end: new Date(Date.now() + 1800000).toISOString().slice(0, 16), location: 'https://ex3.fake.test:8443/element/#/room/' + encodeURIComponent(room) + '?via=fake.test', reminder: -1 },
					})
					const reminderJob = (occ("background-job:list --class='OCA\\Webmail\\BackgroundJob\\ReminderJob'").match(/\|\s*(\d+)\s*\|\s*OCA/) || [])[1]
					occ('background-job:execute ' + reminderJob + ' --force-execute')
					occ('background-job:execute ' + reminderJob + ' --force-execute')
					assert.strictEqual((await notices()).filter((t) => t.includes('« Point qui commence » commence')).length, 1, JSON.stringify(await notices()))
					await call('/accounts/' + cal.id + '/calendar/delete', { eventId: started.id })
					// cancelled: said, renamed, closed to new comers
					await call('/accounts/' + cal.id + '/calendar/delete', { eventId: cal.ev.id })
					assert.ok((await notices()).includes('La réunion « Revue avec Marie » est annulée.'), JSON.stringify(await notices()))
					const after = await states()
					assert.ok(after.some((x) => x.type === 'm.room.name' && x.content.name === 'Annulée : Revue avec Marie'), JSON.stringify(after))
					assert.ok(after.some((x) => x.type === 'm.room.join_rules' && x.content.join_rule === 'invite'), JSON.stringify(after))
				} finally {
					occ('user:delete marie-e2e')
				}
				// the card of the account: linked, to link again by Nextcloud if need be
				await page.click('.wm-tab[data-module="mail"]')
				await page.click('text=Comptes et signature')
				await page.waitForSelector('.wm-matrix-sso:has-text("Se reconnecter avec Nextcloud")')
				await page.waitForSelector('.wm-matrix-form button:has-text("Déconnecter")')
				await page.click('.wm-matrix-form button:has-text("Déconnecter")')
				await page.waitForSelector('.wm-toast:has-text("Compte Matrix déconnecté")')
				await page.waitForSelector('.wm-matrix-sso:has-text("Se connecter avec Nextcloud")')
			} finally {
				occ('config:app:delete external sites')
				occ('config:app:delete webmail visio')
				await page.waitForTimeout(3500)
			}
		} finally {
			occ('config:system:delete allow_local_remote_servers')
		}
	})

	await step('touch: a message swiped to the right is read / unread, to the left goes to the trash (with « Annuler »)', async () => {
		const row = '.wm-message:has(.wm-message-subject:text-is("Newsletter n°2"))'
		// a finger on the row, moved by a fraction of its width (touch events, as a phone sends them)
		const swipeOnce = (fraction) => page.locator(row).evaluate((el, f) => {
			const r = el.getBoundingClientRect()
			// a row the list has just drawn again is no longer on the page (no width): not swiped
			if (!el.isConnected || r.width === 0) return false
			const x = r.left + r.width / 2
			const y = r.top + r.height / 2
			const t = (cx) => new Touch({ identifier: 1, target: el, clientX: cx, clientY: y })
			el.dispatchEvent(new TouchEvent('touchstart', { touches: [t(x)], bubbles: true, cancelable: true }))
			for (let i = 1; i <= 10; i++) el.dispatchEvent(new TouchEvent('touchmove', { touches: [t(x + r.width * f * i / 10)], bubbles: true, cancelable: true }))
			el.dispatchEvent(new TouchEvent('touchend', { touches: [], changedTouches: [t(x + r.width * f)], bubbles: true, cancelable: true }))
			return true
		}, fraction)
		// a finger only touches a row on the screen: again on the new row if the list was drawn again
		const swipe = async (fraction) => {
			for (let i = 0; i < 20; i++) {
				if (await swipeOnce(fraction)) return
				await page.waitForTimeout(100)
			}
			throw new Error('row never on the screen')
		}
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

	await step('Nextcloud Calendar: the Exchange calendar seen by CalDAV (read only), then taken away', async () => {
		await page.click('.wm-tab[data-module="calendar"]')
		await page.waitForSelector('.wm-cal-nc input:not([disabled])')
		assert(!(await page.isChecked('.wm-cal-nc input')))
		await page.check('.wm-cal-nc input')
		await page.waitForSelector('.wm-toast:has-text("Agenda Exchange visible dans l\'Agenda de Nextcloud")')
		const caldav = (method, sub = '', body = null) => page.evaluate(async ({ method, sub, body }) => {
			const base = '/remote.php/dav/calendars/' + OC.getCurrentUser().uid + '/'
			const list = await (await fetch(base, {
				method: 'PROPFIND',
				headers: { Depth: '1', requesttoken: OC.requestToken, 'Content-Type': 'application/xml' },
				body: '<d:propfind xmlns:d="DAV:"><d:prop><d:displayname/></d:prop></d:propfind>',
			})).text()
			const m = /calendars\/[^/]+\/(app-generated--[a-z-]+--webmail-exchange-\d+)\//.exec(list)
			if (!m) return { found: false }
			const r = await fetch(base + m[1] + '/' + sub, { method, headers: { Depth: '1', requesttoken: OC.requestToken, 'Content-Type': method === 'PUT' ? 'text/calendar' : 'application/xml' }, body })
			return { found: true, status: r.status, text: await r.text(), name: list.includes('Exchange — ') }
		}, { method, sub, body })
		const query = '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/><c:calendar-data/></d:prop>'
			+ '<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT"/></c:comp-filter></c:filter></c:calendar-query>'
		const warned = uidWarnings()
		const r = await caldav('REPORT', '', query)
		assert(r.found && r.name, 'calendar not found')
		assert.strictEqual(uidWarnings(), warned, 'warnings « Undefined array key UID » in the log')
		assert.strictEqual(r.status, 207, r.text.slice(0, 300))
		assert(r.text.includes('SUMMARY:Comité de pilotage'), r.text.slice(0, 600))
		assert(/UID:webmail-[0-9a-f]{32}/.test(r.text))
		// (long lines folded by iCalendar: unfolded first)
		assert(r.text.replace(/\r?\n[ \t]/g, '').includes('apps/webmail/?account='), 'a link back to the webmail')
		// read only: Nextcloud refuses a new event there
		const put = await caldav('PUT', 'nouveau.ics', 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:nouveau\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260101T100000Z\r\nSUMMARY:x\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n')
		assert(put.status === 403 || put.status === 405 || put.status === 501, 'PUT ' + put.status)
		// the answer of the server (its toast may already have made way for another one);
		// again if the box was drawn anew under the click (the side of the calendar is redrawn when its data comes)
		let off = null
		for (let i = 0; i < 3 && !off; i++) {
			const answer = page.waitForResponse((res) => res.url().endsWith('/calendar/nextcloud') && res.request().method() === 'POST', { timeout: 8000 }).catch(() => null)
			await page.uncheck('.wm-cal-nc input')
			off = await answer
		}
		assert.ok(off, 'no request to take the calendar away')
		assert.strictEqual((await off.json()).enabled, false)
		assert.strictEqual((await caldav('PROPFIND')).found, false)
	})

	await step('Nextcloud calendars in the Agenda: read, created, an occurrence changed, the series deleted (CalDAV sees it all)', async () => {
		// CalDAV of the user, as the Calendar app and the phones see it
		const dav = (method, path, body = null, extra = {}) => page.evaluate(async ({ method, path, body, extra }) => {
			const r = await fetch('/remote.php/dav/' + path.replace('{user}', OC.getCurrentUser().uid), {
				method, body, headers: Object.assign({ requesttoken: OC.requestToken }, extra),
			})
			return { status: r.status, text: (await r.text()).replace(/\r?\n[ \t]/g, '') }
		}, { method, path, body, extra })
		await page.click('.wm-tab[data-module="calendar"]')
		const personal = '.wm-cal-row[data-key^="nc:"]:has-text("Personal")'
		await page.waitForSelector(personal + ' input:checked')
		// the birthdays of the contacts: listed, not shown until ticked
		const birthdays = '.wm-cal-row[data-key^="nc:"]:has-text("birthdays"), .wm-cal-row[data-key^="nc:"]:has-text("Anniversaires")'
		assert(!(await page.isChecked(birthdays + ' >> input')), 'birthdays hidden by default')
		assert.strictEqual(await page.textContent(birthdays + ' >> .wm-cal-mode'), 'lecture')

		// an event put in the Calendar app (here by CalDAV): tomorrow 10:00, every week, 3 times
		const day = await page.evaluate(() => { const d = new Date(Date.now() + 86400000); return d.getFullYear() + String(d.getMonth() + 1).padStart(2, '0') + String(d.getDate()).padStart(2, '0') })
		const ics = 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//e2e//FR\r\nBEGIN:VEVENT\r\nUID:e2e-nc-weekly\r\nDTSTAMP:20260101T000000Z\r\n'
			+ 'DTSTART;TZID=Europe/Paris:' + day + 'T100000\r\nDTEND;TZID=Europe/Paris:' + day + 'T110000\r\nRRULE:FREQ=WEEKLY;COUNT=3\r\n'
			+ 'SUMMARY:Point Nextcloud\r\nLOCATION:Salle B\r\nCATEGORIES:Projet\r\nX-E2E-KEPT:oui\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n'
		const put = await dav('PUT', 'calendars/{user}/personal/e2e-nc-weekly.ics', ics, { 'Content-Type': 'text/calendar' })
		assert([201, 204].includes(put.status), 'PUT ' + put.status)
		await page.click('.wm-segmented button:has-text("Liste")')
		await page.waitForFunction(() => [...document.querySelectorAll('.wm-agenda-item')].filter((e) => e.textContent.includes('Point Nextcloud')).length >= 2)
		await page.click('.wm-agenda-item:has-text("Point Nextcloud") >> nth=0')
		await page.waitForSelector('.wm-modal:has-text("Agenda Nextcloud « Personal »")')
		await page.waitForSelector('.wm-modal .wm-event-rule:has-text("semaine")')
		await shot('100-nextcloud-event')
		// this occurrence only: renamed
		await page.click('.wm-modal button:has-text("Modifier cette occurrence")')
		await page.waitForSelector('.wm-modal h2:has-text("Modifier l\'événement")')
		assert(await page.isHidden('.wm-modal input[name=attendees]'), 'no invitations from a Nextcloud calendar')
		await page.fill('.wm-modal input[name=subject]', 'Point Nextcloud (avancé)')
		await page.click('.wm-modal button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Événement modifié")')
		await page.waitForSelector('.wm-agenda-item:has-text("Point Nextcloud (avancé)")')
		let got = await dav('GET', 'calendars/{user}/personal/e2e-nc-weekly.ics')
		assert(got.text.includes('RRULE:FREQ=WEEKLY;COUNT=3') && got.text.includes('RECURRENCE-ID'), got.text)
		assert(got.text.includes('SUMMARY:Point Nextcloud (avancé)') && got.text.includes('SUMMARY:Point Nextcloud\r\n'), got.text)
		assert(got.text.includes('X-E2E-KEPT:oui') && got.text.includes('CATEGORIES:Projet'), 'the rest of the event is kept')

		// a new event in the Nextcloud calendar, from the webmail
		await page.click('.wm-compose-btn:has-text("Nouvel événement")')
		const key = await page.getAttribute(personal, 'data-key')
		await page.selectOption('.wm-modal select[name=calendar]', key)
		assert(await page.isHidden('.wm-modal input[name=attendees]'))
		await page.fill('.wm-modal input[name=subject]', 'Créé dans le webmail')
		await page.click('.wm-modal button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Événement créé")')
		await page.waitForSelector('.wm-agenda-item:has-text("Créé dans le webmail")')
		const report = await dav('REPORT', 'calendars/{user}/personal/', '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><c:calendar-data/></d:prop>'
			+ '<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT"/></c:comp-filter></c:filter></c:calendar-query>', { Depth: '1', 'Content-Type': 'application/xml' })
		assert(report.text.includes('SUMMARY:Créé dans le webmail') && report.text.includes('TZID=Europe/Paris'), report.text.slice(0, 1500))
		assert(report.text.includes('TRIGGER:-PT15M'), 'the reminder of the form')
		await page.click('.wm-agenda-item:has-text("Créé dans le webmail")')
		page.once('dialog', (d) => d.accept())
		await page.click('.wm-modal button.wm-danger:has-text("Supprimer")')
		await page.waitForSelector('.wm-toast:has-text("Événement supprimé")')
		// (the list drawn again without it, before the next click)
		await page.waitForFunction(() => ![...document.querySelectorAll('.wm-agenda-item')].some((e) => e.textContent.includes('Créé dans le webmail')))
		await page.waitForSelector('.wm-modal', { state: 'detached' })

		// the whole series, from one occurrence
		await page.click('.wm-agenda-item:has-text("Point Nextcloud") >> nth=1')
		await page.waitForSelector('.wm-modal button:has-text("Supprimer la série")')
		page.once('dialog', (d) => d.accept())
		await page.click('.wm-modal button:has-text("Supprimer la série")')
		await page.waitForSelector('.wm-modal', { state: 'detached' })
		await page.waitForFunction(() => ![...document.querySelectorAll('.wm-agenda-item')].some((e) => e.textContent.includes('Point Nextcloud') || e.textContent.includes('Créé dans le webmail')))
		// (the list of the calendar: a GET of a missing event would be an error in the console)
		const left = await dav('PROPFIND', 'calendars/{user}/personal/', null, { Depth: '1' })
		assert.strictEqual(left.status, 207)
		assert(!left.text.includes('e2e-nc-weekly.ics'), 'the series is deleted in Nextcloud')
	})

	await step('Nextcloud address books in Contacts: a card of the Contacts app read, changed (its other fields kept), created, deleted', async () => {
		const dav = (method, path, body = null, extra = {}) => page.evaluate(async ({ method, path, body, extra }) => {
			const r = await fetch('/remote.php/dav/' + path.replace('{user}', OC.getCurrentUser().uid), {
				method, body, headers: Object.assign({ requesttoken: OC.requestToken }, extra),
			})
			return { status: r.status, text: (await r.text()).replace(/\r?\n[ \t]/g, '') }
		}, { method, path, body, extra })
		const card = 'BEGIN:VCARD\r\nVERSION:3.0\r\nUID:e2e-nc-claire\r\nFN:Claire Nextcloud\r\nN:Nextcloud;Claire;;;\r\nEMAIL;TYPE=WORK:claire@nextcloud-e2e.fr\r\n'
			+ 'TEL;TYPE=CELL:06 99 88 77 66\r\nCATEGORIES:Amis\r\nX-E2E-KEPT:oui\r\nEND:VCARD\r\n'
		const put = await dav('PUT', 'addressbooks/users/{user}/contacts/e2e-nc-claire.vcf', card, { 'Content-Type': 'text/vcard' })
		assert([201, 204].includes(put.status), 'PUT ' + put.status)
		await page.click('.wm-tab[data-module="contacts"]')
		const ncBook = '.wm-book-row[data-key^="nc:"]:has-text("Contacts") .wm-book-link'
		await page.waitForSelector('.wm-cal-section:has-text("Carnets Nextcloud")')
		await page.click(ncBook)
		await page.waitForSelector('.wm-contact:has-text("Claire Nextcloud")')
		assert((await page.textContent('.wm-contact:has-text("Claire Nextcloud")')).includes('claire@nextcloud-e2e.fr'))
		await page.click('.wm-contact:has-text("Claire Nextcloud")')
		await page.waitForSelector('.wm-contact-card:has-text("06 99 88 77 66")')
		await shot('101-nextcloud-contact')
		await page.click('.wm-toolbar button:has-text("Modifier")')
		await page.fill('input[name=jobTitle]', 'Architecte')
		await page.click('.wm-contact-form button[type=submit]')
		await page.waitForSelector('.wm-contact-card:has-text("Architecte")')
		let got = await dav('GET', 'addressbooks/users/{user}/contacts/e2e-nc-claire.vcf')
		assert(got.text.includes('TITLE:Architecte') && got.text.includes('UID:e2e-nc-claire'), got.text)
		assert(got.text.includes('CATEGORIES:Amis') && got.text.includes('X-E2E-KEPT:oui'), 'the other fields are kept: ' + got.text)
		// searched there too
		await page.fill('.wm-list .wm-search', 'archi')
		await page.press('.wm-list .wm-search', 'Enter')
		await page.waitForSelector('.wm-search-info:has-text("archi")')
		await page.waitForFunction(() => document.querySelectorAll('.wm-contact').length === 0 || [...document.querySelectorAll('.wm-contact')].every((e) => e.textContent.includes('Claire')))
		await page.fill('.wm-list .wm-search', 'claire')
		await page.press('.wm-list .wm-search', 'Enter')
		await page.waitForSelector('.wm-search-info:has-text("claire")')
		await page.waitForFunction(() => document.querySelectorAll('.wm-contact').length === 1)
		await page.click('.wm-search-info a')

		// a new card, from the webmail, in the Nextcloud book shown
		await page.click('.wm-compose-btn:has-text("Nouveau contact")')
		assert((await page.inputValue('select[name=book]')).startsWith('nc:'), 'new contact goes to the displayed book')
		await page.fill('input[name=givenName]', 'Bruno')
		await page.fill('input[name=surname]', 'Webmail')
		await page.fill('input[name=email1]', 'bruno@nextcloud-e2e.fr')
		await page.click('.wm-contact-form button[type=submit]')
		await page.waitForSelector('.wm-toast:has-text("Contact créé")')
		await page.waitForSelector('.wm-contact:has-text("Bruno Webmail")')
		const query = await dav('REPORT', 'addressbooks/users/{user}/contacts/', '<c:addressbook-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:carddav"><d:prop><c:address-data/></d:prop></c:addressbook-query>', { Depth: '1', 'Content-Type': 'application/xml' })
		assert(query.text.includes('EMAIL;TYPE=INTERNET,PREF:bruno@nextcloud-e2e.fr') && /UID:[0-9a-f-]{36}/.test(query.text), query.text.slice(0, 1500))

		// the accounts of this Nextcloud (« Comptes » of the Contacts app): the colleagues, read only, with their avatar
		const occ = (args) => execSync(OCC + ' ' + args, { encoding: 'utf8' })
		occ('user:add --generate-password --display-name="Denise Collègue" denise-e2e')
		try {
			occ('user:setting denise-e2e settings email denise@nextcloud-e2e.fr')
			occ('dav:sync-system-addressbook')
			await page.reload()
			await page.click('.wm-tab[data-module="contacts"]')
			await page.waitForSelector('.wm-book-row[data-key="nc:accounts"]')
			assert.strictEqual(await page.textContent('.wm-book-row[data-key="nc:accounts"] .wm-cal-name'), 'Comptes Nextcloud')
			await page.click('.wm-book-row[data-key="nc:accounts"] .wm-book-link')
			await page.waitForSelector('.wm-contact:has-text("Denise Collègue")')
			assert((await page.textContent('.wm-contact:has-text("Denise Collègue")')).includes('denise@nextcloud-e2e.fr'))
			assert((await page.getAttribute('.wm-contact:has-text("Denise Collègue") img.wm-avatar-photo', 'src')).includes('/avatar/denise-e2e/'), 'her Nextcloud avatar')
			assert(!(await page.$('.wm-contact:has-text("admin")')), 'not oneself')
			await page.click('.wm-contact:has-text("Denise Collègue")')
			await page.waitForSelector('.wm-toolbar:has-text("lecture seule")')
			assert(await page.$('.wm-toolbar button:has-text("Écrire")'))
			assert(!(await page.$('.wm-toolbar button:has-text("Modifier")')))
			await shot('102-nextcloud-accounts')
		} finally {
			occ('user:delete denise-e2e')
		}
		await page.click(ncBook)
		await page.waitForSelector('.wm-contact:has-text("Bruno Webmail")')

		for (const name of ['Bruno Webmail', 'Claire Nextcloud']) {
			await page.click('.wm-contact:has-text("' + name + '")')
			page.once('dialog', (d) => d.accept())
			await page.click('.wm-toolbar button:has-text("Supprimer")')
			await page.waitForSelector('.wm-toast:has-text("Contact supprimé")')
			await page.waitForFunction((n) => ![...document.querySelectorAll('.wm-contact')].some((e) => e.textContent.includes(n)), name)
		}
		const left = await dav('PROPFIND', 'addressbooks/users/{user}/contacts/', null, { Depth: '1' })
		assert.strictEqual(left.status, 207)
		assert(!left.text.includes('e2e-nc-claire.vcf') && !left.text.includes('Bruno'), 'the cards are deleted in Nextcloud')
		await page.click(book('Contacts'))
		await page.waitForSelector('.wm-contact:has-text("Jean Dupont")')
	})

	await step('Nextcloud unified search: messages and events, the link opens them', async () => {
		const search = async (term, section) => {
			await page.goto(NC + '/apps/files/')
			// up to Nextcloud 33: a button that opens the search; Nextcloud 34: a field in the header;
			// Nextcloud 35: the field itself
			await page.click('header button[aria-label="Recherche unifiée"], .unified-search-input__button, .unified-search-input__input')
			await page.keyboard.type(term)
			await page.waitForSelector('text=' + section)
		}
		// precise: Nextcloud 35 shows the first 3 results of each source only (« Facture novembre » sent meanwhile)
		await search('facture OVH', 'Courrier Exchange')
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

	await step('Files app: « Envoyer par e-mail » opens a new message with the file attached', async () => {
		await page.goto(NC + '/apps/files/?dir=/Documents')
		const row = '[data-cy-files-list-row-name="devis-2027.pdf"]'
		await page.waitForSelector(row)
		// the « … » menu of the row (the first buttons are inline ones: sharing…)
		await page.click(row + ' [data-cy-files-list-row-actions] .action-item__menutoggle')
		const item = page.locator('[data-cy-files-list-row-action="webmail-send-by-mail"]')
		await item.waitFor()
		assert((await item.textContent()).includes('Envoyer par e-mail'))
		const [tab] = await Promise.all([page.context().waitForEvent('page'), item.click()])
		await tab.waitForSelector('.wm-compose-attachments li:has-text("/Documents/devis-2027.pdf")')
		assert.strictEqual(await tab.inputValue('input[name=subject]'), 'devis-2027.pdf')
		// the address: the file is not part of it any more (a reload does not attach it again)
		assert(!tab.url().includes('file='), tab.url())
		await tab.fill('input[name=to]', 'client@exemple.fr')
		await tab.click('.wm-compose button[type=submit]')
		await tab.waitForSelector('.wm-toast:has-text("Message envoyé")')
		await tab.close()
		// a path out of the user's files: no message
		await page.goto(NC + '/apps/webmail/?file=' + encodeURIComponent('/../etc/passwd'))
		await page.waitForSelector('.wm-message')
		assert(!(await page.$('.wm-compose')), 'no message opened')
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
		// the view chosen last is kept: the list, the one for phones
		await page.click('.wm-segmented button:has-text("Liste")')
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

	// expected: an event refused by the read-only Exchange calendar (403); deliberate 400s (bad domain/password, missing recipient) and the fake tracker pixel; the network cut on purpose (offline step)
	// errors of other Nextcloud apps (Files, Text… where the unified search is tried) are not ours
	const relevant = errors.filter((e) => !/favicon|ResizeObserver|status of 400|ERR_TUNNEL_CONNECTION_FAILED|ERR_NAME_NOT_RESOLVED|ERR_INTERNET_DISCONNECTED|facture-novembre%20\(2\)|webmail-exchange-\d+\/nouveau\.ics/.test(e)
		&& !/\/apps\/(?!webmail\/)[a-z_]+\//.test(e))
	await browser.close()
	if (relevant.length) {
		console.log('\nBrowser errors:\n' + relevant.join('\n'))
		process.exit(1)
	}
	console.log('\nAll end-to-end steps passed.')
}

main().catch((e) => { console.error('\nFAILED:', e); process.exit(1) })
