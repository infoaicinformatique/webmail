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
