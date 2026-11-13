/* Webmail – admin settings (no build step required) */
(function () {
	'use strict'

	function init() {
		const root = document.getElementById('webmail-admin')
		if (!root) return
		const tbody = document.getElementById('webmail-servers')
		const msg = document.getElementById('webmail-admin-msg')

		function rowTemplate() {
			const tr = document.createElement('tr')
			tr.innerHTML = '<td><input type="text" class="wm-domain" placeholder="exemple.fr"></td>'
				+ '<td><input type="text" class="wm-label" placeholder="Libellé"></td>'
				+ '<td><input type="text" class="wm-url" placeholder="ex3.mail.ovh.net"></td>'
				+ '<td><button type="button" class="wm-remove icon-delete" title="Supprimer" aria-label="Supprimer"></button></td>'
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
				forget.textContent = 'Oublier'
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

		document.getElementById('webmail-save').addEventListener('click', async () => {
			const servers = Array.from(tbody.querySelectorAll('tr')).map((tr) => ({
				domain: tr.querySelector('.wm-domain').value.trim(),
				label: tr.querySelector('.wm-label').value.trim(),
				url: tr.querySelector('.wm-url').value.trim(),
			})).filter((s) => s.domain !== '' || s.url !== '')

			msg.textContent = 'Enregistrement…'
			msg.className = 'msg'
			try {
				const res = await fetch(OC.generateUrl('/apps/webmail/api/admin/servers'), {
					method: 'PUT',
					headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
					body: JSON.stringify({
						servers,
						autodiscover: {
							enabled: document.getElementById('webmail-autodiscover').checked,
							hosts: document.getElementById('webmail-autodiscover-hosts').value.split(/[,;\s]+/).filter(Boolean),
						},
					}),
				})
				const data = await res.json()
				if (!res.ok) throw new Error(data.message || 'Erreur')
				// show normalised values
				tbody.innerHTML = ''
				data.servers.forEach((s) => {
					const tr = rowTemplate()
					tr.querySelector('.wm-domain').value = s.domain
					tr.querySelector('.wm-label').value = s.label
					tr.querySelector('.wm-url').value = s.url
					tbody.appendChild(tr)
				})
				document.getElementById('webmail-autodiscover').checked = data.autodiscover.enabled
				document.getElementById('webmail-autodiscover-hosts').value = data.autodiscover.hosts.join(', ')
				showDiscovered(data.discovered)
				msg.textContent = 'Enregistré'
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
