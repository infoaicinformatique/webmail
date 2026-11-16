/* Webmail – admin settings (no build step required) */
(function () {
	'use strict'

	const _ = (s, vars) => (window.t ? window.t('webmail', s, vars, undefined, { escape: false }) : s)

	function init() {
		const root = document.getElementById('webmail-admin')
		if (!root) return
		const tbody = document.getElementById('webmail-servers')
		const msg = document.getElementById('webmail-admin-msg')

		function rowTemplate() {
			const tr = document.createElement('tr')
			const cell = (child) => {
				const td = document.createElement('td')
				td.appendChild(child)
				tr.appendChild(td)
			}
			const input = (className, placeholder) => {
				const el = document.createElement('input')
				el.type = 'text'
				el.className = className
				el.placeholder = placeholder
				cell(el)
			}
			input('wm-domain', _('exemple.fr'))
			input('wm-label', _('Libellé'))
			input('wm-url', 'ex3.mail.ovh.net')
			const remove = document.createElement('button')
			remove.type = 'button'
			remove.className = 'wm-remove icon-delete'
			remove.title = _('Supprimer')
			remove.setAttribute('aria-label', _('Supprimer'))
			cell(remove)
			return tr
		}

		document.getElementById('webmail-add').addEventListener('click', () => {
			const tr = rowTemplate()
			tbody.appendChild(tr)
			tr.querySelector('.wm-domain').focus()
		})

		tbody.addEventListener('click', (e) => {
			if (e.target.classList.contains('wm-remove')) {
				e.target.closest('tr').remove()
			}
		})

		// « État des services »: checked on demand
		// the signature of the organisation: its model, how it is used, a preview with one's own profile
		const orgSave = document.getElementById('webmail-org-signature-save')
		if (orgSave) {
			orgSave.addEventListener('click', async () => {
				const out = document.getElementById('webmail-org-signature-msg')
				orgSave.disabled = true
				try {
					const res = await fetch(OC.generateUrl('/apps/webmail/api/admin/org-signature'), {
						method: 'POST',
						headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
						body: JSON.stringify({ template: document.getElementById('webmail-org-signature').value, mode: document.getElementById('webmail-org-signature-mode').value }),
					})
					const data = await res.json()
					if (!res.ok) throw new Error(data.message || _('Erreur'))
					document.getElementById('webmail-org-signature').value = data.template
					document.getElementById('webmail-org-signature-mode').value = data.mode
					// (cleaned by the server: no script can come in)
					document.getElementById('webmail-org-signature-preview').innerHTML = data.preview
					out.textContent = _('Enregistré')
					out.className = 'msg success'
				} catch (e) {
					out.textContent = e.message
					out.className = 'msg error'
				} finally {
					orgSave.disabled = false
				}
			})
		}
		// the model of the automatic replies during the absences of Nextcloud
		const absenceSave = document.getElementById('webmail-absence-save')
		if (absenceSave) {
			absenceSave.addEventListener('click', async () => {
				const out = document.getElementById('webmail-absence-msg')
				absenceSave.disabled = true
				try {
					const res = await fetch(OC.generateUrl('/apps/webmail/api/admin/absence-template'), {
						method: 'POST',
						headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
						body: JSON.stringify({ template: document.getElementById('webmail-absence-template').value }),
					})
					const data = await res.json()
					if (!res.ok) throw new Error(data.message || _('Erreur'))
					document.getElementById('webmail-absence-template').value = data.template
					out.textContent = _('Enregistré')
					out.className = 'msg success'
				} catch (e) {
					out.textContent = e.message
					out.className = 'msg error'
				} finally {
					absenceSave.disabled = false
				}
			})
		}
		// « Mises à jour »: the versions published since the one installed, and what they bring
		const update = document.getElementById('webmail-update')
		if (update) {
			const status = update.querySelector('.wm-update-status')
			const notes = update.querySelector('.wm-update-notes')
			const checkBtn = document.getElementById('webmail-update-check')
			const notify = document.getElementById('webmail-update-notify')
			const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e }
			// the notes of a release (Markdown): headings, list items and paragraphs, as text
			const renderNotes = (md) => {
				const box = el('div', 'wm-update-release-notes')
				let ul = null
				md.split('\n').forEach((line) => {
					const t = line.trim()
					if (!t) { ul = null; return }
					const clean = (x) => x.replace(/\*\*([^*]+)\*\*/g, '$1').replace(/`([^`]+)`/g, '$1').replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
					if (/^#{1,6}\s/.test(t)) { ul = null; box.appendChild(el('strong', 'wm-update-heading', clean(t.replace(/^#+\s*/, '')))); return }
					if (/^[-*]\s/.test(t)) { if (!ul) { ul = el('ul'); box.appendChild(ul) } ul.appendChild(el('li', '', clean(t.slice(2)))); return }
					ul = null
					box.appendChild(el('p', '', clean(t)))
				})
				return box
			}
			const show = (data) => {
				notes.innerHTML = ''
				update.classList.toggle('available', !!data.available)
				if (data.available) {
					status.textContent = '🆕 ' + _('Nouvelle version disponible : {latest} (installée : {installed})', { latest: data.latest, installed: data.installed })
					data.releases.forEach((r) => {
						const box = el('details', 'wm-update-release')
						if (r.version === data.latest) box.open = true
						const sum = el('summary', '', _('Version {version}', { version: r.version }) + (r.date ? ' — ' + r.date : ''))
						box.appendChild(sum)
						if (r.notes) box.appendChild(renderNotes(r.notes))
						if (r.url) {
							const a = el('a', 'external', _('Voir sur GitHub ↗'))
							a.href = r.url
							a.target = '_blank'
							a.rel = 'noopener noreferrer'
							box.appendChild(a)
						}
						notes.appendChild(box)
					})
				} else if (data.error) {
					status.textContent = '⚠️ ' + _('Version installée : {installed}. Les nouvelles versions n\'ont pas pu être lues ({error}).', { installed: data.installed, error: data.error })
				} else {
					status.textContent = '✅ ' + _('Version installée : {installed} — à jour.', { installed: data.installed })
				}
			}
			const load = async (fresh) => {
				checkBtn.disabled = true
				try {
					const res = await fetch(OC.generateUrl('/apps/webmail/api/admin/update') + (fresh ? '?fresh=1' : ''), { headers: { requesttoken: OC.requestToken, Accept: 'application/json' } })
					const data = await res.json()
					if (!res.ok) throw new Error(data.message || ('HTTP ' + res.status))
					show(data)
				} catch (e) {
					status.textContent = '⚠️ ' + e.message
				} finally {
					checkBtn.disabled = false
				}
			}
			checkBtn.addEventListener('click', () => load(true))
			notify.addEventListener('change', async () => {
				await fetch(OC.generateUrl('/apps/webmail/api/admin/update'), {
					method: 'POST',
					headers: { requesttoken: OC.requestToken, Accept: 'application/json', 'Content-Type': 'application/json' },
					body: JSON.stringify({ notify: notify.checked }),
				})
			})
			load(false)
		}

		const healthBtn = document.getElementById('webmail-health-check')
		const healthList = document.getElementById('webmail-health')
		if (healthBtn && healthList) {
			healthBtn.addEventListener('click', async () => {
				healthBtn.disabled = true
				healthList.innerHTML = ''
				const loading = document.createElement('li')
				loading.textContent = _('Vérification…')
				healthList.appendChild(loading)
				try {
					const res = await fetch(OC.generateUrl('/apps/webmail/api/admin/health'), { headers: { requesttoken: OC.requestToken, Accept: 'application/json' } })
					const data = await res.json()
					if (!res.ok) throw new Error(data.message || ('HTTP ' + res.status))
					healthList.innerHTML = ''
					data.checks.forEach((c) => {
						const li = document.createElement('li')
						li.className = 'wm-health-' + c.state
						const icon = { ok: '✅', warning: '⚠️', error: '❌' }[c.state] || '•'
						const strong = document.createElement('strong')
						strong.textContent = c.check
						li.append(icon + ' ', strong, ' — ' + c.detail)
						healthList.appendChild(li)
					})
				} catch (e) {
					healthList.innerHTML = ''
					const li = document.createElement('li')
					li.className = 'wm-health-error'
					li.textContent = '❌ ' + e.message
					healthList.appendChild(li)
				} finally {
					healthBtn.disabled = false
				}
			})
		}

		const discovered = document.getElementById('webmail-discovered')
		const showDiscovered = (map) => {
			discovered.innerHTML = ''
			Object.entries(map || {}).forEach(([domain, url]) => {
				const li = document.createElement('li')
				li.dataset.domain = domain
				li.append(domain + ' → ')
				const code = document.createElement('code')
				code.textContent = url
				const forget = document.createElement('button')
				forget.type = 'button'
				forget.className = 'wm-forget'
				forget.title = _('Oublier : le prochain compte de ce domaine le cherchera à nouveau')
				forget.textContent = _('Oublier')
				li.append(code, ' ', forget)
				discovered.appendChild(li)
			})
		}
		discovered.addEventListener('click', async (e) => {
			if (!e.target.classList.contains('wm-forget')) return
			const res = await fetch(OC.generateUrl('/apps/webmail/api/admin/discovered/delete'), {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
				body: JSON.stringify({ domain: e.target.closest('li').dataset.domain }),
			})
			if (res.ok) showDiscovered((await res.json()).discovered)
		})

		const noVerify = document.getElementById('webmail-no-verify-tls')
		const tlsWarning = document.getElementById('webmail-tls-warning')
		const maxAttachments = document.getElementById('webmail-max-attachments')
		noVerify.addEventListener('change', () => { tlsWarning.hidden = !noVerify.checked })

		document.getElementById('webmail-save').addEventListener('click', async () => {
			const servers = Array.from(tbody.querySelectorAll('tr')).map((tr) => ({
				domain: tr.querySelector('.wm-domain').value.trim(),
				label: tr.querySelector('.wm-label').value.trim(),
				url: tr.querySelector('.wm-url').value.trim(),
			})).filter((s) => s.domain !== '' || s.url !== '')

			msg.textContent = _('Enregistrement…')
			msg.className = 'msg'
			try {
				const res = await fetch(OC.generateUrl('/apps/webmail/api/admin/servers'), {
					method: 'PUT',
					headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
					body: JSON.stringify({
						servers,
						visio: document.getElementById('webmail-visio').value.trim(),
						visioMode: document.getElementById('webmail-visio-room').checked ? 'element-room' : '',
						verifyTls: !noVerify.checked,
						maxAttachmentsMb: parseInt(maxAttachments.value, 10) || 0,
						autodiscover: {
							enabled: document.getElementById('webmail-autodiscover').checked,
							hosts: document.getElementById('webmail-autodiscover-hosts').value.split(/[,;\s]+/).filter(Boolean),
						},
					}),
				})
				const data = await res.json()
				if (!res.ok) throw new Error(data.message || _('Erreur'))
				// show normalised values
				tbody.innerHTML = ''
				data.servers.forEach((s) => {
					const tr = rowTemplate()
					tr.querySelector('.wm-domain').value = s.domain
					tr.querySelector('.wm-label').value = s.label
					tr.querySelector('.wm-url').value = s.url
					tbody.appendChild(tr)
				})
				document.getElementById('webmail-visio').value = data.visio
				document.getElementById('webmail-visio-room').checked = data.visioMode === 'element-room'
				const detected = document.getElementById('webmail-visio-detected')
				if (detected) detected.remove()
				noVerify.checked = !data.verifyTls
				tlsWarning.hidden = data.verifyTls
				maxAttachments.value = data.maxAttachmentsMb
				document.getElementById('webmail-autodiscover').checked = data.autodiscover.enabled
				document.getElementById('webmail-autodiscover-hosts').value = data.autodiscover.hosts.join(', ')
				showDiscovered(data.discovered)
				msg.textContent = _('Enregistré')
				msg.className = 'msg success'
			} catch (e) {
				msg.textContent = e.message
				msg.className = 'msg error'
			}
		})
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init)
	} else {
		init()
	}
})()
