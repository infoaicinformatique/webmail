/* Webmail Exchange – delegates of a mailbox (vanilla JS, no build step) */
(function () {
	'use strict'

	const FOLDERS = [['calendar', 'Agenda'], ['inbox', 'Boîte de réception'], ['contacts', 'Contacts'], ['tasks', 'Tâches']]
	const LEVELS = [['None', 'Aucun accès'], ['Reviewer', 'Lecture'], ['Author', 'Lecture et création'], ['Editor', 'Modification']]
	const DELIVERY = [
		['DelegatesAndMe', 'à mes délégués et à moi'],
		['DelegatesAndSendInformationToMe', 'à mes délégués seulement, avec une copie pour information'],
		['DelegatesOnly', 'à mes délégués seulement'],
	]

	let core = null

	function open(c, account) {
		core = c
		const h = core.h
		const el = core.detailEl
		core.setView('detail')
		core.clear(el)
		const wrap = h('div', { class: 'wm-settings wm-delegates', dataset: { account: account.email } },
			h('div', { class: 'wm-compose-title' },
				h('button', { type: 'button', class: 'wm-back', 'aria-label': 'Retour', onclick: () => core.openAccountSettings() }, '←'),
				h('h2', {}, 'Délégués de ' + account.email)),
			h('p', { class: 'wm-muted' }, 'Un délégué peut gérer votre agenda, votre courrier ou vos contacts selon les droits donnés ci-dessous, '
				+ 'recevoir vos invitations de réunion et y répondre en votre nom. Ce sont les mêmes réglages que dans Outlook '
				+ '(Fichier → Paramètres du compte → Accès délégué).'),
			h('div', { class: 'wm-center' }, h('span', { class: 'icon-loading' })))
		el.appendChild(wrap)
		load(account, wrap)
	}

	async function load(account, wrap) {
		try {
			render(account, wrap, await core.api('/accounts/' + account.id + '/delegates'))
		} catch (e) {
			wrap.lastChild.replaceWith(core.h('div', { class: 'wm-form-error' }, e.message))
		}
	}

	function render(account, wrap, data) {
		const h = core.h
		while (wrap.children.length > 2) wrap.lastChild.remove()
		const base = '/accounts/' + account.id + '/delegates'
		const call = async (path, body, okMessage) => {
			try {
				const res = await core.api(base + path, { method: 'POST', body })
				core.toast(okMessage)
				render(account, wrap, res)
				return true
			} catch (e) {
				core.toast(e.message, true)
				return false
			}
		}

		// who receives the invitations
		const anyCopies = data.delegates.some((d) => d.receiveCopies)
		const delivery = h('select', { name: 'deliverMeetingRequests', 'aria-label': 'Envoi des invitations', disabled: !anyCopies },
			DELIVERY.map(([v, l]) => h('option', { value: v, selected: data.deliverMeetingRequests === v }, l)))
		delivery.addEventListener('change', () => call('/meeting-requests', { mode: delivery.value }, 'Réglage des invitations enregistré'))
		wrap.appendChild(h('div', { class: 'wm-account-card' },
			h('h3', {}, 'Invitations de réunion'),
			h('label', { class: 'wm-field' }, h('span', {}, 'Envoyer mes invitations'), delivery),
			h('p', { class: 'wm-muted' }, anyCopies
				? 'Concerne les invitations que vous recevez : les délégués qui « reçoivent une copie » peuvent y répondre en votre nom.'
				: 'Cochez « Reçoit une copie de mes invitations » pour au moins un délégué pour choisir cette option.')))

		wrap.appendChild(h('h3', {}, data.delegates.length ? 'Délégués actuels' : 'Aucun délégué pour l\'instant'))
		data.delegates.forEach((d) => wrap.appendChild(delegateCard(d, false, call)))

		wrap.appendChild(h('h3', {}, 'Ajouter un délégué'))
		wrap.appendChild(delegateCard({ email: '', name: '', permissions: { calendar: 'Editor', inbox: 'None', contacts: 'None', tasks: 'None' },
			receiveCopies: true, viewPrivate: false }, true, call))
	}

	function delegateCard(d, isNew, call) {
		const h = core.h
		const email = isNew ? h('input', { type: 'text', name: 'delegateEmail', required: true, placeholder: 'prenom.nom@exemple.fr' }) : null
		const selects = {}
		const rows = FOLDERS.map(([key, label]) => {
			selects[key] = h('select', { name: key, 'aria-label': label }, LEVELS.map(([v, l]) => h('option', { value: v, selected: (d.permissions[key] || 'None') === v }, l)))
			return h('label', { class: 'wm-field' }, h('span', {}, label), selects[key])
		})
		const copies = h('input', { type: 'checkbox', name: 'receiveCopies', checked: d.receiveCopies })
		const priv = h('input', { type: 'checkbox', name: 'viewPrivate', checked: d.viewPrivate })
		// receiving the invitations requires editing the calendar
		copies.addEventListener('change', () => { if (copies.checked) selects.calendar.value = 'Editor' })
		selects.calendar.addEventListener('change', () => { if (selects.calendar.value !== 'Editor') copies.checked = false })

		const values = () => ({
			email: isNew ? email.value : d.email,
			permissions: Object.fromEntries(FOLDERS.map(([k]) => [k, selects[k].value])),
			receiveCopies: copies.checked,
			viewPrivate: priv.checked,
		})
		const save = h('button', { type: 'submit', class: 'primary' }, isNew ? 'Ajouter le délégué' : 'Enregistrer')
		const form = h('form', {
			class: 'wm-account-card wm-delegate' + (isNew ? ' wm-delegate-new' : ''),
			dataset: { email: d.email || '' },
			onsubmit: async (e) => {
				e.preventDefault()
				save.disabled = true
				const v = values()
				const ok = await call(isNew ? '' : '/update', v, isNew ? 'Délégué ajouté' : 'Délégué mis à jour')
				if (!ok) save.disabled = false
			},
		},
		isNew
			? h('label', { class: 'wm-field' }, h('span', {}, 'Adresse'), core.attachAutocomplete(email))
			: h('div', { class: 'wm-delegate-name' }, h('strong', {}, d.name || d.email), d.name ? h('span', { class: 'wm-muted' }, ' ' + d.email) : null),
		h('div', { class: 'wm-delegate-grid' }, rows),
		h('label', { class: 'wm-inline' }, copies, ' Reçoit une copie de mes invitations et peut y répondre en mon nom (agenda en « Modification »)'),
		h('label', { class: 'wm-inline' }, priv, ' Peut voir mes éléments privés'),
		h('div', { class: 'wm-compose-actions' }, save,
			isNew ? null : h('button', {
				type: 'button',
				class: 'wm-danger',
				onclick: async () => {
					if (!window.confirm('Retirer ' + (d.name || d.email) + ' de vos délégués ? Ses droits sur votre agenda, courrier et contacts seront retirés.')) return
					await call('/delete', { email: d.email }, 'Délégué retiré')
				},
			}, 'Retirer')))
		return form
	}

	window.WebmailDelegates = { open }
})()
