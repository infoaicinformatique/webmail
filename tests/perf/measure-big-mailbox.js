/*
 * Speed of the webmail on a very large mailbox, against fake_ews.py started with
 *   --bulk 200000 --bulk-folders 300 --latency 150 --timing /tmp/ews-timing.tsv
 *
 *   NC_URL=http://localhost:8080 TIMING=/tmp/ews-timing.tsv node tests/perf/measure-big-mailbox.js
 *
 * For each action: the time the person waits, the Exchange requests it made and
 * the time the fake server spent on them itself (to leave out: a real Exchange
 * answers from its indexes). Needs a Nextcloud set up as for tests/e2e, without
 * a webmail account yet (one is added for user@societe-a.fr).
 */
const { chromium } = require('playwright')
const fs = require('fs')

const NC = process.env.NC_URL || 'http://localhost:8080'
const TIMING = process.env.TIMING
const RUNS = Number(process.env.RUNS || 3)

/** The Exchange requests written by the fake server since `from` (milliseconds). */
function ewsSince(from) {
	if (!TIMING || !fs.existsSync(TIMING)) return []
	return fs.readFileSync(TIMING, 'utf8').trim().split('\n').filter(Boolean).map((l) => l.split('\t'))
		.filter(([t]) => Number(t) >= from).map(([, op, ms, items]) => ({ op, ms: Number(ms), items: Number(items || 0) }))
}

async function main() {
	const browser = await chromium.launch()
	const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR', timezoneId: 'Europe/Paris' })
	const page = await context.newPage()
	await page.goto(NC + '/login')
	await page.fill('#user', process.env.NC_USER || 'admin')
	await page.fill('#password', process.env.NC_PASS || 'admin-Pass-123')
	await page.click('button[type=submit]')
	await page.waitForURL(/apps/)
	await page.goto(NC + '/apps/webmail/')
	await page.waitForSelector('.wm-add-account, .wm-message', { timeout: 120000 })
	if (await page.$('.wm-add-account')) {
		await page.fill('.wm-add-account input[type=email]', 'user@societe-a.fr')
		await page.fill('.wm-add-account input[type=password]', 'secret')
		await page.click('.wm-add-account button[type=submit]')
		await page.waitForSelector('.wm-message', { timeout: 120000 })
	}
	const account = await page.evaluate(async () => {
		const r = await fetch(OC.generateUrl('/apps/webmail/api/accounts'), { headers: { requesttoken: OC.requestToken } })
		return (await r.json()).find((a) => a.email === 'user@societe-a.fr').id
	})
	const api = (path) => page.evaluate(async (u) => {
		const r = await fetch(OC.generateUrl('/apps/webmail/api/accounts/' + u), { headers: { requesttoken: OC.requestToken, Accept: 'application/json' } })
		const text = await r.text()
		return { status: r.status, size: text.length, body: r.ok ? JSON.parse(text) : text.slice(0, 200) }
	}, account + path)
	const folders = (await api('/folders')).body.folders
	const inbox = folders.find((f) => f.role === 'inbox') || folders[0]
	const archive = folders.find((f) => /^Archives/.test(f.name) && f.total > 0)
	const firstPage = (await api('/messages?folder=' + encodeURIComponent(inbox.id) + '&offset=0&limit=50')).body
	const deep = (await api('/messages?folder=' + encodeURIComponent(inbox.id) + '&offset=60000&limit=50')).body
	const old = deep.items[10]

	const results = []
	const measure = async (name, fn) => {
		const times = []
		let ews = []
		let detail = ''
		for (let i = 0; i < RUNS; i++) {
			const from = Date.now()
			const t0 = performance.now()
			detail = await fn(i)
			times.push(performance.now() - t0)
			// the fake writes once it answered
			await new Promise((r) => setTimeout(r, 50))
			ews = ewsSince(from)
		}
		times.sort((a, b) => a - b)
		const row = {
			action: name,
			median_ms: Math.round(times[Math.floor(times.length / 2)]),
			ews_requests: ews.length,
			ews_ops: [...new Set(ews.map((e) => e.op))].join(','),
			fake_ms: Math.round(ews.reduce((s, e) => s + e.ms, 0)),
			ews_items: ews.reduce((s, e) => s + e.items, 0),
			detail: detail || '',
		}
		results.push(row)
		console.log(JSON.stringify(row))
	}
	const q = (params) => '/messages?' + new URLSearchParams(Object.assign({ folder: inbox.id, offset: 0, limit: 50 }, params))

	await measure('ouvrir la webmail (page + liste affichée)', async () => {
		await page.evaluate(() => sessionStorage.clear())
		await page.goto(NC + '/apps/webmail/')
		await page.waitForSelector('.wm-message', { timeout: 120000 })
		return (await page.$$('.wm-folder')).length + ' dossiers affichés'
	})
	await measure('liste des dossiers', async () => (await api('/folders')).body.folders.length + ' dossiers')
	await measure('boîte de réception, 1re page', async () => (await api(q({}))).body.total + ' messages')
	await measure('« Charger plus » (page 2)', async () => (await api(q({ offset: 50 }))).body.items.length + '')
	await measure('page lointaine (offset 60 000)', async () => (await api(q({ offset: 60000 }))).body.items.length + '')
	await measure('ouvrir un message récent', async () => (await api('/message?id=' + encodeURIComponent(firstPage.items[5].id))).size + ' octets')
	await measure('ouvrir un message ancien', async () => (await api('/message?id=' + encodeURIComponent(old.id))).size + ' octets')
	await measure('tri par expéditeur', async () => (await api(q({ sort: 'from', order: 'asc' }))).body.items.length + '')
	await measure('tri par taille', async () => (await api(q({ sort: 'size', order: 'desc' }))).body.items.length + '')
	await measure('vue par conversation', async () => {
		const r = await api('/conversations?' + new URLSearchParams({ folder: inbox.id, offset: 0, limit: 50 }))
		return r.status + ' ' + (r.body.items || []).length
	})
	await measure('recherche dans le dossier (« facture »)', async (i) => (await api(q({ q: 'facture' + (i ? ' ' + i : '') }))).body.total + ' résultats')
	await measure('recherche dans tous les dossiers', async (i) => {
		const r = await api(q({ q: 'devis', scope: 'all', offset: 0 }))
		return r.status + ' ' + r.body.total + ' résultats'
	})
	await measure('filtre non lus', async () => (await api(q({ unread: 1 }))).body.total + '')
	await measure('filtre pièces jointes', async () => (await api(q({ attachments: 1 }))).body.total + '')
	if (archive) await measure('dossier d\'archives, 1re page', async () => (await api('/messages?' + new URLSearchParams({ folder: archive.id, offset: 0, limit: 50 }))).body.total + ' messages')
	await measure('messages d\'un expéditeur', async () => {
		const r = await api('/sender?' + new URLSearchParams({ folderId: inbox.id, email: firstPage.items[0].from.email }))
		return r.status + ' ' + (r.body.count !== undefined ? r.body.count : r.body)
	})
	if (process.env.OUT) fs.writeFileSync(process.env.OUT, JSON.stringify(results, null, 1))
	await browser.close()
}

main().catch((e) => { console.error(e); process.exit(1) })
